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
    'Provisioning/AccountDeletionService.php',
    'Provisioning/ClientProvisioner.php',
    'Provisioning/OrderProvisioner.php',
    'Provisioning/DeliveryReader.php',
    'Provisioning/EntitlementService.php',
    'Provisioning/RenewalService.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\OrderProvisioner;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\RenewalService;
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
$erasedUserIds = [];

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

    // ---- 3. unverified existing email provisions anyway, and gates the client area
    // The address IS the account, so this cannot be a second user row for the same
    // email any more: it is this user with its client link detached, which is exactly
    // the state a first purchase starts from when that address already exists in WHMCS.
    // The purchase must NOT wait on WHMCS-side verification — the store already proved
    // the buyer. What waits is the portal, via requires_email_verification.
    Capsule::table('mod_vpnhood_iap_users')->where('id', $linkedUserId)->update(['client_id' => null]);
    // the buyer fixture's WHMCS user is verified (that is scenario B); force the
    // unverified state this section is about, and put it back afterwards
    $buyerVerifiedAt = Capsule::table('tblusers')->where('email', BUYER_EMAIL)->value('email_verified_at');
    Capsule::table('tblusers')->where('email', BUYER_EMAIL)->update(['email_verified_at' => null]);
    $unverifiedUser = $repo->getUser($linkedUserId);
    $gateRecord = makeRecord(['obfuscatedUid' => $unverifiedUser['external_uid'], 'purchaseKey' => "itest-gate-$marker"]);
    $gateResult = $service->redeem($app, $gateRecord, $unverifiedUser, new FakeStoreAdapter($gateRecord));
    $gateResult['state'] === 'provisioned'
        ? ok('existing-but-unverified email still provisions')
        : bad('unexpected result for unverified existing email: ' . json_encode($gateResult));
    (is_string($gateResult['accessCode']) && $gateResult['accessCode'] !== '')
        ? ok('access code delivered despite the unconfirmed address')
        : bad('no access code for unverified existing email: ' . json_encode($gateResult));
    ((int) Capsule::table('mod_vpnhood_iap_users')->where('id', $linkedUserId)
        ->value('requires_email_verification') === 1)
        ? ok('client area gated until the address is confirmed')
        : bad('requires_email_verification was not set');
    $gateOrderId = (int) Capsule::table('mod_vpnhood_iap_purchases')
        ->where('purchase_key', "itest-gate-$marker")->value('whmcs_order_id');
    if ($gateOrderId > 0) {
        $createdOrderIds[] = $gateOrderId;
    }

    // ---- 3b. one active store subscription per account: a SECOND live key is refused
    // before provisioning, so it is never acknowledged and the store refunds it.
    $secondRecord = makeRecord(['obfuscatedUid' => $unverifiedUser['external_uid'], 'purchaseKey' => "itest-second-$marker"]);
    try {
        $service->redeem($app, $secondRecord, $repo->getUser($linkedUserId), new FakeStoreAdapter($secondRecord));
        bad('a second concurrent subscription was provisioned');
    } catch (ApiException $e) {
        $e->getHttpStatus() === 409
            ? ok('second concurrent subscription refused with 409')
            : bad('second subscription rejected with wrong status ' . $e->getHttpStatus());
    }
    $refused = one($db, "SELECT status FROM mod_vpnhood_iap_purchases WHERE purchase_key=?", ["itest-second-$marker"]);
    ($refused && $refused['status'] === 'failed')
        ? ok('refused purchase recorded as failed, left unacknowledged')
        : bad('refused purchase not recorded correctly: ' . json_encode($refused));

    // an upgrade/resubscribe REPLACES the live key rather than adding to it — allowed
    $upgrade = makeRecord([
        'obfuscatedUid'     => $unverifiedUser['external_uid'],
        'purchaseKey'       => "itest-upgrade-$marker",
        'linkedPurchaseKey' => "itest-gate-$marker",
    ]);
    $upgradeResult = $service->redeem($app, $upgrade, $repo->getUser($linkedUserId), new FakeStoreAdapter($upgrade));
    $upgradeResult['state'] === 'provisioned'
        ? ok('upgrade/resubscribe supersedes the live key instead of being blocked')
        : bad('upgrade was blocked: ' . json_encode($upgradeResult));
    $upgradeOrderId = (int) Capsule::table('mod_vpnhood_iap_purchases')
        ->where('purchase_key', "itest-upgrade-$marker")->value('whmcs_order_id');
    if ($upgradeOrderId > 0) {
        $createdOrderIds[] = $upgradeOrderId;
    }

    // retire both live keys so the happy path below is this account's only subscription
    Capsule::table('mod_vpnhood_iap_purchases')
        ->whereIn('purchase_key', ["itest-gate-$marker", "itest-upgrade-$marker"])
        ->update(['status' => 'canceled']);
    // re-link and restore the fixture's verified state for the happy path below
    Capsule::table('tblusers')->where('email', BUYER_EMAIL)->update(['email_verified_at' => $buyerVerifiedAt]);
    Capsule::table('mod_vpnhood_iap_users')->where('id', $linkedUserId)
        ->update(['client_id' => (int) $buyer['id'], 'requires_email_verification' => 0]);

    // ---- 4. happy path: pre-linked user, mapped SKU → real provisioning
    // carries the store's real charge — must land on the row + the invoice text
    $happy = makeRecord(['obfuscatedUid' => $linkedUid, 'amount' => '46.99', 'currency' => 'USD']);
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

    // ---- 6. captured store charge: on the row and in the invoice text --------
    $purchaseRow = one($db, 'SELECT * FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', [$happy->purchaseKey]);
    ($purchaseRow['store_amount'] !== null && (float) $purchaseRow['store_amount'] === 46.99 && $purchaseRow['store_currency'] === 'USD')
        ? ok('real store charge captured on the purchase row (46.99 USD)')
        : bad('store charge not captured: ' . json_encode([$purchaseRow['store_amount'], $purchaseRow['store_currency']]));

    $firstServiceId = (int) $purchaseRow['service_id'];
    $invoiceItem = one(
        $db,
        "SELECT it.description FROM tblorders o JOIN tblinvoiceitems it ON it.invoiceid = o.invoiceid WHERE o.id=? LIMIT 1",
        [(int) $purchaseRow['whmcs_order_id']]
    );
    (str_contains((string) $invoiceItem['description'], 'Billed via Google Play')
        && str_contains((string) $invoiceItem['description'], '46.99 USD'))
        ? ok('invoice line explains the store billing and the real charge')
        : bad('invoice line not annotated: ' . json_encode($invoiceItem));

    // currency matched (USD client) → the PAID invoice and its transaction now
    // carry the store's value instead of the book price
    $moneyRow = one(
        $db,
        "SELECT i.status, i.total, a.amountin FROM tblorders o
         JOIN tblinvoices i ON i.id = o.invoiceid
         LEFT JOIN tblaccounts a ON a.invoiceid = i.id
         WHERE o.id=?",
        [(int) $purchaseRow['whmcs_order_id']]
    );
    ($moneyRow['status'] === 'Paid' && (float) $moneyRow['total'] === 46.99 && (float) $moneyRow['amountin'] === 46.99)
        ? ok('matched currency: invoice + transaction rewritten to the real 46.99, still Paid')
        : bad('store-value rewrite (matched): ' . json_encode($moneyRow) . ' | order=' . (int) $purchaseRow['whmcs_order_id']
            . ' payments=' . json_encode($db->query(
                'SELECT a.id, a.transid, a.amountin FROM tblorders o
                 JOIN tblinvoices i ON i.id = o.invoiceid
                 JOIN tblaccounts a ON a.invoiceid = i.id
                 WHERE o.id = ' . (int) $purchaseRow['whmcs_order_id'])->fetchAll(PDO::FETCH_ASSOC)));

    // ---- 7. terminate leaves an unpaid renewal invoice → cleanup cancels it --
    $draft = localAPI('CreateInvoice', [
        'userid' => (int) $buyer['id'], 'status' => 'Unpaid', 'sendinvoice' => '0',
        'paymentmethod' => 'vpnhoodiappay',
        'itemdescription1' => "renewal fixture $marker", 'itemamount1' => '7.90', 'itemtaxed1' => '0',
    ]);
    $renewalInvoiceId = (int) ($draft['invoiceid'] ?? 0);
    // shape the line the way GenInvoices does, so the cleanup query matches it
    Capsule::table('tblinvoiceitems')->where('invoiceid', $renewalInvoiceId)
        ->update(['type' => 'Hosting', 'relid' => $firstServiceId]);

    localAPI('ModuleTerminate', ['serviceid' => $firstServiceId]);
    (new OrderProvisioner($repo))->cancelUnpaidRenewalInvoices($firstServiceId);
    $renewalInvoiceStatus = (string) one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$renewalInvoiceId])['status'];
    $renewalInvoiceStatus === 'Cancelled'
        ? ok('terminate cleanup cancelled the leftover unpaid renewal invoice')
        : bad("leftover renewal invoice is $renewalInvoiceStatus, expected Cancelled");

    // ---- 8. late renewal on the terminated service → fresh provisioning ------
    // charged in TRY: the currency-mismatch case — books must show 0.00, flag
    // that the money lives at the store, and stay Paid
    $creditBefore = (float) one($db, 'SELECT credit FROM tblclients WHERE id=?', [(int) $buyer['id']])['credit'];
    $renewal = makeRecord([
        'obfuscatedUid' => $linkedUid,
        'storeOrderId'  => 'ITEST.RENEW-' . strtoupper(bin2hex(random_bytes(4))),
        'acknowledged'  => true, // the original purchase was acknowledged long ago
        'amount'        => '259.99',
        'currency'      => 'TRY',
    ]);
    $renewResult = (new RenewalService($repo))->renew($app, $renewal->purchaseKey, new FakeStoreAdapter($renewal));
    $renewResult === 'reprovisioned'
        ? ok('late renewal on a terminated service provisions anew')
        : bad("late renewal returned '$renewResult', expected reprovisioned");

    $rowAfter = one($db, 'SELECT * FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', [$renewal->purchaseKey]);
    $newServiceId = (int) $rowAfter['service_id'];
    $createdOrderIds[] = (int) $rowAfter['whmcs_order_id'];
    ($rowAfter['status'] === 'provisioned' && $newServiceId > 0 && $newServiceId !== $firstServiceId)
        ? ok("purchase re-linked to a fresh service (#$firstServiceId → #$newServiceId)")
        : bad('purchase row after late renewal: ' . json_encode([$rowAfter['status'], $rowAfter['service_id']]));

    $newService = one($db, 'SELECT domainstatus, userid FROM tblhosting WHERE id=?', [$newServiceId]);
    ($newService && $newService['domainstatus'] === 'Active' && (int) $newService['userid'] === (int) $buyer['id'])
        ? ok('fresh service is Active and belongs to the buyer')
        : bad('fresh service wrong: ' . json_encode($newService));

    $renewTx = one($db, 'SELECT transid, amountin, invoiceid FROM tblaccounts WHERE transid=?', [$renewal->storeOrderId]);
    $renewTx !== null
        ? ok('fresh order paid with the renewal\'s own store order id')
        : bad('no payment recorded for the re-provisioned order');

    $mismatchInvoice = one($db, 'SELECT status, total FROM tblinvoices WHERE id=?', [(int) ($renewTx['invoiceid'] ?? 0)]);
    ((float) ($renewTx['amountin'] ?? -1) === 0.00
        && $mismatchInvoice && $mismatchInvoice['status'] === 'Paid' && (float) $mismatchInvoice['total'] === 0.00)
        ? ok('mismatched currency (TRY): invoice + transaction zeroed, still Paid')
        : bad('store-value rewrite (mismatch): ' . json_encode([$renewTx, $mismatchInvoice]));

    $creditAfter = (float) one($db, 'SELECT credit FROM tblclients WHERE id=?', [(int) $buyer['id']])['credit'];
    $creditAfter === $creditBefore
        ? ok('client credit balance untouched by the rewrites')
        : bad("client credit changed: $creditBefore -> $creditAfter");

    // ---- 9. forget me, then Restore Purchases -------------------------------
    // The record's uid names an ERASED account, so the binding guard would 403 —
    // unless the erased-owner carve-out re-links the ledger row to the new
    // account and the idempotent replay re-delivers the SAME service and code.
    // Never a new order: forget-me must be useless as a code mint.
    $erasedUid = IapRepository::uuidV4();
    $erasedUserId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-erased",
        'email' => "erased-$marker@vpnhood.itest", 'email_verified_claim' => 1,
        'client_id' => null, 'external_uid' => $erasedUid,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $erasedUserIds[] = $erasedUserId;
    // the live renewal purchase from section 8 becomes the erased user's
    Capsule::table('mod_vpnhood_iap_purchases')
        ->where('purchase_key', $renewal->purchaseKey)->update(['user_id' => $erasedUserId]);
    // the REAL deletion path (module rows + journal), not a hand-crafted state
    (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService())
        ->deleteUser($repo->getUser($erasedUserId));
    Capsule::table('mod_vpnhood_iap_users')->where('id', $erasedUserId)->exists()
        ? bad('erased fixture user still exists')
        : ok('owner erased through the real forget-me path (journalled)');

    $restoredUserId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-restored",
        'email' => "restored-$marker@vpnhood.itest", 'email_verified_claim' => 1,
        'client_id' => null, 'external_uid' => IapRepository::uuidV4(),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $restoreRecord = makeRecord([
        'obfuscatedUid' => $erasedUid, // the store still reports the OLD uid
        'purchaseKey'   => $renewal->purchaseKey,
        'storeOrderId'  => $renewal->storeOrderId,
        'acknowledged'  => true,
    ]);
    $restoreAdapter = new FakeStoreAdapter($restoreRecord);
    $restored = $service->redeem($app, $restoreRecord, $repo->getUser($restoredUserId), $restoreAdapter);
    ($restored['state'] === 'provisioned' && is_string($restored['accessCode']) && $restored['accessCode'] !== '')
        ? ok('restore after deletion re-delivers the entitlement')
        : bad('restore after deletion failed: ' . json_encode($restored));
    $relinkedRow = one($db, 'SELECT user_id, whmcs_order_id, service_id FROM mod_vpnhood_iap_purchases WHERE purchase_key=?',
        [$renewal->purchaseKey]);
    ((int) $relinkedRow['user_id'] === $restoredUserId)
        ? ok('ledger row handed to the new account')
        : bad('row not re-linked: ' . json_encode($relinkedRow));
    ((int) $relinkedRow['whmcs_order_id'] === (int) $rowAfter['whmcs_order_id']
        && (int) $relinkedRow['service_id'] === $newServiceId)
        ? ok('SAME order and service re-delivered — no new code was minted')
        : bad('restore minted new provisioning: ' . json_encode([$relinkedRow, $rowAfter['whmcs_order_id'], $newServiceId]));
    $restoreAdapter->finalizeCalls === 0
        ? ok('replay path: no re-acknowledge on restore')
        : bad("restore called finalize {$restoreAdapter->finalizeCalls} times");

    // repeat restore (new device again) rides the row-already-mine branch
    $again = $service->redeem($app, $restoreRecord, $repo->getUser($restoredUserId), $restoreAdapter);
    ($again['state'] === 'provisioned' && $again['accessCode'] === $restored['accessCode'])
        ? ok('repeat restore returns the same entitlement again')
        : bad('repeat restore diverged: ' . json_encode($again));

    // abuse fence: an account that already holds a live subscription may NOT
    // take over an erased owner's purchase (re-deliver only, never stack)
    $abuserUserId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-abuser",
        'email' => "abuser-$marker@vpnhood.itest", 'email_verified_claim' => 1,
        'client_id' => null, 'external_uid' => IapRepository::uuidV4(),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Capsule::table('mod_vpnhood_iap_purchases')->insert([
        'app_id' => $appId, 'store' => 'googleplay', 'purchase_key' => "itest-abuserlive-$marker",
        'user_id' => $abuserUserId, 'status' => 'provisioned',
        'expiry_time' => date('Y-m-d H:i:s', time() + 86400),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    // make the row takeable again: pretend its owner was erased once more
    Capsule::table('mod_vpnhood_iap_purchases')
        ->where('purchase_key', $renewal->purchaseKey)->update(['user_id' => $erasedUserId]);
    try {
        $service->redeem($app, $restoreRecord, $repo->getUser($abuserUserId), $restoreAdapter);
        bad('an account with a live subscription took over an erased purchase');
    } catch (ApiException $e) {
        $e->getHttpStatus() === 403
            ? ok('take-over refused (403) for an account that already holds a live subscription')
            : bad('take-over refused with wrong status ' . $e->getHttpStatus());
    }
    ((int) one($db, 'SELECT user_id FROM mod_vpnhood_iap_purchases WHERE purchase_key=?',
        [$renewal->purchaseKey])['user_id'] === $erasedUserId)
        ? ok('refused take-over left the ledger row untouched')
        : bad('refused take-over still moved the row');
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
    if ($erasedUserIds !== []) {
        Capsule::table('mod_vpnhood_iap_deletions')->whereIn('user_id', $erasedUserIds)->delete();
    }
    Capsule::table('mod_vpnhood_iap_products')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    // the hand-made renewal-invoice fixture (deleting an order does not touch it)
    foreach (Capsule::table('tblinvoiceitems')->where('description', 'like', "renewal fixture $marker%")->pluck('invoiceid')->all() as $fixtureInvoiceId) {
        Capsule::table('tblinvoiceitems')->where('invoiceid', (int) $fixtureInvoiceId)->delete();
        Capsule::table('tblinvoices')->where('id', (int) $fixtureInvoiceId)->delete();
    }
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
