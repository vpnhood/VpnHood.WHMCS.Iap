<?php
/**
 * claims.test.php — the account→key pointer layer, end to end on the dev WHMCS:
 *
 *   - provisioning marks (Phase 2): a real vpnhoodstore order leaves
 *     accessCodeHash + isDefaultKey on the service;
 *   - claim by code: possession of the code finds the service (hash lookup on
 *     the hub), records a pointer, and the first claim becomes the default;
 *   - the F2 purchase gate: an account served by its active default key is
 *     refused a store purchase BEFORE provisioning (finalize never called),
 *     and deliberately clearing the default re-opens buying;
 *   - the claims/default-key endpoints over HTTP;
 *   - refund marks (the 24-month fingerprint) round-trip.
 *
 * ⚠ Places ONE real order on a vpnhoodstore product for a throwaway client —
 * a real access token is created on the access manager, then terminated and
 * the order deleted in cleanup (same footprint as redeem.test.php).
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

const API_URL = 'https://whmcs-dev.vpnhood.com/modules/addons/vpnhoodiap/api.php';

/** A scripted store for the gate check: refusal must mean finalize NEVER ran. */
class FakeGateAdapter implements StoreAdapterInterface
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

    public function stopRenewals(array $app, string $purchaseKey): bool
    {
        return false;
    }
}

$marker = 'claimtest-' . bin2hex(random_bytes(4));
$repo = new IapRepository();
$clientId = 0;
$orderId = 0;
$serviceId = 0;
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
    localAPI('AddCredit', ['clientid' => $clientId, 'description' => 'claims test', 'amount' => '10.00']);

    $productId = (int) (one($db, "SELECT p.id FROM tblproducts p
        LEFT JOIN tblproducts_slugs s ON s.product_id = p.id AND s.active = 1
        WHERE p.slug = ? OR s.slug = ? LIMIT 1",
        ['reseller-one-month-premium-code-subscription', 'reseller-one-month-premium-code-subscription'])['id'] ?? 0);
    if ($productId === 0) {
        bad('recurring vpnhoodstore fixture product missing — run the hub repo bootstrap first');
        finish();
    }

    $order = localAPI('AddOrder', [
        'clientid' => $clientId, 'pid' => $productId, 'billingcycle' => 'monthly',
        'paymentmethod' => 'banktransfer', 'noemail' => true, 'noinvoiceemail' => true,
    ]);
    if (($order['result'] ?? '') !== 'success') {
        bad('AddOrder failed: ' . json_encode($order));
        finish();
    }
    $orderId = (int) $order['orderid'];
    $invoiceId = (int) ($order['invoiceid'] ?? 0);
    $serviceId = (int) explode(',', (string) ($order['productids'] ?? ''))[0];
    localAPI('ApplyCredit', ['invoiceid' => $invoiceId, 'amount' => 'full', 'noemail' => true]);
    $accepted = localAPI('AcceptOrder', ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => false]);
    if (($accepted['result'] ?? '') !== 'success') {
        bad('AcceptOrder failed: ' . json_encode($accepted));
        finish();
    }
    ok("real website order placed: client #$clientId order #$orderId service #$serviceId");

    // == provisioning marks (Phase 2 in vpnhoodstore) =========================
    IapRepository::serviceProperty($serviceId, 'accessTokenId') !== null
        ? ok('service carries accessTokenId')
        : bad('no accessTokenId — provisioning failed');
    $storedHash = IapRepository::serviceProperty($serviceId, 'accessCodeHash');
    $storedHash !== null
        ? ok('service carries accessCodeHash (codes still never persisted)')
        : bad('no accessCodeHash on the provisioned service');
    IapRepository::serviceProperty($serviceId, 'isDefaultKey') === 'yes'
        ? ok('the first key bought became the client\'s default at purchase time')
        : bad('isDefaultKey not set on a first purchase');

    // == the code round-trip ==================================================
    $reader = new DeliveryReader();
    $code = $reader->readAccessCode($serviceId);
    ($code !== null && $code !== '')
        ? ok('DeliveryReader read the live code (' . strlen($code) . ' chars)')
        : bad('no code readable for the service');
    hash('sha256', trim((string) $code)) === $storedHash
        ? ok('stored hash matches the live code (claim lookups will find it)')
        : bad('accessCodeHash does not match the live code');
    $state = $reader->readCodeState($serviceId);
    in_array($state['state'], ['active', 'not-started'], true)
        ? ok("readCodeState answers ({$state['state']})")
        : bad('unexpected code state: ' . json_encode($state));

    $keyService = new AccountKeyService($repo);
    $keyService->findServiceIdByCode((string) $code) === $serviceId
        ? ok('findServiceIdByCode resolves the pasted code to the service')
        : bad('code lookup failed');
    $keyService->findServiceIdByCode('no-such-code-' . $marker) === null
        ? ok('an unknown code resolves to nothing')
        : bad('an unknown code matched something');

    // == the buyer's own account sees its key =================================
    $userIds['owner'] = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-owner",
        'email' => "$marker@vpnhood.test", 'email_verified_claim' => 1,
        'client_id' => $clientId,
        'external_uid' => sprintf('%s-0000-4000-8000-%s', substr(md5("$marker-o"), 0, 8), substr(md5("$marker-o"), 0, 12)),
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $owner = (array) Capsule::table('mod_vpnhood_iap_users')->where('id', $userIds['owner'])->first();
    $ownerKeys = $keyService->webKeysForUser($owner);
    (count($ownerKeys) === 1 && $ownerKeys[0]['accessCode'] === $code
        && $ownerKeys[0]['isDefault'] === true)
        ? ok('the linked account sees its website key, marked default')
        : bad('owner webKeys wrong: ' . json_encode($ownerKeys));
    $keyService->defaultKeyIsActive($owner)
        ? ok('the owner is "already served" by their active default key')
        : bad('owner default key not detected as active');

    // == the mismatched-email person claims by code ===========================
    $userIds['claimer'] = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-claimer",
        'email' => "other-$marker@vpnhood.test", 'email_verified_claim' => 1,
        'client_id' => null,
        'external_uid' => sprintf('%s-0000-4000-8000-%s', substr(md5("$marker-c"), 0, 8), substr(md5("$marker-c"), 0, 12)),
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $claimer = (array) Capsule::table('mod_vpnhood_iap_users')->where('id', $userIds['claimer'])->first();

    $claim = $keyService->claim($userIds['claimer'], $serviceId);
    ($claim['created'] === true && $claim['isDefault'] === true)
        ? ok('claiming records the pointer, and the first claim becomes the default')
        : bad('claim wrong: ' . json_encode($claim));
    $again = $keyService->claim($userIds['claimer'], $serviceId);
    ($again['created'] === false && $again['isDefault'] === true)
        ? ok('re-claiming is idempotent')
        : bad('re-claim wrong: ' . json_encode($again));
    $claimerKeys = $keyService->webKeysForUser($claimer);
    (count($claimerKeys) === 1 && $claimerKeys[0]['accessCode'] === $code && $claimerKeys[0]['isDefault'] === true)
        ? ok('the claimer sees the claimed key as their default — billing stays where it was')
        : bad('claimer webKeys wrong: ' . json_encode($claimerKeys));

    // == the F2 gate, end to end ==============================================
    // a real app + catalog mapping, so the refusal we assert is the DEFAULT-KEY
    // gate — not the catalog gate parking an unmapped SKU
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
    $adapter = new FakeGateAdapter($record);
    try {
        (new EntitlementService($repo))->redeem($app, $record, $claimer, $adapter);
        bad('a store purchase was allowed although the default key serves this account');
    } catch (ApiException $e) {
        ($e->getHttpStatus() === 409 && $e->getErrorCode() === 'subscription_already_active')
            ? ok('the gate refuses the store purchase (409 subscription_already_active)')
            : bad('wrong refusal: ' . $e->getHttpStatus() . ' / ' . $e->getErrorCode());
    }
    $adapter->finalizeCalls === 0
        ? ok('refused BEFORE provisioning — finalize never ran, the store auto-refunds')
        : bad('finalize was called on a refused purchase');
    (string) (one($db, 'SELECT status FROM mod_vpnhood_iap_purchases WHERE purchase_key = ?', ["$marker-gate"])['status'] ?? '')
        === 'failed'
        ? ok('the refused purchase is parked as failed on the ledger')
        : bad('refused purchase not parked');

    // deliberately clearing the default is the escape that re-opens buying
    $keyService->setDefault($claimer, null);
    !$keyService->defaultKeyIsActive($claimer)
        ? ok('clearing the default re-opens buying (the deliberate escape)')
        : bad('default still active after clearing');
    $keyService->setDefault($claimer, (string) $code);
    $keyService->defaultKeyIsActive($claimer)
        ? ok('choosing a default again works (last-one-wins, deliberate acts only)')
        : bad('setDefault(code) did not take');

    // == the endpoints over HTTP ==============================================
    $sessions = new SessionService();
    $claimerToken = $sessions->issue($userIds['claimer'])['token'];
    [$status, $body] = claimsHttp('POST', '/account/claims', $claimerToken, ['accessCode' => $code]);
    ($status === 200 && ($body['accessCode'] ?? '') === $code)
        ? ok('POST /account/claims answers 200 for an already-claimed code (idempotent)')
        : bad("claims endpoint wrong: $status " . json_encode($body));
    [$status, $body] = claimsHttp('POST', '/account/claims', $claimerToken, ['accessCode' => 'nope-' . $marker]);
    $status === 404
        ? ok('an unknown code answers 404 code_not_found')
        : bad("unknown code answered $status");
    // POST, not PATCH: the dev host (like many) blocks PATCH at the web server;
    // the endpoint accepts both for exactly that reason
    [$status, $body] = claimsHttp('POST', '/account/default-key', $claimerToken, ['accessCode' => null]);
    $status === 204
        ? ok('POST /account/default-key null answers 204 (default cleared; PATCH alias for blocked hosts)')
        : bad("default-key endpoint wrong: $status " . json_encode($body));
    [$status, $body] = claimsHttp('GET', '/account/entitlements', $claimerToken, null);
    ($status === 200 && count($body['webKeys'] ?? []) === 1 && ($body['webKeys'][0]['isDefault'] ?? true) === false)
        ? ok('GET /account/entitlements lists webKeys with the cleared default')
        : bad("entitlements wrong: $status " . json_encode($body));

    // the wire carries a KEY and nothing about the portal's own vocabulary: no
    // delivery mode, no provenance, and (subscriptions) no store name — one app
    // ships on every platform and may not name a competing store (2.3.10)
    $wireKey = $body['webKeys'][0] ?? [];
    (array_keys($wireKey) === ['accessCode', 'expiresAt', 'isDefault'])
        ? ok('a webKey on the wire is exactly {accessCode, expiresAt, isDefault}')
        : bad('unexpected webKey fields: ' . json_encode(array_keys($wireKey)));

    // -- reseller stock is a merchant concept and never reaches the app -------
    // flip the fixture's own service to stock for the length of this check
    \WHMCS\Service\Service::find($serviceId)->serviceProperties->save(['bulkDelivery' => 'yes']);
    $ownerKeysAsBulk = $keyService->webKeysForUser($owner);
    $ownerKeysAsBulk === []
        ? ok('a bulk order never appears among the account keys — stock is not a key')
        : bad('bulk leaked into webKeys: ' . json_encode($ownerKeysAsBulk));
    $keyService->bulkOrderCount($owner) === 1
        ? ok('…but the portal still counts it, for the farewell message and the web deletion page')
        : bad('bulkOrderCount did not see the batch');
    \WHMCS\Service\Service::find($serviceId)->serviceProperties->save(['bulkDelivery' => '']);
    count($keyService->webKeysForUser($owner)) === 1
        ? ok('unmarking restores it as an ordinary key')
        : bad('the service did not come back after unmarking bulk');

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
    Capsule::table('mod_vpnhood_iap_purchases')->where('purchase_key', 'like', "$marker%")->delete();
    Capsule::table('mod_vpnhood_iap_refund_marks')
        ->where('email_hash', hash('sha256', "refund-$marker@vpnhood.test"))->delete();
    if (!empty($appId)) {
        Capsule::table('mod_vpnhood_iap_products')->where('app_id', $appId)->delete();
        Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    }
    if ($serviceId > 0) {
        localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
    }
    if ($orderId > 0) {
        localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    if ($clientId > 0) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    ok('fixtures cleaned up (token terminated, order + client deleted)');
}

finish();
