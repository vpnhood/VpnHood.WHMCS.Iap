<?php

/**
 * VpnHood! IAP — app-facing API entry point, implementing the Portal API
 * contract (backend-agnostic: no WHMCS concept ever appears on the wire).
 *
 * Public URL:
 *   https://<whmcs>/modules/addons/vpnhoodiap/api.php
 *
 * Request body: JSON  { "action": "...", ...params }
 * Auth (all actions except ping/auth.token): the opaque session token from
 * auth.token, sent as  Authorization: Bearer <token>  or  X-Portal-Token:
 * <token>  (the custom header survives proxies that strip Authorization).
 * Response:     JSON  { "success": true, "data": {...} }  or
 *               { "success": false, "error": "...", "errorCode": "..." }
 *
 * FAILS CLOSED: while the addon is not activated on this install, every request is
 * answered 404 — the module ships inside the hub and partner packages but must expose
 * nothing until an admin activates and configures it.
 *
 * (Endpoint skeleton follows modules/addons/vpnhoodpartnerhub/api.php.)
 */

use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Auth\GoogleIdentityProvider;
use WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountService;

// Bootstrap WHMCS (gives us Capsule, localAPI, models, etc.).
require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Jwt.php';
require_once __DIR__ . '/lib/IapRepository.php';
require_once __DIR__ . '/lib/Auth/IdentityProviderInterface.php';
require_once __DIR__ . '/lib/Auth/GoogleIdentityProvider.php';
require_once __DIR__ . '/lib/Auth/SessionService.php';
require_once __DIR__ . '/lib/Stores/Dto/PurchaseRecord.php';
require_once __DIR__ . '/lib/Stores/Dto/StoreNotification.php';
require_once __DIR__ . '/lib/Stores/StoreAdapterInterface.php';
require_once __DIR__ . '/lib/Stores/StoreAdapterRegistry.php';
require_once __DIR__ . '/lib/Stores/GooglePlay/GooglePlayApiClient.php';
require_once __DIR__ . '/lib/Stores/GooglePlay/GooglePlayAdapter.php';
require_once __DIR__ . '/lib/Provisioning/AccountService.php';
require_once __DIR__ . '/lib/Provisioning/ClientProvisioner.php';
require_once __DIR__ . '/lib/Provisioning/OrderProvisioner.php';
require_once __DIR__ . '/lib/Provisioning/DeliveryReader.php';
require_once __DIR__ . '/lib/Provisioning/EntitlementService.php';

header('Content-Type: application/json; charset=utf-8');

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$action = '';
$repo = null;
$body = null;

try {
    if (!IapRepository::isModuleActive()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Not found.']);
        exit;
    }
    $repo = new IapRepository();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new ApiException('Only POST is supported.', 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new ApiException('Request body must be valid JSON.', 400);
    }

    $action = (string) ($body['action'] ?? '');
    if ($action === '') {
        throw new ApiException('Missing "action".', 400);
    }

    // action dispatch. Unauthenticated actions are rate-limited per IP;
    // authenticated actions resolve the bearer session first.
    $data = match ($action) {
        'ping'            => vpnhoodiap_actionPing($repo, $remoteIp),
        'auth.token'      => vpnhoodiap_actionAuthToken($repo, $body, $remoteIp),
        'auth.revoke'     => vpnhoodiap_actionAuthRevoke(),
        'me.get'          => vpnhoodiap_actionMeGet($repo),
        'purchase.verify' => vpnhoodiap_actionPurchaseVerify($repo, $body, $remoteIp),
        'entitlement.get' => vpnhoodiap_actionEntitlementGet($repo),
        'plans.get'       => vpnhoodiap_actionPlansGet($repo, $body),
        default           => throw new ApiException("Unknown action: $action", 400),
    };

    $repo->log(null, $action, $remoteIp, 200, vpnhoodiap_redact($body), vpnhoodiap_redact($data));
    vpnhoodiap_respond(200, ['success' => true, 'data' => $data]);
} catch (ApiException $e) {
    $status = $e->getHttpStatus();
    $repo?->log(null, $action, $remoteIp, $status, vpnhoodiap_redact($body), $e->getMessage());
    vpnhoodiap_respond($status, ['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    logModuleCall('vpnhoodiap', 'api', $action, $e->getMessage(), $e->getTraceAsString());
    $repo?->log(null, $action, $remoteIp, 500, vpnhoodiap_redact($body), $e->getMessage());
    vpnhoodiap_respond(500, ['success' => false, 'error' => 'Internal error.']);
}

// ---------------------------------------------------------------- actions --

/** Health probe: proves the addon is active and the DB reachable. No data exposed. */
function vpnhoodiap_actionPing(IapRepository $repo, string $remoteIp): array
{
    if ($repo->requestCount($remoteIp, 'ping', 60) > 30) {
        throw new ApiException('Too many requests.', 429);
    }
    return ['status' => 'ok', 'time' => date('c')];
}

/**
 * Sign in with a provider id token; returns the opaque session token.
 *
 * { action: "auth.token", provider: "google", idToken: "...", packageName: "com..." }
 * → { accessToken, expiresAt, userId, account: { email, emailVerified }, state }
 *   state: "ok" | "email_unverified" (an existing WHMCS account holds this email
 *   but has not verified it — purchases will park until it is verified)
 */
function vpnhoodiap_actionAuthToken(IapRepository $repo, array $body, string $remoteIp): array
{
    if ($repo->requestCount($remoteIp, 'auth.token', 300) > 20) {
        throw new ApiException('Too many requests.', 429);
    }

    $provider = (string) ($body['provider'] ?? '');
    $idToken = (string) ($body['idToken'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    if ($idToken === '' || $packageName === '') {
        throw new ApiException('idToken and packageName are required.', 400);
    }
    $identityProvider = match ($provider) {
        'google' => new GoogleIdentityProvider(),
        default  => throw new ApiException("Unsupported sign-in provider: $provider", 400),
    };

    $app = $repo->findAppByPackageAnyStore($packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403);
    }
    $allowedAudiences = array_values(array_filter(array_map('trim', explode(',', (string) $app['oauth_client_ids']))));

    try {
        $identity = $identityProvider->verifyIdToken($idToken, $allowedAudiences);
    } catch (\RuntimeException $e) {
        // exact reason goes to the audit log; the client only learns "invalid"
        (new IapRepository())->log(null, 'auth.token', $remoteIp, 401, $packageName, $e->getMessage());
        throw new ApiException('Invalid sign-in token.', 401);
    }
    if (!$identity['emailVerified']) {
        // the IdP itself has not verified the mailbox — nothing to match on safely
        throw new ApiException('The signed-in email is not verified with the identity provider.', 403);
    }

    $user = $repo->findOrCreateUser($identityProvider->providerId(), $identity['subject'], $identity['email'], true);

    // attach gate: link an existing verified WHMCS account by email; new emails
    // stay unlinked until first purchase creates their client.
    $state = 'ok';
    if ($user['client_id'] === null) {
        $resolution = (new AccountService())->resolveClientForEmail($identity['email']);
        if ($resolution['clientId'] !== null) {
            $repo->linkUserClient((int) $user['id'], $resolution['clientId']);
        } elseif ($resolution['state'] === AccountService::STATE_EMAIL_UNVERIFIED) {
            $state = 'email_unverified';
        }
    }

    $session = (new SessionService())->issue((int) $user['id']);
    return [
        'accessToken' => $session['token'],
        'expiresAt'   => $session['expiresAt'],
        'userId'      => $user['external_uid'],
        'account'     => [
            'email'         => $user['email'],
            'emailVerified' => $state !== 'email_unverified',
        ],
        'state'       => $state,
    ];
}

/** Sign out: revoke the presented session token. Always succeeds. */
function vpnhoodiap_actionAuthRevoke(): array
{
    (new SessionService())->revoke(vpnhoodiap_bearerToken());
    return ['revoked' => true];
}

/** Current account snapshot for the signed-in user. */
function vpnhoodiap_actionMeGet(IapRepository $repo): array
{
    $user = (new SessionService())->resolve(vpnhoodiap_bearerToken());
    $state = 'ok';
    if ($user['client_id'] === null) {
        $resolution = (new AccountService())->resolveClientForEmail((string) $user['email']);
        if ($resolution['state'] === AccountService::STATE_EMAIL_UNVERIFIED) {
            $state = 'email_unverified';
        }
    }
    return [
        'userId'  => $user['external_uid'],
        'account' => [
            'email'         => $user['email'],
            'emailVerified' => $state !== 'email_unverified',
        ],
        'state'   => $state,
    ];
}

/**
 * The primary purchase flow: validate the store proof, provision, return the
 * access code — one synchronous call, no client polling.
 *
 * { action: "purchase.verify", store: "googleplay", packageName: "com...", proof: {...} }
 * → { state: "provisioned", accessCode, expiresAt, planId }
 *   or state: "pending" | "awaiting_email_verification" (no code yet)
 */
function vpnhoodiap_actionPurchaseVerify(IapRepository $repo, array $body, string $remoteIp): array
{
    $user = (new \WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService())->resolve(vpnhoodiap_bearerToken());
    if ($repo->requestCount($remoteIp, 'purchase.verify', 300) > 30) {
        throw new ApiException('Too many requests.', 429);
    }

    $store = (string) ($body['store'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    $proof = $body['proof'] ?? null;
    if ($store === '' || $packageName === '' || !is_array($proof)) {
        throw new ApiException('store, packageName and proof are required.', 400);
    }
    $app = $repo->findAppByPackageName($store, $packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403);
    }

    $adapter = \WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterRegistry::get($store);
    try {
        $record = $adapter->verifyPurchase($app, $proof);
    } catch (\RuntimeException $e) {
        $repo->log((int) $user['id'], 'purchase.verify', $remoteIp, 400, $packageName, $e->getMessage());
        throw new ApiException('The purchase could not be validated with the store.', 400);
    }

    return (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService($repo))
        ->redeem($app, $record, $user, $adapter);
}

/** Current entitlements of the signed-in user (latest provisioned purchase per plan). */
function vpnhoodiap_actionEntitlementGet(IapRepository $repo): array
{
    $user = (new \WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService())->resolve(vpnhoodiap_bearerToken());
    $rows = \WHMCS\Database\Capsule::table('mod_vpnhood_iap_purchases')
        ->where('user_id', (int) $user['id'])
        ->where('status', 'provisioned')
        ->orderByDesc('id')
        ->limit(10)
        ->get()->map(fn ($row) => (array) $row)->all();

    $reader = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\DeliveryReader();
    $entitlements = [];
    foreach ($rows as $row) {
        $expiry = $row['expiry_time'] !== null ? strtotime((string) $row['expiry_time']) : null;
        if ($expiry !== null && $expiry < time()) {
            continue;
        }
        $entitlements[] = [
            'state'      => 'provisioned',
            'accessCode' => $row['service_id'] !== null ? $reader->readAccessCode((int) $row['service_id']) : null,
            'expiresAt'  => $expiry !== null ? gmdate('c', $expiry) : null,
        ];
    }
    return ['entitlements' => $entitlements];
}

/**
 * The sellable plans for one app+store — WHMCS is the source of truth for
 * WHAT is sellable; the store prices it. Unmapped plans simply don't appear.
 */
function vpnhoodiap_actionPlansGet(IapRepository $repo, array $body): array
{
    $store = (string) ($body['store'] ?? '');
    $packageName = (string) ($body['packageName'] ?? '');
    if ($store === '' || $packageName === '') {
        throw new ApiException('store and packageName are required.', 400);
    }
    $app = $repo->findAppByPackageName($store, $packageName);
    if ($app === null) {
        throw new ApiException('Unknown application.', 403);
    }
    $plans = [];
    foreach ($repo->allProductMappings() as $mapping) {
        if ((int) $mapping['app_id'] !== (int) $app['id'] || !$mapping['enabled']) {
            continue;
        }
        $plans[] = [
            'planId'         => $mapping['store_base_plan_id'] !== ''
                ? $mapping['store_product_id'] . '/' . $mapping['store_base_plan_id']
                : $mapping['store_product_id'],
            'storeProductId' => $mapping['store_product_id'],
            'basePlanId'     => $mapping['store_base_plan_id'],
        ];
    }
    return ['plans' => $plans];
}

// ---------------------------------------------------------------- helpers --

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

function vpnhoodiap_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
