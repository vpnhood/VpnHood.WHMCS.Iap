<?php

/**
 * VpnHood! IAP — store notification entry point (Google Play RTDN now; Apple ASSN later).
 *
 * Public URL (per app; the secret path token is generated with the app row):
 *   https://<whmcs>/modules/addons/vpnhoodiap/webhook.php?store=googleplay&t=<secret>
 *
 * Auth is two-layer, both required: the secret path token selects the app row, and the
 * store-native proof (Google Pub/Sub push OIDC JWT / Apple JWS) is verified by the store
 * adapter. The payload itself is only ever treated as a pointer — entitlement comes from
 * re-fetching the purchase from the store API, never from the notification body.
 *
 * FAILS CLOSED: 404 while the addon is not activated; 401 without a matching app token.
 */

use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/IapRepository.php';

header('Content-Type: application/json; charset=utf-8');

if (!IapRepository::isModuleActive()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found.']);
    exit;
}

$repo = new IapRepository();
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$store = (string) ($_GET['store'] ?? '');
$token = (string) ($_GET['t'] ?? '');

try {
    IapRepository::assertStore($store);
} catch (\Throwable $e) {
    $repo->log(null, 'webhook', $remoteIp, 404, $store, 'unknown store');
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found.']);
    exit;
}

$app = $repo->findAppByWebhookToken($store, $token);
if ($app === null) {
    $repo->log(null, 'webhook', $remoteIp, 401, $store, 'invalid webhook token');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

// The store adapters (NotificationController -> StoreAdapterRegistry) land with the
// purchase-pipeline pass. Until then this endpoint must not be wired to any live
// subscription: answer 501 so a premature configuration is loud, not silently swallowed.
$repo->log(null, 'webhook', $remoteIp, 501, substr(file_get_contents('php://input') ?: '', 0, 2000), 'store adapters not implemented yet');
http_response_code(501);
echo json_encode(['success' => false, 'error' => 'Store notification processing is not implemented yet.']);
