<?php
/**
 * redeem.test.php — the whole redemption pipeline inside the real dev WHMCS,
 * with a FAKE store adapter (no Google): binding guard, catalog gate,
 * unverified-email parking, happy-path provisioning through a real
 * vpnhoodstore product (AddOrder → AddInvoicePayment → AcceptOrder →
 * DeliveryReader), idempotent replay, finalize-after-success.
 *
 * ⚠ Places ONE real order on a vpnhoodstore product for the test buyer —
 * a real access token is created on the access manager, then terminated and
 * the order deleted in cleanup (same footprint as the hub repo's
 * purchase-order test). Product selection: env IAP_TEST_PID overrides;
 * default = the first vpnhoodstore product.
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
    'Provisioning/EntitlementService.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

/** A scripted store: returns the given record; counts finalize calls. */
class FakeStoreAdapter implements StoreAdapterInterface
{
    public int $finalizeCalls = 0;

    public function __construct(private PurchaseRecord $record)
    {
    }

    public function storeId(): string
    {
        return 'googleplay';
    }

    public function verifyPurchase(array $app, array $proof): PurchaseRecord
    {
        return $this->record;
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        throw new \RuntimeException('not used in this test');
    }

    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord
    {
        return $this->record;
    }

    public function finalize(array $app, PurchaseRecord $record): void
    {
        $this->finalizeCalls++;
    }

    public function listVoidedPurchaseKeys(array $app, int $sinceUnix): array
    {
        return [];
    }
}

function makeRecord(array $overrides = []): PurchaseRecord
{
    static $defaults = null;
    $defaults ??= [
        'store'             => 'googleplay',
        'purchaseKey'       => 'itest-tok-' . bin2hex(random_bytes(6)),
        'storeOrderId'      => 'ITEST.' . strtoupper(bin2hex(random_bytes(5))),
        'storeProductId'    => 'vh_itest',
        'basePlanId'        => 'monthly',
        'obfuscatedUid'     => null, // filled per test
        'state'             => PurchaseRecord::STATE_ACTIVE,
        'expiryTimeUnix'    => time() + 30 * 86400,
        'autoRenewing'      => true,
        'acknowledged'      => false,
        'linkedPurchaseKey' => null,
        'isTest'            => true,
        'amount'            => null,
        'currency'          => null,
        'raw'               => ['fixture' => true],
    ];
    $merged = array_merge($defaults, $overrides);
    return new PurchaseRecord(...$merged);
}

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixture missing: ' . BUYER_EMAIL);
    finish();
}

// -- pick a provisionable product -------------------------------------------
$pid = (int) (getenv('IAP_TEST_PID') ?: 0);
if ($pid === 0) {
    $product = one($db, "SELECT id, name FROM tblproducts WHERE servertype='vpnhoodstore' ORDER BY id LIMIT 1");
    if (!$product) {
        bad('no vpnhoodstore product exists on this install');
        finish();
    }
    $pid = (int) $product['id'];
    ok("using vpnhoodstore product #$pid ({$product['name']}) — override with IAP_TEST_PID");
}

// -- module-table fixtures ---------------------------------------------------
$marker = 'itest-' . bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$repo = new IapRepository();

$appId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store'         => 'googleplay',
    'package_name'  => "com.vpnhood.$marker",
    'webhook_token' => bin2hex(random_bytes(24)),
    'status'        => 'active',
    'created_at'    => $now,
    'updated_at'    => $now,
]);
Capsule::table('mod_vpnhood_iap_products')->insert([
    'app_id'               => $appId,
    'store_product_id'     => 'vh_itest',
    'store_base_plan_id'   => 'monthly',
    'whmcs_product_id'     => $pid,
    'billing_cycle_months' => 1,
    'enabled'              => 1,
]);
$linkedUid = IapRepository::uuidV4();
$linkedUserId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
    'provider'             => 'google',
    'provider_subject'     => "$marker-linked",
    'email'                => BUYER_EMAIL,
    'email_verified_claim' => 1,
    'client_id'            => (int) $buyer['id'], // pre-linked: happy path
    'external_uid'         => $linkedUid,
    'created_at'           => $now,
    'updated_at'           => $now,
]);
ok("fixtures created (app #$appId, mapping vh_itest/monthly → pid $pid, user #$linkedUserId)");

$app = $repo->getApp($appId);
$service = new EntitlementService($repo);
$createdOrderIds = [];

try {
    // ---- 1. binding guard: someone else's purchase token → 403, nothing placed
    $foreign = makeRecord(['obfuscatedUid' => IapRepository::uuidV4(), 'purchaseKey' => "itest-foreign-$marker"]);
    try {
        $service->redeem($app, $foreign, $repo->getUser($linkedUserId), new FakeStoreAdapter($foreign));
        bad('cross-user purchase was accepted');
    } catch (ApiException $e) {
        $e->getHttpStatus() === 403
            ? ok('cross-user purchase rejected with 403')
            : bad('cross-user purchase rejected with wrong status ' . $e->getHttpStatus());
    }

    // ---- 2. unmapped SKU parks with 422, no order
    $unmapped = makeRecord(['obfuscatedUid' => $linkedUid, 'storeProductId' => 'vh_not_mapped', 'purchaseKey' => "itest-unmapped-$marker"]);
    try {
        $service->redeem($app, $unmapped, $repo->getUser($linkedUserId), new FakeStoreAdapter($unmapped));
        bad('unmapped SKU was provisioned');
    } catch (ApiException $e) {
        $e->getHttpStatus() === 422
            ? ok('unmapped SKU rejected with 422')
            : bad('unmapped SKU rejected with wrong status ' . $e->getHttpStatus());
    }
    $parked = one($db, "SELECT status, last_error FROM mod_vpnhood_iap_purchases WHERE purchase_key=?", ["itest-unmapped-$marker"]);
    ($parked && $parked['status'] === 'pending' && str_contains((string) $parked['last_error'], 'mapping'))
        ? ok('unmapped purchase parked with a loud error')
        : bad('unmapped purchase not parked correctly: ' . json_encode($parked));

    // ---- 3. unverified existing email parks (verification is disabled on dev → fail-closed)
    // The address IS the account, so this cannot be a second user row for the same
    // email any more: it is this user with its client link detached, which is exactly
    // the state a first purchase starts from when that address already exists in WHMCS.
    Capsule::table('mod_vpnhood_iap_users')->where('id', $linkedUserId)->update(['client_id' => null]);
    $unverifiedUser = $repo->getUser($linkedUserId);
    $parkRecord = makeRecord(['obfuscatedUid' => $unverifiedUser['external_uid'], 'purchaseKey' => "itest-park-$marker"]);
    $parkResult = $service->redeem($app, $parkRecord, $unverifiedUser, new FakeStoreAdapter($parkRecord));
    $parkResult['state'] === 'awaiting_email_verification'
        ? ok('existing-but-unverified email parks the purchase')
        : bad('unexpected park result: ' . json_encode($parkResult));
    // re-link for the happy path below
    Capsule::table('mod_vpnhood_iap_users')->where('id', $linkedUserId)->update(['client_id' => (int) $buyer['id']]);

    // ---- 4. happy path: pre-linked user, mapped SKU → real provisioning
    $happy = makeRecord(['obfuscatedUid' => $linkedUid]);
    $adapter = new FakeStoreAdapter($happy);
    $result = $service->redeem($app, $happy, $repo->getUser($linkedUserId), $adapter);

    $result['state'] === 'provisioned' ? ok('redeem returned provisioned') : bad('redeem state: ' . json_encode($result));
    (is_string($result['accessCode']) && $result['accessCode'] !== '')
        ? ok('access code delivered synchronously (' . substr($result['accessCode'], 0, 12) . '…)')
        : bad('no access code returned: ' . json_encode($result));
    assertRowChecks($db, $happy, $buyer, $createdOrderIds);
    $adapter->finalizeCalls === 1
        ? ok('store finalize (acknowledge) called exactly once, after provisioning')
        : bad("finalize called {$adapter->finalizeCalls} times");

    // ---- 5. idempotent replay: same token again → same service, ONE order
    $replay = $service->redeem($app, $happy, $repo->getUser($linkedUserId), $adapter);
    $replay['state'] === 'provisioned' && $replay['accessCode'] === $result['accessCode']
        ? ok('replay returns the same entitlement')
        : bad('replay diverged: ' . json_encode($replay));
    $orderCount = (int) one($db, "SELECT COUNT(*) c FROM mod_vpnhood_iap_purchases WHERE purchase_key=?", [$happy->purchaseKey])['c'];
    $orderCount === 1 ? ok('exactly one purchase row') : bad("$orderCount purchase rows");
    $adapter->finalizeCalls === 1
        ? ok('replay does not re-acknowledge')
        : bad("finalize called {$adapter->finalizeCalls} times after replay");
} finally {
    // -- cleanup -------------------------------------------------------------
    foreach ($createdOrderIds as $orderId) {
        $serviceId = (int) (one($db, 'SELECT id FROM tblhosting WHERE orderid=?', [$orderId])['id'] ?? 0);
        if ($serviceId > 0) {
            localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
        }
        localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    Capsule::table('mod_vpnhood_iap_purchases')->where('purchase_key', 'like', "itest-%$marker%")->delete();
    Capsule::table('mod_vpnhood_iap_purchases')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_users')->where('provider_subject', 'like', "$marker%")->delete();
    Capsule::table('mod_vpnhood_iap_products')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    ok('fixtures cleaned up (order terminated + deleted)');
}

finish();

/** WHMCS-side assertions for the happy path; collects the order id for cleanup. */
function assertRowChecks(PDO $db, PurchaseRecord $happy, array $buyer, array &$createdOrderIds): void
{
    $purchaseRow = one($db, 'SELECT * FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', [$happy->purchaseKey]);
    if (!$purchaseRow) {
        bad('no purchase row recorded');
        return;
    }
    $purchaseRow['status'] === 'provisioned' ? ok('purchase row is provisioned') : bad('purchase row status: ' . $purchaseRow['status']);

    $orderId = (int) $purchaseRow['whmcs_order_id'];
    $createdOrderIds[] = $orderId;
    $serviceId = (int) $purchaseRow['service_id'];

    $invoice = one(
        $db,
        "SELECT i.id, i.status, a.transid FROM tblorders o
         JOIN tblinvoices i ON i.id = o.invoiceid
         LEFT JOIN tblaccounts a ON a.invoiceid = i.id
         WHERE o.id = ?",
        [$orderId]
    );
    ($invoice && $invoice['status'] === 'Paid')
        ? ok("order #$orderId invoice #{$invoice['id']} is Paid")
        : bad('order invoice not Paid: ' . json_encode($invoice));
    ($invoice && $invoice['transid'] === $happy->storeOrderId)
        ? ok('payment transid is the store order id (' . $happy->storeOrderId . ')')
        : bad('wrong transid: ' . json_encode($invoice['transid'] ?? null));

    $serviceRow = one($db, 'SELECT userid, domainstatus FROM tblhosting WHERE id=?', [$serviceId]);
    ($serviceRow && (int) $serviceRow['userid'] === (int) $buyer['id'])
        ? ok("service #$serviceId belongs to the buyer (status {$serviceRow['domainstatus']})")
        : bad('service row wrong: ' . json_encode($serviceRow));
}
