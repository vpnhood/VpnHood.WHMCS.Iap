<?php

/**
 * VpnHood! IAP — app-facing API entry point.
 *
 * Public URL:
 *   https://<whmcs>/modules/addons/vpnhoodiap/api.php
 *
 * Request body: JSON  { "action": "...", ...params }
 * Auth header (all actions except signIn/ping): X-AppStore-Token: <opaque session token>
 * Response:     JSON  { "success": true, "data": {...} }  or
 *               { "success": false, "error": "..." }
 *
 * FAILS CLOSED: while the addon is not activated on this install, every request is
 * answered 404 — the module ships inside the hub and partner packages but must expose
 * nothing until an admin activates and configures it.
 *
 * (Endpoint skeleton follows modules/addons/vpnhoodpartnerhub/api.php.)
 */

use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

// Bootstrap WHMCS (gives us Capsule, localAPI, models, etc.).
require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/IapRepository.php';

header('Content-Type: application/json; charset=utf-8');

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$action = '';
$repo = null;

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

    // action dispatch. Unauthenticated actions are rate-limited per IP; authenticated
    // actions resolve the session first (AppApiController arrives with the sign-in pass).
    $data = match ($action) {
        'ping' => vpnhoodiap_actionPing($repo, $remoteIp),
        default => throw new ApiException("Unknown action: $action", 400),
    };

    $repo->log(null, $action, $remoteIp, 200, $body, $data);
    vpnhoodiap_respond(200, ['success' => true, 'data' => $data]);
} catch (ApiException $e) {
    $status = $e->getHttpStatus();
    $repo?->log(null, $action, $remoteIp, $status, $raw ?? null, $e->getMessage());
    vpnhoodiap_respond($status, ['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    logModuleCall('vpnhoodiap', 'api', $action, $e->getMessage(), $e->getTraceAsString());
    $repo?->log(null, $action, $remoteIp, 500, $raw ?? null, $e->getMessage());
    vpnhoodiap_respond(500, ['success' => false, 'error' => 'Internal error.']);
}

/** Health probe: proves the addon is active and the DB reachable. No data exposed. */
function vpnhoodiap_actionPing(IapRepository $repo, string $remoteIp): array
{
    if ($repo->requestCount($remoteIp, 'ping', 60) > 30) {
        throw new ApiException('Too many requests.', 429);
    }
    return ['status' => 'ok', 'time' => date('c')];
}

function vpnhoodiap_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
