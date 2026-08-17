<?php
/**
 * claims.test.php — the server-chosen code, end to end on the dev WHMCS
 * (lifecycle §8: the account always has exactly one code, the server chooses
 * it, and the app is told a code — never a list):
 *
 *   - provisioning marks (Phase 2): a real vpnhoodstore order leaves
 *     accessCodeHash + isDefaultKey on the service;
 *   - import by code, the CLIENT-AREA act (the app never imports): possession of
 *     the code finds the service (hash lookup on the hub), records a pointer, and
 *     the imported code becomes the account's chosen one (a deliberate act —
 *     last-one-wins). Importing consumes nothing;
 *   - acceptance, not refusal (§8): a paid store purchase on an account already
 *     served by its chosen code is PROVISIONED and acknowledged — refusing after
 *     the money moved only ever worked on the one store that auto-refunds;
 *   - a second purchase never disturbs a working code (§8 rule 1);
 *   - promotion: when the chosen code dies, the next usable one takes over on
 *     the next read — no cron;
 *   - both code endpoints answering 404 (the app tells the backend nothing about
 *     codes), and the account snapshot carrying a single ranked accessCode
 *     (never a list);
 *   - refund marks (the 24-month fingerprint) round-trip.
 *
 * ⚠ Places TWO real orders on a vpnhoodstore product for a throwaway client —
 * real access tokens are created on the access manager, then terminated and
 * the orders deleted in cleanup.
 */

require __DIR__ . '/lib/common.php';

requireIapLib(
    'ApiException.php',
    'IapRepository.php',
    'Auth/SessionService.php',
    'Stores/Dto/PurchaseRecord.php',
    'Stores/Dto/StoreNotification.php',
    'Stores/StoreAdapterInterface.php',
    'Provisioning/AccountService.php',
    'Provisioning/ClientProvisioner.php',
    'Provisioning/OrderProvisioner.php',
    'Provisioning/DeliveryReader.php',
    'Provisioning/AccountKeyService.php',
    'Provisioning/EntitlementService.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountKeyService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\DeliveryReader;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
if (!tableExists($db, 'mod_vpnhood_iap_claims')) {
    bad('mod_vpnhood_iap_claims missing — WHMCS has not run the module upgrade yet');
    finish();
}
if (!columnExists($db, 'mod_vpnhood_iap_claims', 'client_id')) {
    bad('server-chosen-code columns missing — WHMCS has not run the 1.0.14 upgrade yet');
    finish();
}
if (columnExists($db, 'mod_vpnhood_iap_users', 'default_cleared_at')) {
    bad('users.default_cleared_at still present — WHMCS has not run the 1.0.16 upgrade yet');
    finish();
}

const API_URL = 'https://whmcs-dev.vpnhood.com/modules/addons/vpnhoodiap/api.php/v1';

/** A scripted store: finalize is what proves the purchase was acknowledged, not left hanging. */
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
        throw new \RuntimeException('not used');
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

$marker = 'claimtest-' . bin2hex(random_bytes(4));
$repo = new IapRepository();
$clientId = 0;
$orderIds = [];
$serviceIds = [];
$appId = 0;
$userIds = [];

function claimsHttp(string $method, string $path, string $token, ?array $body): array
{
    $curl = curl_init(API_URL . $path);
    $options = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($curl, $options);
    $responseBody = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$status, json_decode($responseBody, true)];
}

/** One real paid-and-provisioned website order; returns [orderId, serviceId]. */
function placeOrder(int $clientId, int $productId): array
{
    $order = localAPI('AddOrder', [
        'clientid' => $clientId, 'pid' => $productId, 'billingcycle' => 'monthly',
        'paymentmethod' => 'banktransfer', 'noemail' => true, 'noinvoiceemail' => true,
    ]);
    if (($order['result'] ?? '') !== 'success') {
        bad('AddOrder failed: ' . json_encode($order));
        finish();
    }
    $orderId = (int) $order['orderid'];
    localAPI('ApplyCredit', ['invoiceid' => (int) ($order['invoiceid'] ?? 0), 'amount' => 'full', 'noemail' => true]);
    $accepted = localAPI('AcceptOrder', ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => false]);
    if (($accepted['result'] ?? '') !== 'success') {
        bad('AcceptOrder failed: ' . json_encode($accepted));
        finish();
    }
    return [$orderId, (int) explode(',', (string) ($order['productids'] ?? ''))[0]];
}

try {
    // == fixtures: throwaway client + one real website order ==================
    $added = localAPI('AddClient', [
        'firstname' => 'Claim', 'lastname' => 'Test', 'email' => "$marker@vpnhood.test",
        'password2' => bin2hex(random_bytes(12)), 'country' => 'US',
        'skipvalidation' => true, 'noemail' => true,
    ]);
    if (($added['result'] ?? '') !== 'success') {
        bad('AddClient failed: ' . json_encode($added));
        finish();
    }
    $clientId = (int) $added['clientid'];
    localAPI('AddCredit', ['clientid' => $clientId, 'description' => 'claims test', 'amount' => '20.00']);

    $productId = (int) (one($db, "SELECT p.id FROM tblproducts p
        LEFT JOIN tblproducts_slugs s ON s.product_id = p.id AND s.active = 1
        WHERE p.slug = ? OR s.slug = ? LIMIT 1",
        ['reseller-one-month-premium-code-subscription', 'reseller-one-month-premium-code-subscription'])['id'] ?? 0);
    if ($productId === 0) {
        bad('recurring vpnhoodstore fixture product missing — run the hub repo bootstrap first');
        finish();
    }

    [$orderIds[0], $serviceIds[0]] = placeOrder($clientId, $productId);
    $serviceId = $serviceIds[0];
    ok("real website order placed: client #$clientId order #$orderIds[0] service #$serviceId");

    // == provisioning marks (Phase 2 in vpnhoodstore) =========================
    IapRepository::serviceProperty($serviceId, 'accessTokenId') !== null
        ? ok('service carries accessTokenId')
        : bad('no accessTokenId — provisioning failed');
    $storedHash = IapRepository::serviceProperty($serviceId, 'accessCodeHash');
    $storedHash !== null
        ? ok('service carries accessCodeHash (codes still never persisted)')
        : bad('no accessCodeHash on the provisioned service');
    IapRepository::serviceProperty($serviceId, 'isDefaultKey') === 'yes'
        ? ok('the first code bought became the client\'s chosen one at purchase time')
        : bad('isDefaultKey not set on a first purchase');

    // == the code round-trip ==================================================
    $reader = new DeliveryReader();
    $code = $reader->readAccessCode($serviceId);
    ($code !== null && $code !== '')
        ? ok('DeliveryReader read the live code (' . strlen($code) . ' chars)')
        : bad('no code readable for the service');
    hash('sha256', trim((string) $code)) === $storedHash
        ? ok('stored hash matches the live code (import lookups will find it)')
        : bad('accessCodeHash does not match the live code');
    $state = $reader->readCodeState($serviceId);
    in_array($state['state'], ['active', 'not-started'], true)
        ? ok("readCodeState answers ({$state['state']})")
        : bad('unexpected code state: ' . json_encode($state));

    $keyService = new AccountKeyService($repo);
    $keyService->findServiceIdByCode((string) $code) === $serviceId
        ? ok('findServiceIdByCode resolves the entered code to the service')
        : bad('code lookup failed');
    $keyService->findServiceIdByCode('no-such-code-' . $marker) === null
        ? ok('an unknown code resolves to nothing')
        : bad('an unknown code matched something');

    // == the buyer's own account is served by its code ========================
    $userIds['owner'] = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-owner",
        'email' => "$marker@vpnhood.test", 'email_verified_claim' => 1,
        'client_id' => $clientId,
        'external_uid' => sprintf('%s-0000-4000-8000-%s', substr(md5("$marker-o"), 0, 8), substr(md5("$marker-o"), 0, 12)),
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $owner = (array) Capsule::table('mod_vpnhood_iap_users')->where('id', $userIds['owner'])->first();
    $ownerCode = $keyService->accessCodeInfoForUser($owner);
    ($ownerCode !== null && $ownerCode['accessCode'] === $code)
        ? ok('the linked account is handed its ONE website code — no list ever crosses the wire')
        : bad('owner accessCodeInfo wrong: ' . json_encode($ownerCode));
    $ownerKeys = $keyService->webKeysForUser($owner);
    (count($ownerKeys) === 1 && $ownerKeys[0]['accessCode'] === $code && $ownerKeys[0]['isDefault'] === true)
        ? ok('the client-area listing (portal-side only) sees the code, marked as serving')
        : bad('owner client-area listing wrong: ' . json_encode($ownerKeys));

    // == the mismatched-email person imports by code ==========================
    $userIds['claimer'] = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-claimer",
        'email' => "other-$marker@vpnhood.test", 'email_verified_claim' => 1,
        'client_id' => null,
        'external_uid' => sprintf('%s-0000-4000-8000-%s', substr(md5("$marker-c"), 0, 8), substr(md5("$marker-c"), 0, 12)),
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $claimer = (array) Capsule::table('mod_vpnhood_iap_users')->where('id', $userIds['claimer'])->first();

    $import = $keyService->importCode($claimer, (string) $code);
    ($import['created'] === true && $import['accessCode'] === $code)
        ? ok('importing records the pointer, and the imported code becomes the chosen one')
        : bad('import wrong: ' . json_encode($import));
    $again = $keyService->importCode($claimer, (string) $code);
    $again['created'] === false
        ? ok('re-importing is idempotent — nothing is consumed, nothing doubles')
        : bad('re-import wrong: ' . json_encode($again));
    $claimerCode = $keyService->accessCodeInfoForUser($claimer);
    ($claimerCode !== null && $claimerCode['accessCode'] === $code)
        ? ok('the importer is now served by the same code — billing stays where it was')
        : bad('claimer accessCodeInfo wrong: ' . json_encode($claimerCode));
    $ownerStill = $keyService->accessCodeInfoForUser($owner);
    ($ownerStill !== null && $ownerStill['accessCode'] === $code)
        ? ok('…and the owner keeps being served by it too: an import takes nothing from anyone')
        : bad('import disturbed the owner: ' . json_encode($ownerStill));

    // == a store purchase on an already-served account is ACCEPTED ============
    // a real app + catalog mapping, so what we assert is the §8 acceptance rule
    // itself — not the catalog gate parking an unmapped SKU
    $appId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
        'store'         => 'googleplay',
        'package_name'  => "test.claims.$marker",
        'webhook_token' => bin2hex(random_bytes(16)),
        'status'        => 'active',
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('mod_vpnhood_iap_products')->insert([
        'app_id'               => $appId,
        'store_product_id'     => 'vh_premium',
        'store_base_plan_id'   => 'monthly',
        'whmcs_product_id'     => $productId,
        'billing_cycle_months' => 1,
        'enabled'              => 1,
    ]);
    $app = (array) Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->first();
    $record = new PurchaseRecord(
        store: 'googleplay',
        purchaseKey: "$marker-gate",
        storeOrderId: "$marker-GATE.1",
        storeProductId: 'vh_premium',
        basePlanId: 'monthly',
        obfuscatedUid: (string) $claimer['external_uid'],
        state: PurchaseRecord::STATE_ACTIVE,
        expiryTimeUnix: time() + 30 * 86400,
        autoRenewing: true,
        acknowledged: false,
        linkedPurchaseKey: null,
        isTest: true,
        amount: null,
        currency: null,
        raw: ['subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE']
    );
    // The account IS already served by its chosen code here — and that must no
    // longer refuse anything. Refusing after payment only ever worked where the
    // store auto-refunds an unacknowledged purchase (Play); Apple has no such
    // mechanism, so a refusal there is the buyer's money kept for nothing.
    // Prevention happens in the app before the store's payment sheet (§8).
    $adapter = new FakeStoreAdapter($record);
    try {
        $result = (new EntitlementService($repo))->redeem($app, $record, $claimer, $adapter);
        (($result['accessCode'] ?? null) !== null)
            ? ok('a paid purchase is provisioned even though a code already served the account')
            : bad('purchase accepted but delivered no access code: ' . json_encode($result));
    } catch (ApiException $e) {
        bad('a paid store purchase was REFUSED: ' . $e->getHttpStatus() . ' / ' . $e->getErrorCode());
    }
    $adapter->finalizeCalls === 1
        ? ok('…and it is acknowledged to the store — never money held for something undelivered')
        : bad("finalize ran {$adapter->finalizeCalls} times on an accepted purchase");
    (string) (one($db, 'SELECT status FROM mod_vpnhood_iap_purchases WHERE purchase_key = ?', ["$marker-gate"])['status'] ?? '')
        === 'provisioned'
        ? ok('the ledger records it as provisioned, so the account visibly holds both')
        : bad('accepted purchase not recorded as provisioned');

    // == the app-facing surface over HTTP =====================================
    // The app tells the backend NOTHING about codes (§8): both code endpoints are
    // gone. Importing a code and naming which one serves the account are portal
    // acts, done on the client-area codes page — where the person can see the
    // outcome, unlike an app call that 404s silently for any code this portal did
    // not sell (promo, admin-issued, partner, MANAGER).
    $sessions = new SessionService();
    $claimerToken = $sessions->issue($userIds['claimer'])['token'];
    [$status, $body] = claimsHttp('POST', '/account/claims', $claimerToken, ['accessCode' => $code]);
    $status === 404
        ? ok('POST /account/claims is gone — importing is a portal act, not an app one')
        : bad("claims endpoint still answers $status " . json_encode($body));

    [$status, $body] = claimsHttp('POST', '/account/code-removed', $claimerToken, ['accessCode' => $code]);
    $status === 404
        ? ok('POST /account/code-removed is gone — together with the park it drove')
        : bad("code-removed still answers $status " . json_encode($body));

    // The claimer now holds BOTH channels — the gate purchase above and the claimed
    // website code — and the SERVER ranks them: the subscription's own code wins (§8).
    [$status, $body] = claimsHttp('GET', '/account', $claimerToken, null);
    $wireCode = $body['accessCodeInfo'] ?? null;
    ($status === 200 && is_array($wireCode) && ($wireCode['accessCode'] ?? '') === ($result['accessCode'] ?? '-'))
        ? ok('GET /account carries the ONE ranked code — the subscription\'s own, never a list')
        : bad("account snapshot wrong: $status " . json_encode($body));
    (!array_key_exists('webKeys', (array) $body) && !array_key_exists('items', (array) $body))
        ? ok('no list ever crosses to a device — the app is told a code, not a list')
        : bad('a list leaked back onto the wire');
    // the code side of the wire carries nothing of the portal's own vocabulary: no
    // delivery mode, no provenance, no default flag
    (array_keys($wireCode) === ['accessCode', 'expirationTime'])
        ? ok('the accessCode on the wire is exactly {code, expirationTime}')
        : bad('unexpected accessCodeInfo fields: ' . json_encode(array_keys($wireCode)));
    (($body['subscription']['storeId'] ?? null) === 'googleplay')
        ? ok('the snapshot names the billing store — the app compares it with its build, never displays it')
        : bad('subscription wrong: ' . json_encode($body['subscription'] ?? null));

    // == a second purchase never disturbs a working code ======================
    // §8 rule 1: a purchase claims the slot only when the account has no usable
    // code — which is what stops a code bought for someone else from moving the
    // buyer off their own
    [$orderIds[1], $serviceIds[1]] = placeOrder($clientId, $productId);
    $code2 = $reader->readAccessCode($serviceIds[1]);
    ($code2 !== null && $code2 !== '' && $code2 !== $code)
        ? ok("second order provisioned with its own code (service #{$serviceIds[1]})")
        : bad('second order has no distinct code');
    $ownerAfterPurchase = $keyService->accessCodeInfoForUser($owner);
    ($ownerAfterPurchase !== null && $ownerAfterPurchase['accessCode'] === $code)
        ? ok('buying a second code leaves the buyer on their own working one (§8 rule 1)')
        : bad('the second purchase disturbed a working code: ' . json_encode($ownerAfterPurchase));

    // promotion: kill the chosen code's service; the next usable one must take
    // over on the very next read — recompute-on-read, no cron anywhere
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => 'Cancelled']);
    $promoted = $keyService->accessCodeInfoForUser($owner);
    ($promoted !== null && $promoted['accessCode'] === $code2)
        ? ok('the chosen code died and the next usable one took over on the next read (promotion)')
        : bad('promotion failed: ' . json_encode($promoted));
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => 'Active']);
    $stable = $keyService->accessCodeInfoForUser($owner);
    ($stable !== null && $stable['accessCode'] === $code2)
        ? ok('the promoted choice is persisted — a revived service does not steal the slot back')
        : bad('promotion was not sticky: ' . json_encode($stable));

    // -- reseller stock is a merchant concept and never reaches the app -------
    // flip the CHOSEN service to stock for the length of this check: the mark
    // does not shield it, so the account must fall back to the other code
    \WHMCS\Service\Service::find($serviceIds[1])->serviceProperties->save(['bulkDelivery' => 'yes']);
    $ownerAsBulk = $keyService->accessCodeInfoForUser($owner);
    ($ownerAsBulk === null || $ownerAsBulk['accessCode'] !== $code2)
        ? ok('stock is never served as the account\'s code, even when it was the chosen one')
        : bad('bulk leaked into accessCodeInfo: ' . json_encode($ownerAsBulk));
    $keyService->bulkOrderCount($owner) === 1
        ? ok('…but the portal still counts it, for the farewell message and the web deletion page')
        : bad('bulkOrderCount did not see the batch');
    \WHMCS\Service\Service::find($serviceIds[1])->serviceProperties->save(['bulkDelivery' => '']);

    // == refund marks round-trip ==============================================
    $repo->addRefundMark("refund-$marker@vpnhood.test");
    $repo->hasRefundMark("Refund-$marker@vpnhood.test  ")
        ? ok('refund mark found back (normalized, one-way)')
        : bad('refund mark not found');
    !$repo->hasRefundMark("never-$marker@vpnhood.test")
        ? ok('no false positives on refund marks')
        : bad('refund mark false positive');
} finally {
    // == cleanup ==============================================================
    foreach ($userIds as $userId) {
        Capsule::table('mod_vpnhood_iap_sessions')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_claims')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->delete();
    }
    if ($clientId > 0) {
        Capsule::table('mod_vpnhood_iap_claims')->where('client_id', $clientId)->delete();
    }
    Capsule::table('mod_vpnhood_iap_purchases')->where('purchase_key', 'like', "$marker%")->delete();
    Capsule::table('mod_vpnhood_iap_refund_marks')
        ->where('email_hash', hash('sha256', "refund-$marker@vpnhood.test"))->delete();
    if (!empty($appId)) {
        Capsule::table('mod_vpnhood_iap_products')->where('app_id', $appId)->delete();
        Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    }
    foreach ($serviceIds as $sid) {
        if ($sid > 0) {
            localAPI('ModuleTerminate', ['serviceid' => $sid]);
        }
    }
    foreach ($orderIds as $oid) {
        if ($oid > 0) {
            localAPI('CancelOrder', ['orderid' => $oid, 'cancelsub' => false]);
            localAPI('DeleteOrder', ['orderid' => $oid]);
        }
    }
    if ($clientId > 0) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    ok('fixtures cleaned up (tokens terminated, orders + client deleted)');
}

finish();
