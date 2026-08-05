<?php
/**
 * webhook.test.php — NotificationController dispatch inside the real dev
 * WHMCS with a fake adapter (auth already covered by unit tests): inbox
 * dedup on (store, message_id), tenant/package guard, TEST handling,
 * CANCELED lifecycle transition, and the never-5xx guarantee when
 * processing throws.
 */

require __DIR__ . '/lib/common.php';

requireIapLib(
    'ApiException.php',
    'IapRepository.php',
    'Stores/Dto/PurchaseRecord.php',
    'Stores/Dto/StoreNotification.php',
    'Stores/StoreAdapterInterface.php',
    'Provisioning/AccountService.php',
    'Provisioning/ClientProvisioner.php',
    'Provisioning/OrderProvisioner.php',
    'Provisioning/DeliveryReader.php',
    'Provisioning/EntitlementService.php',
    'Provisioning/RenewalService.php',
    'Controllers/NotificationController.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\Controllers\NotificationController;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

/** Fake adapter: parseNotification returns a scripted notification (auth assumed done). */
class FakeNotifyAdapter implements StoreAdapterInterface
{
    public ?StoreNotification $next = null;
    public bool $throwOnRefresh = false;

    public function storeId(): string
    {
        return 'googleplay';
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        if ($this->next === null) {
            throw new \RuntimeException('unauthentic');
        }
        return $this->next;
    }

    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord
    {
        throw new \RuntimeException('refresh deliberately failing');
    }

    public function verifyPurchase(array $app, array $proof): PurchaseRecord
    {
        throw new \RuntimeException('not used');
    }

    public function finalize(array $app, PurchaseRecord $record): void
    {
    }

    public function listVoidedPurchaseKeys(array $app, int $sinceUnix): array
    {
        return [];
    }
}

function notif(string $marker, string $type, string $messageId, ?string $purchaseKey, ?string $packageName): StoreNotification
{
    return new StoreNotification('googleplay', $messageId, $type, $purchaseKey, null, $packageName, time(), ['marker' => $marker]);
}

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}

$marker = 'wtest-' . bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$repo = new IapRepository();
$package = "com.vpnhood.$marker";

$appId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store'         => 'googleplay',
    'package_name'  => $package,
    'webhook_token' => bin2hex(random_bytes(24)),
    'status'        => 'active',
    'created_at'    => $now,
    'updated_at'    => $now,
]);
// a provisioned purchase row to drive lifecycle transitions against (no real service)
Capsule::table('mod_vpnhood_iap_purchases')->insert([
    'app_id'       => $appId,
    'store'        => 'googleplay',
    'purchase_key' => "$marker-tok",
    'status'       => 'provisioned',
    'auto_renewing' => 1,
    'created_at'   => $now,
    'updated_at'   => $now,
]);
$app = $repo->getApp($appId);
$controller = new NotificationController($repo);
$adapter = new FakeNotifyAdapter();

try {
    // ---- unauthentic push → 401
    $adapter->next = null;
    $result = $controller->handle($app, $adapter, [], '{}', []);
    $result['status'] === 401 ? ok('unauthentic push answered 401') : bad('unauthentic push: ' . json_encode($result));

    // ---- TEST notification → 200 processed
    $adapter->next = notif($marker, StoreNotification::TEST, "$marker-m1", null, $package);
    $result = $controller->handle($app, $adapter, [], '{}', []);
    ($result['status'] === 200 && $result['body']['data']['handled'] === 'test')
        ? ok('test notification handled with 200')
        : bad('test notification: ' . json_encode($result));

    // ---- duplicate delivery → 200 duplicate, single inbox row
    $adapter->next = notif($marker, StoreNotification::TEST, "$marker-m1", null, $package);
    $result = $controller->handle($app, $adapter, [], '{}', []);
    ($result['status'] === 200 && $result['body']['data']['handled'] === 'duplicate')
        ? ok('duplicate message deduplicated by (store, message_id)')
        : bad('duplicate handling: ' . json_encode($result));
    $count = (int) one($db, "SELECT COUNT(*) c FROM mod_vpnhood_iap_events WHERE message_id=?", ["$marker-m1"])['c'];
    $count === 1 ? ok('exactly one inbox row for the duplicate') : bad("$count inbox rows");

    // ---- package mismatch → skipped, 200
    $adapter->next = notif($marker, StoreNotification::PURCHASED, "$marker-m2", "$marker-tok", 'com.attacker.other');
    $result = $controller->handle($app, $adapter, [], '{}', []);
    ($result['status'] === 200 && $result['body']['data']['handled'] === 'skipped-package-mismatch')
        ? ok('foreign package name skipped, never processed')
        : bad('package mismatch: ' . json_encode($result));

    // ---- CANCELED → auto_renewing off, status canceled, no revoke
    $adapter->next = notif($marker, StoreNotification::CANCELED, "$marker-m3", "$marker-tok", $package);
    $result = $controller->handle($app, $adapter, [], '{}', []);
    $row = one($db, 'SELECT status, auto_renewing FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', ["$marker-tok"]);
    ($result['status'] === 200 && $row['status'] === 'canceled' && (int) $row['auto_renewing'] === 0)
        ? ok('CANCELED flips auto-renew off and keeps the entitlement row')
        : bad('canceled dispatch: ' . json_encode([$result, $row]));

    // ---- processing failure (refresh throws) → recorded failed, still 200
    $adapter->next = notif($marker, StoreNotification::PURCHASED, "$marker-m4", "$marker-tok2", $package);
    $result = $controller->handle($app, $adapter, [], '{}', []);
    $event = one($db, 'SELECT status, error FROM mod_vpnhood_iap_events WHERE message_id=?', ["$marker-m4"]);
    ($result['status'] === 200 && $event['status'] === 'failed' && str_contains((string) $event['error'], 'deliberately'))
        ? ok('processing failure recorded as failed event, answered 200 (never 5xx)')
        : bad('failure handling: ' . json_encode([$result, $event]));
} finally {
    Capsule::table('mod_vpnhood_iap_events')->where('message_id', 'like', "$marker-%")->delete();
    Capsule::table('mod_vpnhood_iap_purchases')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    ok('fixtures cleaned up');
}

finish();
