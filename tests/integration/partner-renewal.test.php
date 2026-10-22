<?php
/**
 * partner-renewal.test.php — a reseller's in-app subscription, end to end: IAP orders a connector
 * (vpnhoodpartner) product, the connector buys the key from the Hub with the reseller's credit, and
 * every store renewal must reach the Hub as exactly one paid cycle, with the key never ending before
 * the reseller's term. The Hub's own rules are tested in its repo (renew-term.test.php); this is
 * the chain. A reseller's key follows the Hub's cycle, not the store's clock to the day, so
 * term-sync.test.php (hub installs) does not apply here.
 *
 * Needs the connector and the Hub on this install (the dev box): it reads the Hub side directly.
 * Product: env IAP_TEST_PID, default the hub bootstrap's connector subscription; none → skipped.
 * Moves both due dates with UpdateClientProduct to stand in for the days a real term takes.
 *
 * ⚠ Provisions ONE real key for a DEDICATED client and spends reseller (test) credit; the key is
 * terminated and the client deleted in cleanup.
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
    'Provisioning/TermSync.php',
    'Provisioning/EntitlementService.php',
    'Provisioning/RefundService.php',
    'Provisioning/RenewalService.php',
    'Controllers/NotificationController.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\Controllers\NotificationController;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\DeliveryReader;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

/** A scripted store: parseNotification and refresh both answer from fields set per step. */
class FakePartnerAdapter implements StoreAdapterInterface
{
    public ?StoreNotification $nextNotification = null;
    public ?PurchaseRecord $record = null;

    public function storeId(): string
    {
        return 'googleplay';
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        return $this->nextNotification ?? throw new \RuntimeException('unauthentic');
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
// the hub repo's bootstrap makes this connector product, mapped for its test partner
const CONNECTOR_SLUG = 'partner-one-month-premium-code-subscription';
$pid = (int) (getenv('IAP_TEST_PID') ?: 0);
if ($pid === 0) {
    $pid = (int) (one($db, "SELECT p.id FROM tblproducts p LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
        WHERE p.slug=? OR s.slug=? LIMIT 1", [CONNECTOR_SLUG, CONNECTOR_SLUG])['id'] ?? 0);
}
if ($pid === 0 || one($db, "SELECT 1 x FROM tblproducts WHERE id=? AND servertype='vpnhoodpartner'", [$pid]) === null) {
    ok('skipped: no recurring connector (vpnhoodpartner) product on this install');
    finish();
}

// -- fixtures: app + mapping + a DEDICATED user (fresh mailbox → fresh client) -----
$marker = 'ptest-' . bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$repo = new IapRepository();
$package = "com.vpnhood.$marker";
$purchaseKey = "$marker-tok";
$appId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store' => 'googleplay', 'package_name' => $package, 'webhook_token' => bin2hex(random_bytes(24)),
    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
]);
Capsule::table('mod_vpnhood_iap_products')->insert([
    'app_id' => $appId, 'store_product_id' => 'vh_ptest', 'store_base_plan_id' => 'monthly',
    'whmcs_product_id' => $pid, 'billing_cycle_months' => 1, 'enabled' => 1,
]);
$ownerUid = IapRepository::uuidV4();
$ownerUserId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
    'provider' => 'google', 'provider_subject' => "$marker-owner", 'email' => "$marker@vpnhood.itest",
    'email_verified_claim' => 1, 'client_id' => null, 'external_uid' => $ownerUid,
    'created_at' => $now, 'updated_at' => $now,
]);
ok("fixtures created (app #$appId, mapping vh_ptest/monthly → connector pid $pid, user #$ownerUserId)");

$app = $repo->getApp($appId);
$adapter = new FakePartnerAdapter();
$controller = new NotificationController($repo);
$reader = new DeliveryReader();
$startedAt = date('Y-m-d H:i:s');
$clientId = 0;
$serviceId = 0;
$orderIds = [];
$hubServiceId = 0;
$resellerId = 0;
$messageNo = 0;

$record = fn (string $orderId, int $expiry, string $state = PurchaseRecord::STATE_ACTIVE): PurchaseRecord => new PurchaseRecord(
    store: 'googleplay', purchaseKey: $purchaseKey, storeOrderId: strtoupper($marker) . ".$orderId",
    storeProductId: 'vh_ptest', basePlanId: 'monthly', obfuscatedUid: $ownerUid, state: $state,
    expiryTimeUnix: $expiry, autoRenewing: true, acknowledged: true, linkedPurchaseKey: null,
    isTest: true, amount: null, currency: null, raw: ['fixture' => true],
);
$notify = function (string $type) use (&$messageNo, $marker, $purchaseKey, $package, $adapter, $controller, $app): string {
    $messageNo++;
    $adapter->nextNotification = new StoreNotification('googleplay', "$marker-m$messageNo", $type, $purchaseKey, null, $package, time(), []);
    $response = $controller->handle($app, $adapter, [], '{}', []);
    return (string) ($response['body']['data']['handled'] ?? json_encode($response));
};
// a month from now at this hour: a store bills at the hour of the original purchase
$monthFromNow = fn (): int => gmmktime((int) gmdate('G'), (int) gmdate('i'), 0, (int) gmdate('n') + 1, (int) gmdate('j'), (int) gmdate('Y'));
$service = fn (int $id): array => one($db, 'SELECT domainstatus, nextduedate, nextinvoicedate, userid, orderid FROM tblhosting WHERE id=?', [$id]) ?? [];
$credit = function () use ($db, &$resellerId): float {
    return round((float) one($db, 'SELECT credit FROM tblclients WHERE id=?', [$resellerId])['credit'], 2);
};
// the Hub's paid cycles of the key: its order invoice and every renewal invoice, Paid
$hubPaidCycles = function () use ($db, &$hubServiceId): int {
    return (int) one($db, "SELECT COUNT(DISTINCT inv.id) c FROM tblinvoiceitems i JOIN tblinvoices inv ON inv.id=i.invoiceid
        WHERE i.type='Hosting' AND i.relid=? AND inv.status='Paid'", [$hubServiceId])['c'];
};
$codeDay = function () use ($reader, &$serviceId): string {
    return substr((string) $reader->readCodeState($serviceId)['expiresAt'], 0, 10);
};
$setDue = function (int $id, string $date): void {
    localAPI('UpdateClientProduct', ['serviceid' => $id, 'nextduedate' => $date]);
};
// the key never ends before the reseller's term, and its code ends with it
$covered = function () use ($service, &$serviceId, &$hubServiceId, $codeDay): ?string {
    $local = $service($serviceId)['nextduedate'] ?? '';
    $hub = $service($hubServiceId)['nextduedate'] ?? '';
    if ($hub < $local) {
        return "the Hub's term $hub ends before the reseller's $local";
    }
    return $codeDay() === $hub ? null : "the code ends {$codeDay()}, the Hub's term $hub";
};
$failures = function () use ($db, $startedAt): array {
    return array_merge(
        array_column($db->query("SELECT action, LEFT(response, 200) r FROM tblmodulelog WHERE module='vpnhoodpartner' AND date >= " . $db->quote($startedAt))->fetchAll(PDO::FETCH_ASSOC), 'r'),
        array_column($db->query("SELECT LEFT(response, 200) r FROM mod_vpnhood_iap_log WHERE action='alert' AND created_at >= " . $db->quote($startedAt))->fetchAll(PDO::FETCH_ASSOC), 'r')
    );
};

try {
    // ---- 1. the purchase: one key bought from the Hub, its term covering the reseller's ----
    $expiry = $monthFromNow();
    $adapter->record = $record('FIRST', $expiry);
    $result = (new EntitlementService($repo))->redeem($app, $adapter->record, $repo->getUser($ownerUserId), $adapter);
    ($result['state'] === 'provisioned' && ($result['accessCode'] ?? '') !== '')
        ? ok('purchase provisioned through the connector, access code delivered')
        : bad('provisioning failed: ' . json_encode($result));
    $serviceId = (int) (one($db, 'SELECT service_id FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', [$purchaseKey])['service_id'] ?? 0);
    $clientId = (int) ($service($serviceId)['userid'] ?? 0);
    $orderIds[] = (int) ($service($serviceId)['orderid'] ?? 0);
    $upstreamOrderId = (int) \WHMCS\Service\Service::find($serviceId)?->serviceProperties->get('upstreamOrderId');
    $hub = one($db, 'SELECT id, userid FROM tblhosting WHERE orderid=?', [$upstreamOrderId]);
    if ($hub === null) {
        bad("the Hub's order #$upstreamOrderId is not on this install — this test needs the Hub here (the dev box)");
        finish();
    }
    $hubServiceId = (int) $hub['id'];
    $resellerId = (int) $hub['userid'];
    ok("key bought from the Hub: order #$upstreamOrderId, service #$hubServiceId, reseller #$resellerId");
    $hubPaidCycles() === 1 ? ok('the reseller paid for exactly one cycle') : bad('paid cycles after the purchase: ' . $hubPaidCycles());
    ($why = $covered()) === null ? ok('the key covers the reseller\'s term, and the code ends with it') : bad("after the purchase: $why");

    // ---- 2. the store renews on the term's last day: one more cycle --------------------
    $tomorrow = date('Y-m-d', time() + 86400);
    $setDue($serviceId, $tomorrow);
    $setDue($hubServiceId, $tomorrow);
    $creditBefore = $credit();
    $adapter->record = $record('RENEW-1', $monthFromNow());
    $handled = $notify(StoreNotification::RENEWED);
    in_array($handled, ['resynced', 'renewed'], true) ? ok("renewal handled ($handled)") : bad("renewal: $handled");
    $hubPaidCycles() === 2 ? ok('…the reseller paid exactly one more cycle') : bad('paid cycles after the renewal: ' . $hubPaidCycles());
    ($credit() < $creditBefore) ? ok('…from the reseller\'s credit (' . $creditBefore . ' → ' . $credit() . ')') : bad('reseller credit unchanged');
    ($why = $covered()) === null ? ok('…the key covers the new term') : bad("after the renewal: $why");

    // ---- 3. the replayed event pays nothing twice --------------------------------
    $handled = $notify(StoreNotification::RENEWED);
    ($handled === 'skipped-already-paid' && $hubPaidCycles() === 2)
        ? ok('replayed renewal: nothing paid twice')
        : bad("renewal replay: $handled, paid cycles " . $hubPaidCycles());

    // ---- 4. a payment hold that outlasts the term, then the store recovers --------------
    $handled = $notify(StoreNotification::ON_HOLD);
    ($handled === 'on_hold' && ($service($hubServiceId)['domainstatus'] ?? '') === 'Suspended')
        ? ok('ON_HOLD suspended the key at the Hub too')
        : bad('on_hold: ' . json_encode([$handled, $service($serviceId), $service($hubServiceId)]));
    $lapsed = date('Y-m-d', time() - 3 * 86400);
    $setDue($serviceId, $lapsed);
    $setDue($hubServiceId, $lapsed);
    $adapter->record = $record('RECOVER-1', $monthFromNow());
    $handled = $notify(StoreNotification::RECOVERED);
    str_starts_with($handled, 'recovered-') ? ok("RECOVERED handled ($handled)") : bad("recovered: $handled");
    $hubPaidCycles() === 3 ? ok('…the reseller paid exactly one cycle for it') : bad('paid cycles after the recovery: ' . $hubPaidCycles());
    (($service($hubServiceId)['domainstatus'] ?? '') === 'Active')
        ? ok('…the key is active again at the Hub')
        : bad('Hub service after the recovery: ' . json_encode($service($hubServiceId)));
    ($service($hubServiceId)['nextduedate'] ?? '') >= date('Y-m-d', strtotime('+1 month'))
        ? ok('…its term restarted from today (' . $service($hubServiceId)['nextduedate'] . '), not from the lapsed date')
        : bad('Hub term after the recovery: ' . ($service($hubServiceId)['nextduedate'] ?? ''));
    ($why = $covered()) === null ? ok('…the key covers the reseller\'s term') : bad("after the recovery: $why");

    $left = $failures();
    $left === [] ? ok('no connector error and no IAP alert along the way') : bad('failures logged: ' . json_encode($left));
} catch (\Throwable $e) {
    bad('stopped by ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    // == cleanup — the key at the Hub (through the connector), the dedicated client =====
    foreach ($orderIds as $orderId) {
        $sid = (int) (one($db, 'SELECT id FROM tblhosting WHERE orderid=?', [$orderId])['id'] ?? 0);
        if ($sid > 0) {
            localAPI('ModuleTerminate', ['serviceid' => $sid]);
        }
        localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    if ($hubServiceId > 0 && in_array($service($hubServiceId)['domainstatus'] ?? '', ['Active', 'Suspended'], true)) {
        bad("the Hub's service #$hubServiceId is still " . $service($hubServiceId)['domainstatus'] . ' after the cleanup');
    }
    Capsule::table('mod_vpnhood_iap_events')->where('message_id', 'like', "$marker-%")->delete();
    Capsule::table('mod_vpnhood_iap_purchases')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_users')->where('provider_subject', 'like', "$marker%")->delete();
    Capsule::table('mod_vpnhood_iap_products')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    if ($clientId > 0) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    ok('fixtures removed (key terminated, dedicated client deleted)');
}

finish();
