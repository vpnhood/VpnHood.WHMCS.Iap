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
 *   GET    /v1/system/status          service probe (no auth)
 *   POST   /v1/auth/sessions          sign in: provider id token, WHMCS password,
 *                                     or second-factor challenge completion (no auth)
 *   DELETE /v1/auth/sessions/current  sign out
 *   GET    /v1/account                the signed-in account: identity, THE one
 *                                     access code serving it, and the store
 *                                     subscription behind it — the whole snapshot
 *   GET    /v1/billing/products       the store product ids one app+store sells (no auth)
 *   POST   /v1/billing/purchases      redeem a store purchase; the account
 *                                     snapshot then carries what it delivered
 *
 * Every resource hangs off a controller, under a major-version segment. The
 * OpenAPI document is the deliberate exception, unversioned at the root because
 * tooling expects to find it there.
 *
 * The version segment is the escape hatch an app store makes necessary: a
 * published app can never be force-updated, so when the shape of this API has to
 * change incompatibly, /v2 is served beside /v1 and old installs keep working
 * untouched. It matches the major of the contract version reported by
 * GET /v1/system/status and by the OpenAPI document.
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
require_once __DIR__ . '/lib/Auth/PasswordSignInService.php';
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
    '/openapi.json'             => ['GET' => 'vpnhoodiap_getOpenApi'],
    '/v1/system/status'         => ['GET' => 'vpnhoodiap_getStatus'],
    '/v1/auth/sessions'         => ['POST' => 'vpnhoodiap_createSession'],
    '/v1/auth/sessions/current' => ['DELETE' => 'vpnhoodiap_deleteCurrentSession'],
    '/v1/account'               => ['GET' => 'vpnhoodiap_getAccount', 'DELETE' => 'vpnhoodiap_deleteAccount'],
    '/v1/billing/products'      => ['GET' => 'vpnhoodiap_listProducts'],
    '/v1/billing/purchases'     => ['POST' => 'vpnhoodiap_createPurchase'],
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

/** GET /v1/system/status — proves the addon is active and the DB reachable. No data exposed. */
function vpnhoodiap_getStatus(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 30, 60);
    return [200, ['status' => 'ok', 'api' => 'v1', 'time' => gmdate('c')]];
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
 * POST /v1/auth/sessions — sign in. Three request forms, one session concept:
 *
 *   { provider, idToken, packageName }         provider id token (Google/Apple)
 *   { email, password, packageName }           the WHMCS client-area password
 *   { challengeToken, code, packageName }      second-factor completion
 *
 * → 201 { accessToken, expiresAt, userId }
 * The password form may instead answer 200 { challenge } when a second factor
 * is due; the challenge completion may add newBackupCode when one was spent.
 */
function vpnhoodiap_createSession(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 20, 300);

    $body = $request['body'];
    if (array_key_exists('challengeToken', $body)) {
        return vpnhoodiap_passwordChallengeForm($repo, $request);
    }
    if (array_key_exists('email', $body) || array_key_exists('password', $body)) {
        return vpnhoodiap_passwordForm($repo, $request);
    }

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

    return [201, vpnhoodiap_sessionBody($user, (string) $app['store'])];
}

/**
 * The password form of POST /auth/sessions. Sign-in only: it never creates an
 * account (unlike the provider form, where a new email deliberately does) and
 * never touches WHMCS's own login pages. Unknown email and wrong password are
 * ONE answer — status, body and timing identical — so nothing here can be used
 * to scan which emails exist (`invalid_credentials`, and the per-address
 * lockout fires for nonexistent addresses exactly as for real ones).
 */
function vpnhoodiap_passwordForm(IapRepository $repo, array $request): array
{
    $body = $request['body'];
    $email = (string) ($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    if ($email === '' || $password === '' || $packageName === '') {
        throw new ApiException('email, password and packageName are required.', 400, 'bad_request');
    }
    $app = $repo->findAppByPackageAnyStore($packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403, 'unknown_app');
    }

    $service = new \WHMCS\Module\Addon\VpnHoodIap\Auth\PasswordSignInService($repo);
    $outcome = $service->signInWithPassword($email, $password, $packageName);
    if (isset($outcome['challenge'])) {
        // the password was right but a second factor is due: no session yet, so
        // 200 with the challenge — its token can do nothing but this completion
        return [200, ['challenge' => $outcome['challenge']]];
    }

    $user = $service->signInToModuleAccount($outcome['whmcsUser']);
    return [201, vpnhoodiap_sessionBody($user, (string) $app['store'])];
}

/**
 * The second-factor form of POST /auth/sessions: completes the challenge the
 * password form answered. Accepts the authenticator code or the WHMCS backup
 * code; a spent backup code is rotated and the replacement returned once as
 * `newBackupCode` — the app must show it, nothing ever shows it again.
 */
function vpnhoodiap_passwordChallengeForm(IapRepository $repo, array $request): array
{
    $body = $request['body'];
    $challengeToken = (string) ($body['challengeToken'] ?? '');
    $code = (string) ($body['code'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    if ($challengeToken === '' || $code === '' || $packageName === '') {
        throw new ApiException('challengeToken, code and packageName are required.', 400, 'bad_request');
    }
    $app = $repo->findAppByPackageAnyStore($packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403, 'unknown_app');
    }

    $service = new \WHMCS\Module\Addon\VpnHoodIap\Auth\PasswordSignInService($repo);
    $outcome = $service->completeChallenge($challengeToken, $code, $packageName);
    $user = $service->signInToModuleAccount($outcome['whmcsUser']);

    $result = vpnhoodiap_sessionBody($user, (string) $app['store']);
    if ($outcome['newBackupCode'] !== null) {
        $result['newBackupCode'] = $outcome['newBackupCode'];
    }
    return [201, $result];
}

/**
 * The one session-response shape every sign-in form returns. $store is the device's
 * home store, taken from the app it signed in with: the session remembers it so
 * GET /v1/account can prefer the subscription that store bills (lifecycle §8).
 */
function vpnhoodiap_sessionBody(array $user, ?string $store): array
{
    $session = (new SessionService())->issue((int) $user['id'], $store);
    // Identity and lifetime only — what this device may now do. Who the person is (email,
    // name) belongs to GET /v1/account and is read from there: an address here would be a
    // second copy of a MUTABLE value, frozen at sign-in and stale the day it is changed.
    return [
        'accessToken' => $session['token'],
        'expiresAt'   => $session['expiresAt'],
        'userId'      => $user['external_uid'],
    ];
}

/** DELETE /v1/auth/sessions/current — sign out. Idempotent: always 204. */
function vpnhoodiap_deleteCurrentSession(IapRepository $repo, array $request): array
{
    (new SessionService())->revoke(vpnhoodiap_bearerToken());
    return [204, null];
}

/**
 * GET /v1/account — the COMPLETE account snapshot, mapping the app's account model
 * 1:1: identity, THE one access code serving the account, and the store
 * subscription behind it when one exists. One call, one object. The server does
 * the ranking — an active subscription's code outranks the website choice — so
 * no device ever sees a list or picks a code (lifecycle §8).
 */
function vpnhoodiap_getAccount(IapRepository $repo, array $request): array
{
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());
    return [200, vpnhoodiap_accountSnapshot($repo, $user)];
}

/** The snapshot body: { userId, name, email, accessCodeInfo, subscription }. */
function vpnhoodiap_accountSnapshot(IapRepository $repo, array $user): array
{
    // the billing owner's name, when a WHMCS client is linked and carries one
    $name = null;
    if (!empty($user['client_id'])) {
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', (int) $user['client_id'])->first(['firstname', 'lastname']);
        if ($client !== null) {
            $name = trim($client->firstname . ' ' . $client->lastname) ?: null;
        }
    }

    // The store subscription serving the account: the newest provisioned, unexpired
    // purchase whose code is deliverable. Its code IS the account's code — it is what
    // the person is paying for right now. The price is the STORE's own charge for the
    // current period, not a catalogue price.
    //
    // THIS DEVICE'S OWN STORE COMES FIRST (lifecycle §8). One account can hold a
    // subscription in more than one store, and only the device's own store can manage,
    // renew or cancel the one it sold: handing an Android device an Apple subscription
    // would hide its Google one, refuse a Google purchase as "already premium", and
    // offer no way to manage either. So the home store's serving subscription is
    // preferred, and the account-wide newest is the fallback — for a device whose store
    // sold nothing, and for sessions issued before the session knew its store.
    $subscription = null;
    $accessCode = null;
    $reader = new DeliveryReader();
    $homeStore = $user['session_store'] ?? null;
    $rows = \WHMCS\Database\Capsule::table('mod_vpnhood_iap_purchases')
        ->where('user_id', (int) $user['id'])
        ->where('status', 'provisioned')
        ->orderByDesc('id')
        ->limit(10)
        ->get()->map(fn ($row) => (array) $row)->all();
    if ($homeStore !== null) {
        usort($rows, fn ($a, $b) => ((string) $b['store'] === $homeStore ? 1 : 0)
            <=> ((string) $a['store'] === $homeStore ? 1 : 0));
    }
    foreach ($rows as $row) {
        $expiry = $row['expiry_time'] !== null ? strtotime((string) $row['expiry_time']) : null;
        if (($expiry !== null && $expiry < time()) || $row['service_id'] === null) {
            continue;
        }
        $code = $reader->readAccessCode((int) $row['service_id']);
        if ($code === null) {
            continue; // not deliverable (partner outage) — try the next purchase
        }
        $expirationTime = $expiry !== null ? gmdate('c', $expiry) : null;
        $accessCode = ['accessCode' => $code, 'expirationTime' => $expirationTime];
        $subscription = [
            'storeId'        => (string) $row['store'],
            'createdTime'    => $row['created_at'] !== null
                ? gmdate('c', strtotime((string) $row['created_at']))
                : null,
            'expirationTime' => $expirationTime,
            'priceAmount'    => $row['store_amount'] !== null ? (float) $row['store_amount'] : null,
            'priceCurrency'  => $row['store_currency'],
            'billingPeriod'  => IapRepository::billingPeriodForService((int) $row['service_id']),
            'isAutoRenew'    => (bool) $row['auto_renewing'],
        ];
        break;
    }

    // No subscription: THE one website code fills in — chosen by the server and
    // recomputed on this very read (a dead choice is replaced by the next usable
    // code, lifecycle §8). No list ever crosses to a device.
    if ($accessCode === null) {
        $info = (new AccountKeyService($repo))->accessCodeInfoForUser($user);
        if ($info !== null) {
            $accessCode = ['accessCode' => $info['accessCode'], 'expirationTime' => $info['expiresAt']];
        }
    }

    return [
        'userId'       => $user['external_uid'],
        'name'         => $name,
        'email'        => $user['email'],
        'accessCodeInfo' => $accessCode,
        'subscription' => $subscription,
    ];
}

/**
 * DELETE /v1/account (Apple 5.1.1(v), Play account deletion, GDPR
 * Art. 17). The person is erased everywhere at once — sessions on every device,
 * sign-in identities, the account row — and the WHMCS client behind the retained
 * invoices is anonymized and closed. Nothing blocks it (lifecycle §8): web
 * billing is cancelled at the end of its paid period instead, stored payment
 * methods are dropped, and one final message carries the codes and warnings to
 * the address before it is erased. A store subscription is deliberately left
 * exactly as it is (lifecycle §8): signing in again brings it back by itself,
 * so cancelling it here would destroy the very thing a return depends on.
 * Running codes are left alone too: open gates with no personal data, already
 * paid for.
 */
function vpnhoodiap_deleteAccount(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 5, 300);
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());

    // collect everything the farewell message must carry BEFORE anything dies —
    // the confirmation screen warns, the mail delivers (lifecycle §5 steps 2–3)
    $farewell = vpnhoodiap_collectFarewell($repo, $user);
    (new AccountDeletionService())->deleteUser($user, [
        'keys'                 => $farewell['keys'],
        // bulk never reaches the wire (a merchant concept, not a code), but the
        // farewell message still says the delivered file cannot be served again
        'bulkOrders'           => (new AccountKeyService($repo))->bulkOrderCount($user),
        'subscriptionWarnings' => $farewell['subscriptionWarnings'],
    ]);
    return [204, null];
}

/**
 * Everything the farewell mail must carry (lifecycle §5 step 3): every code the
 * person paid for — website services AND the code behind each store purchase —
 * plus one warning line per subscription still open at a store. Feeds only the
 * mail: no preview endpoint exists, deliberately (§10 — the confirmation shows
 * no codes and no counts, so there is nothing for a device to fetch).
 */
function vpnhoodiap_collectFarewell(IapRepository $repo, array $user): array
{
    $keys = (new AccountKeyService($repo))->webKeysForUser($user);

    $warnings = [];
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
            continue; // fully over — nothing to send, nothing to warn about
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
        if ($autoRenewing) {
            // grace/hold: the store still holds the subscription open although access
            // stopped — the case most likely to charge again unexpectedly (§8)
            $warnings[] = $expired && $autoRenewing
                ? 'A subscription with a failed payment is still open at the store and may start charging again. '
                    . 'Cancel it in the store it was bought from.'
                : 'Deleting the account does not cancel the subscription — it may keep renewing until '
                    . 'cancelled in the store it was bought from.';
        }
    }

    return ['keys' => $keys, 'subscriptionWarnings' => array_values(array_unique($warnings))];
}

/**
 * POST /v1/billing/purchases — the primary purchase flow: validate the store proof
 * and provision. One synchronous call, no client polling.
 *
 * { storeId: "googleplay", packageName: "com...", proof: {...} }
 * → 201 "provisioned" | "pending"
 *
 * The response IS the state: once provisioned, the app refreshes GET /v1/account,
 * which is where the delivered code and the subscription already live — a copy
 * here would be a second source of truth for the same facts. The state is not
 * also encoded in the status line: "pending" is a fact about the STORE settling
 * a payment, not about what this request did to a resource, and a status code
 * saying it again is one more copy to keep true.
 *
 * Redeeming the same purchase again answers 201 again: the store purchase key
 * is the idempotency anchor, so a retry never double-orders.
 */
function vpnhoodiap_createPurchase(IapRepository $repo, array $request): array
{
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());
    vpnhoodiap_rateLimit($repo, $request, 30, 300);

    $body = $request['body'];
    $storeId = (string) ($body['storeId'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    $proof = $body['proof'] ?? null;
    if ($storeId === '' || $packageName === '' || !is_array($proof)) {
        throw new ApiException('storeId, packageName and proof are required.', 400, 'bad_request');
    }
    $app = $repo->findAppByPackageName($storeId, $packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403, 'unknown_app');
    }

    $adapter = StoreAdapterRegistry::get($storeId);
    try {
        $record = $adapter->verifyPurchase($app, $proof);
    } catch (\RuntimeException $e) {
        $repo->log((int) $user['id'], $request['route'], $request['ip'], 400, $packageName, $e->getMessage());
        throw new ApiException('The purchase could not be validated with the store.', 400, 'purchase_invalid');
    }

    $entitlement = (new EntitlementService($repo))->redeem($app, $record, $user, $adapter);
    return [201, $entitlement['state']];
}

/**
 * GET /v1/billing/products?store=&packageName= — the sellable store product ids for
 * one app+store. WHMCS is the source of truth for WHAT is sellable; the store
 * prices it. Unmapped plans simply don't appear.
 *
 * Products, not plans: the catalog maps one row per plan (product + base plan), but
 * a base plan is not something an app can act on — the store enumerates those within
 * a product by itself. So the rows are reduced to their DISTINCT product ids here
 * rather than shipping the repeats for every client to collapse the same way.
 *
 * No session: an app renders its plans page before anyone signs in, so gating this
 * would force every app to ship a hardcoded product list and drift from the catalog
 * it is mapped against. Nothing here is account-scoped, and the ids are public in
 * the store listing anyway — only WHAT this app sells, never WHO buys it.
 */
function vpnhoodiap_listProducts(IapRepository $repo, array $request): array
{
    vpnhoodiap_rateLimit($repo, $request, 30, 60);

    $storeId = (string) ($request['query']['store'] ?? '');
    $packageName = (string) ($request['query']['packageName'] ?? '');
    if ($storeId === '' || $packageName === '') {
        throw new ApiException('store and packageName are required.', 400, 'bad_request');
    }
    $app = $repo->findAppByPackageName($storeId, $packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403, 'unknown_app');
    }
    $items = [];
    foreach ($repo->allProductMappings() as $mapping) {
        if ((int) $mapping['app_id'] !== (int) $app['id'] || !$mapping['enabled']) {
            continue;
        }
        // the store product id alone: the app asks its store to price it, and the store
        // itself enumerates the base plans within a product — nothing else is consumed
        $items[] = (string) $mapping['store_product_id'];
    }
    // array_values: array_unique keeps the original keys, and a gap would encode as a
    // JSON object instead of the array this answers with
    return [200, array_values(array_unique($items))];
}

// ---------------------------------------------------------------- helpers --

/**
 * The resource path, e.g. "/v1/account". PATH_INFO is the normal source; ?path=
 * is the escape hatch for hosts that strip it (some nginx/php-fpm setups). A bare
 * root lands on the current version's status, so a human probing the base URL
 * gets an answer rather than a 404.
 */
function vpnhoodiap_requestPath(): string
{
    $path = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($path === '') {
        $path = (string) ($_GET['path'] ?? '');
    }
    $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '', '/');
    return $path === '/' ? '/v1/system/status' : $path;
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
    static $secretKeys = ['idtoken', 'proof', 'accesstoken', 'token', 'password', 'code', 'challengetoken', 'newbackupcode'];
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
