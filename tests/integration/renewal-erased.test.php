<?php
/**
 * renewal-erased.test.php — an AUTONOMOUS store renewal for an ERASED owner
 * must still extend the entitlement (lifecycle §5: deletion erases the person,
 * never the service; the store keeps billing until the person cancels THERE).
 * relinkErasedOwner covers only the signed-in restore path — this proves the
 * webhook path, which has no session and no module user left to consult:
 * RenewalService acts on the ledger row + the anonymized client alone.
 *
 * ⚠ Provisions ONE real order for a DEDICATED client (never the shared buyer —
 * the deletion path anonymizes the client it runs on), then deletes everything
 * in cleanup.
 */

require __DIR__ . '/lib/common.php';

requireIapLib(
    'ApiException.php',
    'IapRepository.php',
    'Stores/Dto/PurchaseRecord.php',
    'Stores/Dto/StoreNotification.php',
    'Stores/StoreAdapterInterface.php',
    'Provisioning/AccountService.php',
    'Provisioning/AccountDeletionService.php',
    'Provisioning/ClientProvisioner.php',
    'Provisioning/OrderProvisioner.php',
    'Provisioning/DeliveryReader.php',
    'Provisioning/EntitlementService.php',
    'Provisioning/RefundService.php',
    'Provisioning/RenewalService.php',
    'Controllers/NotificationController.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Controllers\NotificationController;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

/** A scripted store: parseNotification and refresh both answer from fields set per step. */
class FakeRenewalAdapter implements StoreAdapterInterface
{
    public ?StoreNotification $nextNotification = null;
    public ?PurchaseRecord $record = null;

    public function storeId(): string
    {
        return 'googleplay';
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        if ($this->nextNotification === null) {
            throw new \RuntimeException('unauthentic');
        }
        return $this->nextNotification;
    }

    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord
    {
        return $this->record ?? throw new \RuntimeException('no record scripted');
    }

    public function verifyPurchase(array $app, array $proof): PurchaseRecord
    {
        return $this->record ?? throw new \RuntimeException('no record scripted');
    }

    public function finalize(array $app, PurchaseRecord $record): void
    {
    }

    public function listVoidedPurchaseKeys(array $app, int $sinceUnix): array
    {
        return [];
    }

    public function stopRenewals(array $app, string $purchaseKey): bool
    {
        return false;
    }
}

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}

// -- pick a provisionable product (same rule as redeem.test.php) --------------
$pid = (int) (getenv('IAP_TEST_PID') ?: 0);
if ($pid === 0) {
    $product = one($db, "SELECT id, name FROM tblproducts WHERE servertype='vpnhoodstore' ORDER BY id LIMIT 1");
    if (!$product) {
        bad('no vpnhoodstore product exists on this install');
        finish();
    }
    $pid = (int) $product['id'];
}

// -- fixtures: app + mapping + a DEDICATED user (fresh mailbox → fresh client) -
$marker = 'rtest-' . bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$repo = new IapRepository();
$package = "com.vpnhood.$marker";
$purchaseKey = "$marker-tok";

$appId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store'         => 'googleplay',
    'package_name'  => $package,
    'webhook_token' => bin2hex(random_bytes(24)),
    'status'        => 'active',
    'created_at'    => $now,
    'updated_at'    => $now,
]);
Capsule::table('mod_vpnhood_iap_products')->insert([
    'app_id'               => $appId,
    'store_product_id'     => 'vh_rtest',
    'store_base_plan_id'   => 'monthly',
    'whmcs_product_id'     => $pid,
    'billing_cycle_months' => 1,
    'enabled'              => 1,
]);
$ownerUid = IapRepository::uuidV4();
$ownerUserId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
    'provider'             => 'google',
    'provider_subject'     => "$marker-owner",
    'email'                => "$marker@vpnhood.itest",
    'email_verified_claim' => 1,
    'client_id'            => null,
    'external_uid'         => $ownerUid,
    'created_at'           => $now,
    'updated_at'           => $now,
]);
ok("fixtures created (app #$appId, mapping vh_rtest/monthly → pid $pid, user #$ownerUserId)");

$app = $repo->getApp($appId);
$adapter = new FakeRenewalAdapter();
$clientId = 0;
$orderIds = [];

try {
    // ---- 1. provision the purchase for the living owner ----------------------
    $adapter->record = new PurchaseRecord(
        store: 'googleplay',
        purchaseKey: $purchaseKey,
        storeOrderId: strtoupper($marker) . '.FIRST',
        storeProductId: 'vh_rtest',
        basePlanId: 'monthly',
        obfuscatedUid: $ownerUid,
        state: PurchaseRecord::STATE_ACTIVE,
        expiryTimeUnix: time() + 30 * 86400,
        autoRenewing: true,
        acknowledged: false,
        linkedPurchaseKey: null,
        isTest: true,
        amount: null,
        currency: null,
        raw: ['fixture' => true],
    );
    $result = (new EntitlementService($repo))
        ->redeem($app, $adapter->record, $repo->getUser($ownerUserId), $adapter);
    $result['state'] === 'provisioned'
        ? ok('purchase provisioned for the living owner')
        : bad('initial provisioning failed: ' . json_encode($result));

    $row = one($db, 'SELECT * FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', [$purchaseKey]);
    $clientId = (int) $row['client_id'];
    $serviceId = (int) $row['service_id'];
    $orderIds[] = (int) $row['whmcs_order_id'];
    ($clientId > 0 && $serviceId > 0)
        ? ok("real client #$clientId and service #$serviceId created")
        : bad('no client/service behind the purchase: ' . json_encode($row));
    $dueBefore = (string) one($db, 'SELECT nextduedate FROM tblhosting WHERE id=?', [$serviceId])['nextduedate'];

    // ---- 2. erase the owner through the real deletion path -------------------
    (new AccountDeletionService())->deleteUser($repo->getUser($ownerUserId));
    !Capsule::table('mod_vpnhood_iap_users')->where('id', $ownerUserId)->exists()
        ? ok('owner erased (module user gone, journalled)')
        : bad('deletion left the module user behind');
    $clientEmail = (string) one($db, 'SELECT email FROM tblclients WHERE id=?', [$clientId])['email'];
    str_contains($clientEmail, 'anonymized.invalid')
        ? ok('client anonymized — the person is gone, the billing anchor remains')
        : bad("client email not anonymized: $clientEmail");
    $serviceStatus = (string) one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$serviceId])['domainstatus'];
    $serviceStatus === 'Active'
        ? ok('deletion left the store-billed service running (lifecycle §5)')
        : bad("service is $serviceStatus after deletion, expected Active");

    // ---- 3. the store renews the subscription — nobody is signed in ----------
    $newExpiry = time() + 60 * 86400;
    $adapter->record = new PurchaseRecord(
        store: 'googleplay',
        purchaseKey: $purchaseKey,
        storeOrderId: strtoupper($marker) . '.RENEW-1',
        storeProductId: 'vh_rtest',
        basePlanId: 'monthly',
        obfuscatedUid: $ownerUid, // the store still reports the erased owner's uid
        state: PurchaseRecord::STATE_ACTIVE,
        expiryTimeUnix: $newExpiry,
        autoRenewing: true,
        acknowledged: true,
        linkedPurchaseKey: null,
        isTest: true,
        amount: null,
        currency: null,
        raw: ['fixture' => true],
    );
    $adapter->nextNotification = new StoreNotification(
        'googleplay', "$marker-m1", StoreNotification::RENEWED, $purchaseKey, null, $package, time(), []);
    $response = (new NotificationController($repo))->handle($app, $adapter, [], '{}', []);
    $handled = (string) ($response['body']['data']['handled'] ?? '');
    ($response['status'] === 200 && in_array($handled, ['renewed', 'resynced'], true))
        ? ok("the renewal went through without an owner ($handled)")
        : bad('renewal dispatch: ' . json_encode($response));

    // ---- 4. the entitlement is extended, and the erasure is not undone -------
    $rowAfter = one($db, 'SELECT status, expiry_time, service_id FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', [$purchaseKey]);
    ($rowAfter['status'] === 'provisioned'
        && (int) $rowAfter['service_id'] === $serviceId
        && abs(strtotime((string) $rowAfter['expiry_time']) - $newExpiry) < 5)
        ? ok('ledger row extended to the store\'s new expiry on the SAME service')
        : bad('ledger row after renewal: ' . json_encode($rowAfter));
    $serviceAfter = one($db, 'SELECT domainstatus, nextduedate FROM tblhosting WHERE id=?', [$serviceId]);
    ($serviceAfter['domainstatus'] === 'Active' && (string) $serviceAfter['nextduedate'] > $dueBefore)
        ? ok("service still Active, due date advanced ($dueBefore → {$serviceAfter['nextduedate']})")
        : bad('service after renewal: ' . json_encode([$serviceAfter, 'before' => $dueBefore]));
    !Capsule::table('mod_vpnhood_iap_users')->where('external_uid', $ownerUid)->exists()
        ? ok('no module account was resurrected — the renewal needed none')
        : bad('the renewal re-created a module user for the erased owner');

    // ---- 5. the replayed store event is a no-op ------------------------------
    $adapter->nextNotification = new StoreNotification(
        'googleplay', "$marker-m2", StoreNotification::RENEWED, $purchaseKey, null, $package, time(), []);
    $response = (new NotificationController($repo))->handle($app, $adapter, [], '{}', []);
    (($response['body']['data']['handled'] ?? '') === 'skipped-already-paid'
        || ($response['body']['data']['handled'] ?? '') === 'resynced')
        ? ok('replayed renewal event stayed idempotent (' . $response['body']['data']['handled'] . ')')
        : bad('renewal replay: ' . json_encode($response));
} finally {
    // == cleanup — the dedicated client and everything under it ================
    foreach ($orderIds as $orderId) {
        $sid = (int) (one($db, 'SELECT id FROM tblhosting WHERE orderid=?', [$orderId])['id'] ?? 0);
        if ($sid > 0) {
            localAPI('ModuleTerminate', ['serviceid' => $sid]);
        }
        localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    Capsule::table('mod_vpnhood_iap_events')->where('message_id', 'like', "$marker-%")->delete();
    Capsule::table('mod_vpnhood_iap_purchases')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_users')->where('provider_subject', 'like', "$marker%")->delete();
    Capsule::table('mod_vpnhood_iap_deletions')->where('user_id', $ownerUserId)->delete();
    Capsule::table('mod_vpnhood_iap_products')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    if ($clientId > 0) {
        Capsule::table('mod_vpnhood_iap_deletions')->where('client_id', $clientId)->delete();
        Capsule::table('mod_vpnhood_iap_frozen_invoices')->where('client_id', $clientId)->delete();
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    ok('fixtures removed (order + dedicated client deleted)');
}

finish();
