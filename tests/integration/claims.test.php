<?php
/**
 * claims.test.php — the account's one served code, end to end on the dev WHMCS
 * (lifecycle §8 + keyring plan: one imported-code slot, ONE RANKING recomputed on
 * every read, and the app is told a code — never a list):
 *
 *   - provisioning marks (Phase 2): a real vpnhoodstore order leaves
 *     accessCodeHash + isDefaultKey on the service;
 *   - attach by code: possession of the code finds the service (hash lookup on
 *     the hub), records a pointer, and the attached code becomes the account's
 *     chosen one. Attaching consumes nothing;
 *   - THE ONE SLOT: PUT atomically sets/replaces it and a null PUT empties it;
 *   - acceptance, not refusal (§8): a paid store purchase on an account already
 *     served by its chosen code is PROVISIONED and acknowledged — refusing after
 *     the money moved only ever worked on the one store that auto-refunds;
 *   - a second purchase never disturbs a working code (§8 rule 1);
 *   - NO promotion: a dead choice serves nothing and nothing is re-picked — the
 *     access server is the one that breaks the news (keyring plan §3/§6);
 *   - the legacy code endpoints answering 404, and the account snapshot carrying
 *     a single ranked accessCode (never a list);
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

/** A well-formed access code (version 1 + checksum over 18 digits), as the apps build one. */
function claimsBuildAccessCode(string $random): string
{
    $sum = 0;
    for ($i = 0, $len = strlen($random); $i < $len; $i++) {
        $sum += ord($random[$i]);
    }
    while ($sum >= 10) {
        $sum = array_sum(str_split((string) $sum));
    }
    return '1' . $sum . $random;
}
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
    // isDefaultKey is still written by provisioning but is no longer READ: there is no stored
    // selection any more (keyring plan §2), the ranking recomputes on every read.

    // == the code round-trip ==================================================
    $reader = new DeliveryReader();
    $code = $reader->readAccessCode($serviceId);
    ($code !== null && $code !== '')
        ? ok('DeliveryReader read the live code (' . strlen($code) . ' chars)')
        : bad('no code readable for the service');
    hash('sha256', trim((string) $code)) === $storedHash
        ? ok('stored hash matches the live code (a refusal will match this service)')
        : bad('accessCodeHash does not match the live code');
    $state = $reader->readCodeState($serviceId);
    in_array($state['state'], ['active', 'not-started'], true)
        ? ok("readCodeState answers ({$state['state']})")
        : bad('unexpected code state: ' . json_encode($state));

    $keyService = new AccountKeyService($repo);

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
    (count($ownerKeys) === 1 && $ownerKeys[0]['accessCode'] === $code
        && $ownerKeys[0]['isAutoSelectable'] === true && $ownerKeys[0]['uploaded'] === false)
        ? ok('the client-area listing (portal-side only) sees the code, eligible by default')
        : bad('owner client-area listing wrong: ' . json_encode($ownerKeys));

    // the panel's one control: turn it off and the ranking stops offering it — nothing is deleted
    $keyService->setAutoSelectable($owner, $serviceId, false);
    $keyService->accessCodeInfoForUser($owner) === null
        ? ok('a code turned off in the panel is skipped by the ranking on the very next read')
        : bad('isAutoSelectable=false did not remove the code from the ranking');
    $keyService->setAutoSelectable($owner, $serviceId, true);
    ($keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null) === $code
        ? ok('…and turning it back on restores it — the mark deletes nothing')
        : bad('isAutoSelectable=true did not restore the code');

    // == the mismatched-email person imports by code ==========================
    $userIds['claimer'] = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-claimer",
        'email' => "other-$marker@vpnhood.test", 'email_verified_claim' => 1,
        'client_id' => null,
        'external_uid' => sprintf('%s-0000-4000-8000-%s', substr(md5("$marker-c"), 0, 8), substr(md5("$marker-c"), 0, 12)),
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $claimer = (array) Capsule::table('mod_vpnhood_iap_users')->where('id', $userIds['claimer'])->first();

    $keyService->setAccessCode($claimer, (string) $code);
    $keyService->uploadedAccessCode($claimer) === $code
        ? ok('uploading stores the code itself — no service lookup, no answer to inspect')
        : bad('upload slot wrong: ' . json_encode($keyService->uploadedAccessCode($claimer)));
    $keyService->setAccessCode($claimer, (string) $code);
    $keyService->uploadedAccessCode($claimer) === $code
        ? ok('re-uploading is idempotent — nothing is consumed, nothing doubles')
        : bad('re-upload wrong');

    // the whole point of dropping the lookup: a code this portal has never issued still saves.
    // It has to LOOK like an access code — shape is input validation, not a claim about validity.
    $strangerCode = claimsBuildAccessCode('310000000000000003');
    $keyService->setAccessCode($claimer, $strangerCode);
    $keyService->uploadedAccessCode($claimer) === $strangerCode
        ? ok('a code the portal has never seen is accepted on trust — no code_not_found exists')
        : bad('an unknown code was refused by the slot');
    $keyService->setAccessCode($claimer, (string) $code);

    $claimerCode = $keyService->accessCodeInfoForUser($claimer);
    ($claimerCode !== null && $claimerCode['accessCode'] === $code)
        ? ok('the attacher is now served by the same code — billing stays where it was')
        : bad('claimer accessCodeInfo wrong: ' . json_encode($claimerCode));
    $ownerStill = $keyService->accessCodeInfoForUser($owner);
    ($ownerStill !== null && $ownerStill['accessCode'] === $code)
        ? ok('…and the owner keeps being served by it too: an attach takes nothing from anyone')
        : bad('attach disturbed the owner: ' . json_encode($ownerStill));

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

    // A dead service leaves the ranking with nothing to offer, and a revived one is picked up
    // again by itself — because nothing is stored as the selection (keyring plan §2).
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => 'Cancelled']);
    Capsule::table('tblhosting')->where('id', $serviceIds[1])->update(['domainstatus' => 'Cancelled']);
    $afterDeath = $keyService->accessCodeInfoForUser($owner);
    $afterDeath === null
        ? ok('with every service dead the ranking offers nothing — there is no stale stored choice')
        : bad('something served a dead service: ' . json_encode($afterDeath));
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => 'Active']);
    Capsule::table('tblhosting')->where('id', $serviceIds[1])->update(['domainstatus' => 'Active']);
    $revived = $keyService->accessCodeInfoForUser($owner);
    $revived !== null
        ? ok('a revived service is ranked again by itself — nothing had to be re-entered')
        : bad('the ranking did not recover after revival');

    // == the ONE upload slot (keyring plan §5) ================================
    // Uploading a different code replaces what is there: the accepted price of a single slot.
    $keyService->setAccessCode($claimer, (string) $code2);
    $keyService->uploadedAccessCode($claimer) === $code2
        ? ok('uploading a different code replaces the slot')
        : bad('replace wrong: ' . json_encode($keyService->uploadedAccessCode($claimer)));
    $claimRows = (int) Capsule::table('mod_vpnhood_iap_claims')->where('user_id', $userIds['claimer'])->count();
    $claimRows === 0
        ? ok('the slot is a stored string — it leaves no claim rows behind at all')
        : bad("claim rows after upload: $claimRows");

    // == eligibility: a device reports a REFUSAL, and nothing else (keyring plan §4) =========
    // Its own account, holding one imported code and nothing else: eligibility is about the code
    // that is CURRENT, and the accounts above carry purchases and website services that rank ahead.
    $userIds['importer'] = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider' => 'google', 'provider_subject' => "$marker-importer",
        'email' => "importer-$marker@vpnhood.test", 'email_verified_claim' => 1,
        'client_id' => null,
        'external_uid' => sprintf('%s-0000-4000-8000-%s', substr(md5("$marker-i"), 0, 8), substr(md5("$marker-i"), 0, 12)),
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $importer = (array) Capsule::table('mod_vpnhood_iap_users')->where('id', $userIds['importer'])->first();
    $importedCode = claimsBuildAccessCode('610000000000000006');
    $keyService->setAccessCode($importer, $importedCode);
    ($keyService->accessCodeInfoForUser($importer)['accessCode'] ?? null) === $importedCode
        ? ok('an account whose only code is the imported one is served by it')
        : bad('the imported code is not current: ' . json_encode($keyService->accessCodeInfoForUser($importer)));

    // a report about a code the account is not serving changes nothing — this is the device that
    // has been offline since before a replacement
    $keyService->reportRejected($importer, (string) $code);
    ($keyService->accessCodeInfoForUser($importer)['accessCode'] ?? null) === $importedCode
        ? ok('a refusal for a code the account no longer serves cannot disable the current one')
        : bad('a stale refusal disabled the current code');
    $keyService->isRejected($importer, (string) $code)
        ? bad('a report that did not apply was recorded anyway')
        : ok('…and it is not recorded either: it was overtaken, so nothing happened at all');

    // A REFUSAL DEMOTES, IT NEVER REMOVES. This account holds nothing else, so the refused code is
    // still what it is served: the person holds it, their device kept it anyway, and a second device
    // must not be told they have nothing. It is also how a topped-up code returns by itself.
    $keyService->reportRejected($importer, $importedCode);
    ($keyService->accessCodeInfoForUser($importer)['accessCode'] ?? null) === $importedCode
        ? ok('a refused code with nothing behind it is still served — a refusal demotes, never removes')
        : bad('a refused code was dropped although the account holds nothing else');
    $keyService->isRejected($importer, $importedCode)
        ? ok('…and the refusal is recorded all the same, so the client area still shows the fault')
        : bad('the refusal was not recorded');
    $keyService->uploadedAccessCode($importer) === $importedCode
        ? ok('the rejection never removed the code — nothing is deleted by the system (§3)')
        : bad('reporting a rejection deleted the code');

    // Retry, both halves: the client area clears it by hand, and re-adding the code clears it too
    $keyService->clearRejection($importer, $importedCode);
    $keyService->isRejected($importer, $importedCode)
        ? bad('clearing the rejection did not lift the mark')
        : ok('the client area can lift a refusal, so the code stops being demoted at all');

    $keyService->reportRejected($importer, $importedCode);
    $keyService->setAccessCode($importer, $importedCode);
    $keyService->isRejected($importer, $importedCode)
        ? bad('re-uploading a refused code left it marked')
        : ok('…and typing the same code again lifts it, which is the whole of Retry');

    // A REJECTION NEVER SKIPS WHAT IS BEING PAID FOR RIGHT NOW. The owner's code belongs to a live
    // recurring service, so a refusal is recorded and shown, but the person keeps being served:
    // downgrading a payer to a lesser code would hide our own provisioning fault. It is also how
    // renewal recovers, with nothing to clear by hand.
    $keyService->reportRejected($owner, (string) $code);
    ($keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null) === $code
        ? ok('a code being paid for right now is served even after a refusal was reported')
        : bad('a rejection skipped a paid-now code');
    $keyService->isRejected($owner, (string) $code)
        ? ok('…and the refusal is still recorded, so the client area can show the fault')
        : bad('the refusal for a paid-now code was not recorded');

    // …and somebody on OUR side is told, because a payer with a dead code is our fault to fix and
    // the customer writing in must not be the first we hear of it
    Capsule::table('tblactivitylog')
        ->where('description', 'like', '%REFUSED the code of a subscription being paid for%')
        ->where('description', 'like', '%iap user #' . $userIds['owner'] . '%')
        ->exists()
        ? ok('a refusal on something being paid for raises an admin activity entry')
        : bad('nothing was raised when a paying subscriber was refused');
    $keyService->clearRejection($owner, (string) $code);

    // ONCE EVERYTHING HAS BEEN REFUSED, THE ACCOUNT HOLDS ITS GROUND. Both of this account's
    // services are one-time here, so neither is being paid for and neither is exempt. A refusal
    // steps aside for the next working code; when no working code is left the account keeps
    // answering with the MOST recently refused one — what the devices already hold — and never
    // cycles through the rest hoping one works. Whoever tops a code up knows which one they paid,
    // and typing it is what selects it.
    $ownerCycle = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('billingcycle');
    Capsule::table('tblhosting')->whereIn('id', [$serviceId, $serviceIds[1]])
        ->update(['billingcycle' => 'One Time']);
    $first = $keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null;
    $keyService->reportRejected($owner, (string) $first);
    $second = $keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null;
    ($first !== null && $second !== null && $second !== $first)
        ? ok('a refusal steps aside for the next working code')
        : bad('a refused code was served while another was eligible: ' . json_encode([$first, $second]));

    $keyService->reportRejected($owner, (string) $second);
    ($keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null) === $second
        ? ok('…with every code refused the account holds its ground — same code, same honest refusal')
        : bad('the account did not hold the last refused code: ' . json_encode($keyService->accessCodeInfoForUser($owner)));

    // typing the topped-up code is what selects it — no cycling ever tries the others by itself
    $keyService->setAccessCode($owner, (string) $first);
    ($keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null) === $first
        ? ok('…and typing the code they topped up is what selects it')
        : bad('typing a refused code did not select it: ' . json_encode($keyService->accessCodeInfoForUser($owner)));
    $keyService->setAccessCode($owner, null);

    $keyService->clearRejection($owner, (string) $first);
    $keyService->clearRejection($owner, (string) $second);

    // …and that holds for a code the account ALREADY OWNS. Both codes work here and the ranking
    // prefers $first (oldest); typing $second must still switch to it — the old special case
    // flipped a panel flag instead of filling the slot, and the ranking carried on with $first.
    $keyService->setAccessCode($owner, (string) $second);
    ($keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null) === $second
        ? ok('typing a code the account already owns selects it — no owned-code special case')
        : bad('typing an owned code did not select it: ' . json_encode($keyService->accessCodeInfoForUser($owner)));
    $keyService->setAccessCode($owner, null);

    // TYPING A CODE MEANS "USE THIS ONE". While nobody is being billed, the code somebody typed
    // outranks the codes we sold them — anything else leaves them staring at an app that took
    // their code and carried on as before.
    $typed = claimsBuildAccessCode('710000000000000007');
    $keyService->setAccessCode($owner, $typed);
    ($keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null) === $typed
        ? ok('a typed code outranks the website codes nobody is being billed for')
        : bad('the typed code did not win: ' . json_encode($keyService->accessCodeInfoForUser($owner)));

    // …and the moment one of them IS being paid for, the payer wins again: a fresh code is never
    // spent on top of a subscription. Whoever wants that anyway signs out.
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['billingcycle' => $ownerCycle]);
    ($keyService->accessCodeInfoForUser($owner)['accessCode'] ?? null) === $code
        ? ok('…but never over a code being paid for right now — that is what signing out is for')
        : bad('a typed code was served ahead of something being paid for');
    $keyService->setAccessCode($owner, null);

    Capsule::table('tblhosting')->whereIn('id', [$serviceId, $serviceIds[1]])
        ->update(['billingcycle' => $ownerCycle]);

    // A SUBSCRIPTION WE ENDED IS NOT ONE OF THEIR CODES. We are the ones who ended it, so the
    // moment the paid time is over its code stops being served — the person falls through to what
    // they actually hold instead of being handed back a credential that was taken away.
    $storeRow = Capsule::table('mod_vpnhood_iap_purchases')
        ->where('user_id', $userIds['claimer'])->whereNotNull('service_id')->orderByDesc('id')->first();
    if ($storeRow === null) {
        bad('no store purchase row to check the subscription rule against');
    } else {
        $storeRowId = (int) $storeRow->id;
        $storeCode = $reader->readAccessCode((int) $storeRow->service_id);
        $restore = fn () => Capsule::table('mod_vpnhood_iap_purchases')->where('id', $storeRowId)
            ->update(['status' => (string) $storeRow->status, 'expiry_time' => $storeRow->expiry_time]);

        Capsule::table('mod_vpnhood_iap_purchases')->where('id', $storeRowId)
            ->update(['status' => 'provisioned', 'expiry_time' => gmdate('Y-m-d H:i:s', time() + 86400)]);
        ($keyService->accessCodeInfoForUser($claimer)['accessCode'] ?? null) === $storeCode
            ? ok('a live store subscription is what the account serves')
            : bad('the store subscription was not served: ' . json_encode($keyService->accessCodeInfoForUser($claimer)));

        // A LONG BUYING HISTORY MUST NOT HIDE A LIVE SUBSCRIPTION. Finished purchases piled on top
        // of it used to push it out of the window the account looked at, and somebody who was
        // still being billed quietly stopped being premium.
        $noise = [];
        for ($i = 0; $i < 12; $i++) {
            $noise[] = (int) Capsule::table('mod_vpnhood_iap_purchases')->insertGetId([
                'app_id'       => $appId,
                'store'        => 'googleplay',
                'purchase_key' => "noise-$marker-$i",
                'user_id'      => $userIds['claimer'],
                'service_id'   => 990000 + $i,
                'status'       => $i % 2 === 0 ? 'refunded' : 'expired',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }
        ($keyService->accessCodeInfoForUser($claimer)['accessCode'] ?? null) === $storeCode
            ? ok('…and a pile of finished purchases on top of it cannot hide it')
            : bad('newer ended purchases hid a live subscription: ' . json_encode($keyService->accessCodeInfoForUser($claimer)));
        Capsule::table('mod_vpnhood_iap_purchases')->whereIn('id', $noise)->delete();

        // cancelled is NOT ended: auto-renew is off, and the time already paid for is theirs
        Capsule::table('mod_vpnhood_iap_purchases')->where('id', $storeRowId)->update(['status' => 'canceled']);
        ($keyService->accessCodeInfoForUser($claimer)['accessCode'] ?? null) === $storeCode
            ? ok('…and cancelling keeps serving it: the paid period is never taken back early')
            : bad('cancelling took away time the person had already paid for');

        // a refusal cannot take away a subscription somebody is still paying for
        $keyService->reportRejected($claimer, (string) $storeCode);
        ($keyService->accessCodeInfoForUser($claimer)['accessCode'] ?? null) === $storeCode
            ? ok('…nor can a refusal, while the store is still charging')
            : bad('a refusal skipped a live store subscription');
        $keyService->clearRejection($claimer, (string) $storeCode);

        // refunded, expired, and simply run out: all ended, all gone from the account at once
        foreach ([
            ['refunded', null, 'a refunded subscription'],
            ['expired', null, 'an expired subscription'],
            ['provisioned', gmdate('Y-m-d H:i:s', time() - 3600), 'a subscription whose paid time ran out'],
        ] as [$status, $expiry, $what]) {
            Capsule::table('mod_vpnhood_iap_purchases')->where('id', $storeRowId)
                ->update(['status' => $status, 'expiry_time' => $expiry]);
            ($keyService->accessCodeInfoForUser($claimer)['accessCode'] ?? null) !== $storeCode
                ? ok("$what stops being one of their codes at once — no refusal needed")
                : bad("$what was still served");
        }
        $restore();
    }

    // Emptying is idempotent and removes only the account's copy.
    $keyService->setAccessCode($claimer, null);
    $keyService->uploadedAccessCode($claimer) === null
        ? ok('a null upload empties the slot')
        : bad('null upload did not empty the slot');
    $keyService->setAccessCode($claimer, null);
    ok('a second null upload is idempotent');

    // The wire resource uses one PUT and answers NO BODY — the code is taken on trust.
    [$status, $body] = claimsHttp('PUT', '/account/access-code', $claimerToken,
        ['accessCode' => (string) $code]);
    ($status === 204 && ($body === null || $body === []))
        ? ok('PUT fills the slot and answers 204 with no body')
        : bad("wire initial PUT wrong: $status " . json_encode($body));
    [$status] = claimsHttp('PUT', '/account/access-code', $claimerToken,
        ['accessCode' => claimsBuildAccessCode('410000000000000004')]);
    $status === 204
        ? ok('a code this portal never issued is stored too — there is no code_not_found any more')
        : bad("wire PUT of an unknown code wrong: $status");
    [$status] = claimsHttp('POST', '/account/access-code/rejected', $claimerToken,
        ['accessCode' => claimsBuildAccessCode('510000000000000005')]);
    $status === 204
        ? ok('a device can report a refusal over the wire, and a report that does not apply is still 204')
        : bad("wire rejection report wrong: $status");
    [$status, $body] = claimsHttp('POST', '/account/access-code/rejected', $claimerToken, []);
    $status === 400
        ? ok('a rejection report with no code is a clean 400')
        : bad("wire rejection report without a code wrong: $status " . json_encode($body));

    // Shape is enforced on BOTH writes: a malformed string is bad input, whatever the access
    // server might have made of it (§5).
    [$status] = claimsHttp('PUT', '/account/access-code', $claimerToken,
        ['accessCode' => "never-issued-$marker"]);
    $status === 400
        ? ok('a string that is not an access code is a clean 400 on the slot')
        : bad("wire PUT of a malformed code wrong: $status");
    [$status] = claimsHttp('POST', '/account/access-code/rejected', $claimerToken,
        ['accessCode' => str_repeat('9', 200)]);
    $status === 400
        ? ok('…and on the rejection report, which the 64-character column also depends on')
        : bad("wire rejection of a malformed code wrong: $status");
    [$status] = claimsHttp('PUT', '/account/access-code', $claimerToken, ['accessCode' => null]);
    $status === 204
        ? ok('null PUT empties the slot over the wire')
        : bad("wire null PUT wrong: $status");

    // -- reseller stock is a merchant concept and never reaches the app -------
    // flip both services to stock: the ranking must offer nothing rather than fall back to it
    \WHMCS\Service\Service::find($serviceIds[1])->serviceProperties->save(['bulkDelivery' => 'yes']);
    \WHMCS\Service\Service::find($serviceId)->serviceProperties->save(['bulkDelivery' => 'yes']);
    $ownerAsBulk = $keyService->accessCodeInfoForUser($owner);
    $ownerAsBulk === null
        ? ok('stock is never served as the account\'s code, whatever else it holds')
        : bad('bulk leaked into accessCodeInfo: ' . json_encode($ownerAsBulk));
    $keyService->bulkOrderCount($owner) === 2
        ? ok('…but the portal still counts it, for the web deletion page')
        : bad('bulkOrderCount did not see the batches');
    \WHMCS\Service\Service::find($serviceIds[1])->serviceProperties->save(['bulkDelivery' => '']);
    \WHMCS\Service\Service::find($serviceId)->serviceProperties->save(['bulkDelivery' => '']);

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
        Capsule::table('mod_vpnhood_iap_code_rejections')->where('user_id', $userId)->delete();
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
