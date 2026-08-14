<?php

/**
 * VpnHood! IAP — the Portal API: the REST surface the apps talk to.
 *
 * Base URL:
 *   https://<whmcs>/modules/addons/vpnhoodiap/api.php
 *
 * The path after api.php is the resource (PHP PATH_INFO), so no rewrite rule
 * and no server config is needed on a partner's install. Hosts that strip
 * PATH_INFO can use the equivalent ?path=/account form; both route identically.
 *
 *   GET    /openapi.json              this API's OpenAPI 3.1 document (no auth)
 *   GET    /system/status             service probe (no auth)
 *   POST   /auth/sessions             sign in with a provider id token (no auth)
 *   DELETE /auth/sessions/current     sign out
 *   GET    /account                   the signed-in account
 *   GET    /account/entitlements      what that account currently holds
 *   GET    /billing/plans             the sellable plans of one app+store (no auth)
 *   POST   /billing/purchases         redeem a store purchase → access code
 *
 * Every resource hangs off a controller; the OpenAPI document is the deliberate
 * exception, because tooling expects to find it at the root of an API.
 *
 * There is no version in the path on purpose: a breaking change ships as a new
 * endpoint beside the old one, so an app that cannot be updated keeps working
 * without freezing the other seven. The contract version lives in the OpenAPI
 * document and in GET /system/status.
 *
 * Auth: the opaque session token from POST /auth/sessions, sent as
 * Authorization: Bearer <token> — or X-Portal-Token: <token> for proxies that
 * strip Authorization.
 *
 * Responses are the resource itself (no envelope). Errors are RFC 9457
 * problem+json — `code` is the contract, `detail` is prose:
 *   { "type": "...", "title": "...", "status": 401, "code": "unauthorized",
 *     "detail": "..." }
 *
 * FAILS CLOSED: while the addon is not activated on this install, every request is
 * answered 404 — the module ships inside the hub and partner packages but must expose
 * nothing until an admin activates and configures it.
 *
 * The contract lives in openapi.json next to this file; keep them in step.
 */

use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Auth\GoogleIdentityProvider;
use WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountKeyService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\ClientProvisioner;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\DeliveryReader;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterRegistry;

// Bootstrap WHMCS (gives us Capsule, localAPI, models, etc.).
require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Jwt.php';
require_once __DIR__ . '/lib/IapRepository.php';
require_once __DIR__ . '/lib/Auth/IdentityProviderInterface.php';
require_once __DIR__ . '/lib/Auth/GoogleIdentityProvider.php';
require_once __DIR__ . '/lib/Jwk.php';
require_once __DIR__ . '/lib/Auth/AppleIdentityProvider.php';
require_once __DIR__ . '/lib/Auth/SessionService.php';
require_once __DIR__ . '/lib/Stores/Dto/PurchaseRecord.php';
require_once __DIR__ . '/lib/Stores/Dto/StoreNotification.php';
require_once __DIR__ . '/lib/Stores/StoreAdapterInterface.php';
require_once __DIR__ . '/lib/Stores/StoreAdapterRegistry.php';
require_once __DIR__ . '/lib/Stores/GooglePlay/GooglePlayApiClient.php';
require_once __DIR__ . '/lib/Stores/GooglePlay/GooglePlayAdapter.php';
require_once __DIR__ . '/lib/Stores/AppStore/AppleJws.php';
require_once __DIR__ . '/lib/Stores/AppStore/AppStoreApiClient.php';
require_once __DIR__ . '/lib/Stores/AppStore/AppStoreAdapter.php';
require_once __DIR__ . '/lib/Provisioning/AccountDeletionService.php';
require_once __DIR__ . '/lib/Provisioning/AccountKeyService.php';
require_once __DIR__ . '/lib/Provisioning/AccountService.php';
require_once __DIR__ . '/lib/Provisioning/ClientProvisioner.php';
require_once __DIR__ . '/lib/Provisioning/OrderProvisioner.php';
require_once __DIR__ . '/lib/Provisioning/DeliveryReader.php';
require_once __DIR__ . '/lib/Provisioning/EntitlementService.php';

/**
 * The routing table: resource → method → handler. A path that exists with a
 * different method answers 405 + Allow, never 404 — the difference tells a
 * client integrator whether the URL or the verb is wrong.
 */
const VPNHOODIAP_ROUTES = [
    '/openapi.json'          => ['GET' => 'vpnhoodiap_getOpenApi'],
    '/system/status'         => ['GET' => 'vpnhoodiap_getStatus'],
    '/auth/sessions'         => ['POST' => 'vpnhoodiap_createSession'],
    '/auth/sessions/current' => ['DELETE' => 'vpnhoodiap_deleteCurrentSession'],
    '/account'               => ['GET' => 'vpnhoodiap_getAccount', 'DELETE' => 'vpnhoodiap_deleteAccount'],
    '/account/entitlements'  => ['GET' => 'vpnhoodiap_listEntitlements'],
    '/account/claims'        => ['POST' => 'vpnhoodiap_claimCode'],
    // POST tolerated beside PATCH: some hosts (LiteSpeed defaults among them)
    // refuse PATCH at the web-server layer before PHP ever runs
    '/account/default-key'   => ['PATCH' => 'vpnhoodiap_setDefaultKey', 'POST' => 'vpnhoodiap_setDefaultKey'],
    '/account/deletion-preview' => ['GET' => 'vpnhoodiap_deletionPreview'],
    '/billing/plans'         => ['GET' => 'vpnhoodiap_listPlans'],
    '/billing/purchases'     => ['POST' => 'vpnhoodiap_createPurchase'],
];

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$route = '';
$repo = null;
$logged = null;

try {
    if (!IapRepository::isModuleActive()) {
        vpnhoodiap_problem(404, 'not_found', 'Not found.');
    }
    $repo = new IapRepository();

    $path = vpnhoodiap_requestPath();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'HEAD') {
        $method = 'GET';
    }

    $handlers = VPNHOODIAP_ROUTES[$path] ?? null;
    if ($handlers === null) {
        throw new ApiException("No such resource: $path", 404, 'not_found');
    }
    if (!isset($handlers[$method])) {
        header('Allow: ' . implode(', ', array_keys($handlers)));
        throw new ApiException("$method is not allowed on $path", 405, 'method_not_allowed');
    }
    $route = "$method $path";

    $request = [
        'route'  => $route,
        'body'   => in_array($method, ['POST', 'PUT', 'PATCH'], true) ? vpnhoodiap_jsonBody() : [],
        'query'  => $_GET,
        'ip'     => $remoteIp,
    ];
    $logged = $request['body'] !== [] ? $request['body'] : $request['query'];

    /** @var array{0:int, 1:mixed} $result status + body (null = no content) */
    $result = $handlers[$method]($repo, $request);
    [$status, $data] = $result;

    $repo->log(null, $route, $remoteIp, $status, vpnhoodiap_redact($logged), vpnhoodiap_redact($data));
    vpnhoodiap_respond($status, $data);
} catch (ApiException $e) {
    $status = $e->getHttpStatus();
    $repo?->log(null, $route, $remoteIp, $status, vpnhoodiap_redact($logged), $e->getMessage());
    vpnhoodiap_problem($status, $e->getErrorCode(), $e->getMessage());
} catch (\Throwable $e) {
    logModuleCall('vpnhoodiap', 'api', $route, $e->getMessage(), $e->getTraceAsString());
    $repo?->log(null, $route, $remoteIp, 500, vpnhoodiap_redact($logged), $e->getMessage());
    vpnhoodiap_problem(500, 'internal_error', 'Internal error.');
}

// --------------------------------------------------------------- handlers --
// Each returns [httpStatus, body]; body null means "no content".

/** GET /system/status — proves the addon is active and the DB reachable. No data exposed. */
function vpnhoodiap_getStatus(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 30, 60);
    return [200, ['status' => 'ok', 'api' => '1.0', 'time' => gmdate('c')]];
}

/** GET /openapi.json — the machine-readable contract, served from the module. */
function vpnhoodiap_getOpenApi(IapRepository $repo, array $request): array
{
    $spec = @file_get_contents(__DIR__ . '/openapi.json');
    if ($spec === false) {
        throw new ApiException('The API document is not installed.', 404, 'not_found');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo $spec;
    exit;
}

/**
 * POST /auth/sessions — sign in with a provider id token.
 *
 * { provider: "google"|"apple", idToken: "...", packageName: "com..." }
 * → 201 { accessToken, expiresAt, userId, account: { email } }
 */
function vpnhoodiap_createSession(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 20, 300);

    $body = $request['body'];
    $provider = (string) ($body['provider'] ?? '');
    $idToken = (string) ($body['idToken'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    if ($idToken === '' || $packageName === '') {
        throw new ApiException('idToken and packageName are required.', 400, 'bad_request');
    }
    $identityProvider = match ($provider) {
        'google' => new GoogleIdentityProvider(),
        'apple'  => new \WHMCS\Module\Addon\VpnHoodIap\Auth\AppleIdentityProvider(),
        default  => throw new ApiException("Unsupported sign-in provider: $provider", 400, 'unsupported_provider'),
    };

    $app = $repo->findAppByPackageAnyStore($packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403, 'unknown_app');
    }
    $allowedAudiences = array_values(array_filter(array_map('trim', explode(',', (string) $app['oauth_client_ids']))));

    try {
        $identity = $identityProvider->verifyIdToken($idToken, $allowedAudiences);
    } catch (\RuntimeException $e) {
        // exact reason goes to the audit log; the client only learns "invalid"
        $repo->log(null, $request['route'], $request['ip'], 401, $packageName, $e->getMessage());
        throw new ApiException('Invalid sign-in token.', 401, 'invalid_id_token');
    }
    if (!$identity['emailVerified']) {
        // the IdP itself has not verified the mailbox — nothing to match on safely
        throw new ApiException('The signed-in email is not verified with the identity provider.', 403,
            'provider_email_unverified');
    }

    $user = $repo->findOrCreateUser($identityProvider->providerId(), $identity['subject'], $identity['email'], true,
        $identity['name'] ?? null);

    // link an existing WHMCS account by email; new emails stay unlinked until
    // first purchase creates their client. The IdP already proved the mailbox,
    // so nothing here waits on a WHMCS-side verification round trip.
    $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;
    if ($clientId === null) {
        $resolution = (new AccountService())->resolveClientForEmail($identity['email']);
        if ($resolution['clientId'] !== null) {
            $clientId = (int) $resolution['clientId'];
            $repo->linkUserClient((int) $user['id'], $clientId);
        }
    }
    if ($clientId !== null) {
        // the IdP just gave us fresh data — mirror it onto the WHMCS client
        (new ClientProvisioner())->syncClient($clientId, $identity['name'] ?? null);
    }

    $session = (new SessionService())->issue((int) $user['id']);
    return [201, [
        'accessToken' => $session['token'],
        'expiresAt'   => $session['expiresAt'],
        'userId'      => $user['external_uid'],
        'account'     => ['email' => $user['email']],
    ]];
}

/** DELETE /auth/sessions/current — sign out. Idempotent: always 204. */
function vpnhoodiap_deleteCurrentSession(IapRepository $repo, array $request): array
{
    (new SessionService())->revoke(vpnhoodiap_bearerToken());
    return [204, null];
}

/** GET /account — current account snapshot for the signed-in user. */
function vpnhoodiap_getAccount(IapRepository $repo, array $request): array
{
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());
    return [200, [
        'userId'  => $user['external_uid'],
        'account' => ['email' => $user['email']],
    ]];
}

/**
 * DELETE /account — "forget me" (Apple 5.1.1(v), Play account deletion, GDPR
 * Art. 17). The person is erased everywhere at once — sessions on every device,
 * sign-in identities, the account row — and the WHMCS client behind the retained
 * invoices is anonymized and closed. Nothing blocks it (lifecycle §8): web
 * billing is cancelled at the end of its paid period instead, stored payment
 * methods are dropped, and one final message carries the keys and warnings to
 * the address before it is erased. Body/query `stopRenewals=true` additionally
 * asks the stores that allow it to stop future renewals (Google yes, Apple no —
 * the preview says which). Running keys are deliberately left alone: they are
 * open gates with no personal data, already paid for.
 */
function vpnhoodiap_deleteAccount(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 5, 300);
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());

    $stopRenewals = filter_var(
        $request['body']['stopRenewals'] ?? $request['query']['stopRenewals'] ?? false,
        FILTER_VALIDATE_BOOL);

    // collect everything the farewell message must carry BEFORE anything dies
    $preview = vpnhoodiap_buildDeletionPreview($repo, $user);
    (new AccountDeletionService())->deleteUser($user, [
        'stopRenewals'         => $stopRenewals,
        'keys'                 => $preview['keys'],
        // bulk never reaches the wire (a merchant concept, not a key), but the
        // farewell message still says the delivered file cannot be served again
        'bulkOrders'           => (new AccountKeyService($repo))->bulkOrderCount($user),
        'subscriptionWarnings' => array_values(array_filter(array_map(
            fn (array $subscription) => $subscription['warning'] ?? null, $preview['subscriptions']))),
    ]);
    return [204, null];
}

/**
 * GET /account/deletion-preview — everything the person must see before they
 * confirm (lifecycle §5/§10): every key they paid for shown one last time, the
 * state of every store subscription and whether we can stop its renewals for
 * them, and how many web-billed services will be cancelled at period end.
 */
function vpnhoodiap_deletionPreview(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 10, 300);
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());
    return [200, vpnhoodiap_buildDeletionPreview($repo, $user)];
}

/** The shared preview builder: also feeds the final message at actual deletion. */
function vpnhoodiap_buildDeletionPreview(IapRepository $repo, array $user): array
{
    $keyService = new AccountKeyService($repo);
    $keys = $keyService->webKeysForUser($user);

    $subscriptions = [];
    $rows = \WHMCS\Database\Capsule::table('mod_vpnhood_iap_purchases')
        ->where('user_id', (int) $user['id'])
        ->where('status', 'provisioned')
        ->get()->map(fn ($row) => (array) $row)->all();
    $reader = new DeliveryReader();
    foreach ($rows as $row) {
        $expiry = $row['expiry_time'] !== null ? strtotime((string) $row['expiry_time']) : null;
        $expired = $expiry !== null && $expiry < time();
        $autoRenewing = (bool) $row['auto_renewing'];
        if ($expired && !$autoRenewing) {
            continue; // fully over — nothing to show, nothing to warn about
        }
        if (!$expired && $row['service_id'] !== null) {
            $code = $reader->readAccessCode((int) $row['service_id']);
            if ($code !== null) {
                $keys[] = [
                    'accessCode' => $code,
                    'expiresAt'  => $expiry !== null ? gmdate('c', $expiry) : null,
                    'isDefault'  => false,
                ];
            }
        }
        $canStopRenewals = $autoRenewing && (string) $row['store'] === 'googleplay';
        // grace/hold: the store still holds the subscription open although access
        // stopped — the case most likely to charge again unexpectedly (§8)
        $betweenPayments = $expired && $autoRenewing;
        $warning = null;
        if ($autoRenewing) {
            $warning = $betweenPayments
                ? 'A subscription with a failed payment is still open at the store and may start charging again. '
                    . 'Cancel it in the store it was bought from.'
                : 'Deleting the account does not cancel the subscription — it may keep renewing until '
                    . 'cancelled in the store it was bought from.';
        }
        // NO store name on the wire, deliberately: an app shipping on every
        // platform may not name a competing store (App Review 2.3.10), and the
        // only actionable fact — can WE stop the renewals — is its own flag.
        $subscriptions[] = [
            'autoRenewing'    => $autoRenewing,
            'expiresAt'       => $expiry !== null ? gmdate('c', $expiry) : null,
            'state'           => $betweenPayments ? 'between-payments' : ($expired ? 'expired' : 'active'),
            'canStopRenewals' => $canStopRenewals,
            'warning'         => $warning,
        ];
    }

    $webBilling = 0;
    if ($user['client_id'] !== null) {
        $moduleServiceIds = \WHMCS\Database\Capsule::table('mod_vpnhood_iap_purchases')
            ->where('client_id', (int) $user['client_id'])->whereNotNull('service_id')->pluck('service_id')->all();
        $webBilling = (int) \WHMCS\Database\Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', (int) $user['client_id'])
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->where('p.paytype', 'recurring')
            ->whereNotIn('h.id', $moduleServiceIds ?: [0])
            ->count();
    }

    return [
        'keys'          => $keys,
        'subscriptions' => $subscriptions,
        'webBilling'    => ['servicesToCancelAtPeriodEnd' => $webBilling],
    ];
}

/**
 * POST /account/claims — claim by code (lifecycle §8): pasting a code once
 * proves possession and records a pointer. Nothing about billing moves. The
 * first key an account ever points at becomes its default. Tightly rate
 * limited: possession is the proof, so guessing must be expensive.
 *
 * { accessCode: "..." } → 201 (created) / 200 (already claimed)
 */
function vpnhoodiap_claimCode(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 10, 300);
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());

    $accessCode = trim((string) ($request['body']['accessCode'] ?? ''));
    if ($accessCode === '') {
        throw new ApiException('accessCode is required.', 400, 'bad_request');
    }
    $keyService = new AccountKeyService($repo);
    $serviceId = $keyService->findServiceIdByCode($accessCode);
    if ($serviceId === null) {
        throw new ApiException('No key with this code.', 404, 'code_not_found');
    }
    $claim = $keyService->claim((int) $user['id'], $serviceId);
    $state = (new DeliveryReader())->readCodeState($serviceId);
    return [$claim['created'] ? 201 : 200, [
        'accessCode' => $accessCode,
        'expiresAt'  => $state['expiresAt'],
        'isDefault'  => $claim['isDefault'],
    ]];
}

/**
 * PATCH /account/default-key — deliberately choose (or clear, with null) THE
 * key that serves this account. Last-one-wins applies to exactly this kind of
 * deliberate act; nothing automatic ever calls it.
 *
 * { accessCode: "..." | null } → 204
 */
function vpnhoodiap_setDefaultKey(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 10, 300);
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());

    $body = $request['body'];
    if (!array_key_exists('accessCode', $body)) {
        throw new ApiException('accessCode is required (null clears the default).', 400, 'bad_request');
    }
    $accessCode = $body['accessCode'] !== null ? trim((string) $body['accessCode']) : null;
    (new AccountKeyService($repo))->setDefault($user, $accessCode === '' ? null : $accessCode);
    return [204, null];
}

/**
 * POST /billing/purchases — the primary purchase flow: validate the store proof,
 * provision, return the access code. One synchronous call, no client polling.
 *
 * { store: "googleplay", packageName: "com...", proof: {...} }
 * → 201 { state: "provisioned", accessCode, expiresAt, planId, purchasedAt,
 *         autoRenewing, priceAmount, priceCurrency, billingPeriod }
 * → 202 { state: "pending", accessCode: null, ... }
 *
 * Redeeming the same purchase again returns the same entitlement (201): the
 * store purchase key is the idempotency anchor, so a retry never double-orders.
 */
function vpnhoodiap_createPurchase(IapRepository $repo, array $request): array
{
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());
    vpnhoodiap_rateLimit($repo, $request, 30, 300);

    $body = $request['body'];
    $store = (string) ($body['store'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    $proof = $body['proof'] ?? null;
    if ($store === '' || $packageName === '' || !is_array($proof)) {
        throw new ApiException('store, packageName and proof are required.', 400, 'bad_request');
    }
    $app = $repo->findAppByPackageName($store, $packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403, 'unknown_app');
    }

    $adapter = StoreAdapterRegistry::get($store);
    try {
        $record = $adapter->verifyPurchase($app, $proof);
    } catch (\RuntimeException $e) {
        $repo->log((int) $user['id'], $request['route'], $request['ip'], 400, $packageName, $e->getMessage());
        throw new ApiException('The purchase could not be validated with the store.', 400, 'purchase_invalid');
    }

    $entitlement = (new EntitlementService($repo))->redeem($app, $record, $user, $adapter);
    $entitlement['store'] = $store; // which store billed it — clients key "manage subscription" off this

    // 202 while the entitlement is not deliverable yet (store still settling, or
    // the account's email awaits verification); the client polls or retries.
    return [$entitlement['state'] === 'provisioned' ? 201 : 202, $entitlement];
}

/** GET /account/entitlements — what the signed-in account currently holds. */
function vpnhoodiap_listEntitlements(IapRepository $repo, array $request): array
{
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());
    $rows = \WHMCS\Database\Capsule::table('mod_vpnhood_iap_purchases')
        ->where('user_id', (int) $user['id'])
        ->where('status', 'provisioned')
        ->orderByDesc('id')
        ->limit(10)
        ->get()->map(fn ($row) => (array) $row)->all();

    $reader = new DeliveryReader();
    $items = [];
    foreach ($rows as $row) {
        $expiry = $row['expiry_time'] !== null ? strtotime((string) $row['expiry_time']) : null;
        if ($expiry !== null && $expiry < time()) {
            continue;
        }
        $items[] = [
            'state'         => 'provisioned',
            'accessCode'    => $row['service_id'] !== null ? $reader->readAccessCode((int) $row['service_id']) : null,
            'expiresAt'     => $expiry !== null ? gmdate('c', $expiry) : null,
            'store'         => (string) $row['store'],
            // what the buyer is actually on: the app shows this as the subscription
            // summary, so it must not need a second call to the store to render
            'purchasedAt'   => $row['created_at'] !== null ? gmdate('c', strtotime((string) $row['created_at'])) : null,
            'autoRenewing'  => (bool) $row['auto_renewing'],
            'priceAmount'   => $row['store_amount'],
            'priceCurrency' => $row['store_currency'],
            'billingPeriod' => $row['service_id'] !== null
                ? IapRepository::billingPeriodForService((int) $row['service_id'])
                : null,
        ];
    }

    // website keys this account can see: its linked client's own services plus
    // anything it claimed by code (lifecycle §7/§8) — with the default marked,
    // which is what the app auto-applies at sign-in
    $webKeys = (new AccountKeyService($repo))->webKeysForUser($user);

    return [200, ['items' => $items, 'webKeys' => $webKeys]];
}

/**
 * GET /billing/plans?store=&packageName= — the sellable plans for one app+store.
 * WHMCS is the source of truth for WHAT is sellable; the store prices it.
 * Unmapped plans simply don't appear.
 *
 * No session: an app renders its plans page before anyone signs in, so gating this
 * would force every app to ship a hardcoded product list and drift from the catalog
 * it is mapped against. Nothing here is account-scoped, and the ids are public in
 * the store listing anyway — only WHAT this app sells, never WHO buys it.
 */
function vpnhoodiap_listPlans(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 30, 60);

    $store = (string) ($request['query']['store'] ?? '');
    $packageName = (string) ($request['query']['packageName'] ?? '');
    if ($store === '' || $packageName === '') {
        throw new ApiException('store and packageName are required.', 400, 'bad_request');
    }
    $app = $repo->findAppByPackageName($store, $packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403, 'unknown_app');
    }
    $items = [];
    foreach ($repo->allProductMappings() as $mapping) {
        if ((int) $mapping['app_id'] !== (int) $app['id'] || !$mapping['enabled']) {
            continue;
        }
        $items[] = [
            'planId'         => $mapping['store_base_plan_id'] !== ''
                ? $mapping['store_product_id'] . '/' . $mapping['store_base_plan_id']
                : $mapping['store_product_id'],
            'storeProductId' => $mapping['store_product_id'],
            'basePlanId'     => $mapping['store_base_plan_id'],
        ];
    }
    return [200, ['items' => $items]];
}

// ---------------------------------------------------------------- helpers --

/**
 * The resource path, e.g. "/account". PATH_INFO is the normal source; ?path= is
 * the escape hatch for hosts that strip it (some nginx/php-fpm setups).
 */
function vpnhoodiap_requestPath(): string
{
    $path = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($path === '') {
        $path = (string) ($_GET['path'] ?? '');
    }
    $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '', '/');
    return $path === '/' ? '/system/status' : $path;
}

/** The parsed JSON request body; an empty body is an empty array, not an error. */
function vpnhoodiap_jsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (trim($raw) === '') {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new ApiException('Request body must be valid JSON.', 400, 'bad_request');
    }
    return $body;
}

/** Sliding-window limit per IP and route. */
function vpnhoodiap_rateLimit(IapRepository $repo, array $request, int $limit, int $windowSeconds): void
{
    if ($repo->requestCount($request['ip'], $request['route'], $windowSeconds) > $limit) {
        throw new ApiException('Too many requests.', 429, 'rate_limited');
    }
}

/** The bearer session token: Authorization: Bearer …, or X-Portal-Token. */
function vpnhoodiap_bearerToken(): ?string
{
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m)) {
        return $m[1];
    }
    $custom = (string) ($_SERVER['HTTP_X_PORTAL_TOKEN'] ?? '');
    return $custom !== '' ? $custom : null;
}

/**
 * Strip secrets from anything headed for the audit log: id tokens, purchase
 * proofs and session tokens must never be stored.
 */
function vpnhoodiap_redact(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    static $secretKeys = ['idtoken', 'proof', 'accesstoken', 'token'];
    $redacted = [];
    foreach ($value as $key => $item) {
        $redacted[$key] = is_string($key) && in_array(strtolower($key), $secretKeys, true)
            ? '[redacted]'
            : vpnhoodiap_redact($item);
    }
    return $redacted;
}

function vpnhoodiap_respond(int $status, mixed $data): never
{
    http_response_code($status);
    if ($status === 204 || $data === null) {
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** RFC 9457 problem+json. `code` is the stable contract; `detail` is prose. */
function vpnhoodiap_problem(int $status, string $code, string $detail): never
{
    http_response_code($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode([
        'type'   => 'https://docs.vpnhood.com/portal-api/errors/' . $code,
        'title'  => ucwords(str_replace('_', ' ', $code)),
        'status' => $status,
        'code'   => $code,
        'detail' => $detail,
    ]);
    exit;
}
