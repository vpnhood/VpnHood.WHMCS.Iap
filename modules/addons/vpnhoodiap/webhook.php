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
 * Processing failures answer 200 (recorded + cron-replayed) — stores retry on 5xx and
 * must never be given a reason to loop.
 */

use WHMCS\Module\Addon\VpnHoodIap\Controllers\NotificationController;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterRegistry;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Jwt.php';
require_once __DIR__ . '/lib/IapRepository.php';
require_once __DIR__ . '/lib/Auth/IdentityProviderInterface.php';
require_once __DIR__ . '/lib/Auth/GoogleIdentityProvider.php';
require_once __DIR__ . '/lib/Stores/Dto/PurchaseRecord.php';
require_once __DIR__ . '/lib/Stores/Dto/StoreNotification.php';
require_once __DIR__ . '/lib/Stores/StoreAdapterInterface.php';
require_once __DIR__ . '/lib/Stores/StoreAdapterRegistry.php';
require_once __DIR__ . '/lib/Stores/GooglePlay/GooglePlayApiClient.php';
require_once __DIR__ . '/lib/Stores/GooglePlay/GooglePlayAdapter.php';
require_once __DIR__ . '/lib/Jwk.php';
require_once __DIR__ . '/lib/Stores/AppStore/AppleJws.php';
require_once __DIR__ . '/lib/Stores/AppStore/AppStoreApiClient.php';
require_once __DIR__ . '/lib/Stores/AppStore/AppStoreAdapter.php';
require_once __DIR__ . '/lib/Provisioning/AccountService.php';
require_once __DIR__ . '/lib/Provisioning/ClientProvisioner.php';
require_once __DIR__ . '/lib/Provisioning/OrderProvisioner.php';
require_once __DIR__ . '/lib/Provisioning/DeliveryReader.php';
require_once __DIR__ . '/lib/Provisioning/EntitlementService.php';
require_once __DIR__ . '/lib/Provisioning/RenewalService.php';
require_once __DIR__ . '/lib/Controllers/NotificationController.php';

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
// the adapter pins the OIDC audience to this exact endpoint URL
$app['webhook_url'] = $repo->webhookUrl($app);

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
    }
}

$controller = new NotificationController($repo);
$result = $controller->handle(
    $app,
    StoreAdapterRegistry::get($store),
    $headers,
    file_get_contents('php://input') ?: '',
    $_GET
);

$repo->log(null, 'webhook', $remoteIp, $result['status'], null, $result['body']['data'] ?? $result['body']);
http_response_code($result['status']);
echo json_encode($result['body']);
