<?php
/**
 * googleplay.test.php — GooglePlayApiClient (auth assertion, request shape,
 * idempotent acknowledge, voided pagination) and GooglePlayAdapter (state
 * mapping, RTDN parsing, Pub/Sub OIDC authentication) against a fake HTTP
 * transport and runtime-generated keys. No network.
 */

require_once IAP_LIB . '/Jwt.php';
require_once IAP_LIB . '/Stores/Dto/PurchaseRecord.php';
require_once IAP_LIB . '/Stores/Dto/StoreNotification.php';
require_once IAP_LIB . '/Stores/StoreAdapterInterface.php';
require_once IAP_LIB . '/Stores/GooglePlay/GooglePlayApiClient.php';
require_once IAP_LIB . '/Stores/GooglePlay/GooglePlayAdapter.php';

use WHMCS\Module\Addon\VpnHoodIap\Jwt;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\GooglePlay\GooglePlayAdapter;
use WHMCS\Module\Addon\VpnHoodIap\Stores\GooglePlay\GooglePlayApiClient;

$saKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($saKey, $saPrivatePem);
$saPublicPem = openssl_pkey_get_details($saKey)['key'];

const SA_EMAIL = 'publisher@test-project.iam.gserviceaccount.com';
const TOKEN_URI = 'https://oauth2.test/token';
const PACKAGE = 'com.vpnhood.connect.android';

function serviceAccount(string $privatePem): array
{
    return ['client_email' => SA_EMAIL, 'private_key' => $privatePem, 'token_uri' => TOKEN_URI];
}

/**
 * A scripted HTTP fake: records every request; answers TOKEN_URI with an
 * access token and everything else from the queue (or a default).
 */
function fakeHttp(array &$log, array $responses = []): callable
{
    return function (string $method, string $url, array $headers, ?string $body) use (&$log, &$responses): array {
        $log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($url === TOKEN_URI) {
            return ['status' => 200, 'body' => '', 'json' => ['access_token' => 'test-access-token', 'expires_in' => 3600]];
        }
        foreach ($responses as $i => $response) {
            if (str_contains($url, $response['match'])) {
                unset($responses[$i]);
                return $response['response'];
            }
        }
        return ['status' => 200, 'body' => '{}', 'json' => []];
    };
}

function activeSubscriptionDoc(): array
{
    return [
        'kind'                       => 'androidpublisher#subscriptionPurchaseV2',
        'latestOrderId'              => 'GPA.3333-4444-5555-66666',
        'subscriptionState'          => 'SUBSCRIPTION_STATE_ACTIVE',
        'acknowledgementState'       => 'ACKNOWLEDGEMENT_STATE_PENDING',
        'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'c0ffee00-0000-4000-8000-000000000001'],
        'lineItems'                  => [[
            'productId'        => 'vh_premium',
            'expiryTime'       => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400),
            'autoRenewingPlan' => ['autoRenewEnabled' => true],
            'offerDetails'     => ['basePlanId' => 'monthly'],
        ]],
    ];
}

// ------------------------------------------------------------- api client --

test('token exchange posts a valid RS256 assertion and caches the token', function () use ($saPrivatePem, $saPublicPem) {
    $log = [];
    $client = new GooglePlayApiClient(serviceAccount($saPrivatePem), PACKAGE, fakeHttp($log));
    $client->getSubscription('tok-1');
    $client->getSubscription('tok-2');

    $tokenCalls = array_values(array_filter($log, fn ($r) => $r['url'] === TOKEN_URI));
    assertSame(1, count($tokenCalls), 'token endpoint must be hit exactly once (cache)');

    parse_str((string) $tokenCalls[0]['body'], $form);
    assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
    $claims = Jwt::verifyRs256($form['assertion'], ['sa' => $saPublicPem]);
    assertSame(SA_EMAIL, $claims['iss']);
    assertSame(TOKEN_URI, $claims['aud']);
    assertSame('https://www.googleapis.com/auth/androidpublisher', $claims['scope']);

    $apiCalls = array_values(array_filter($log, fn ($r) => $r['url'] !== TOKEN_URI));
    assertSame('Bearer test-access-token', $apiCalls[0]['headers']['Authorization']);
    assertTrue(str_contains($apiCalls[0]['url'], '/applications/' . rawurlencode(PACKAGE) . '/purchases/subscriptionsv2/tokens/tok-1'), 'subscription URL shape');
});

test('non-2xx store responses surface the Google error message', function () use ($saPrivatePem) {
    $log = [];
    $http = fakeHttp($log, [[
        'match'    => 'subscriptionsv2',
        'response' => ['status' => 404, 'body' => '', 'json' => ['error' => ['message' => 'Purchase token not found.']]],
    ]]);
    $client = new GooglePlayApiClient(serviceAccount($saPrivatePem), PACKAGE, $http);
    assertThrows(fn () => $client->getSubscription('bad'), \RuntimeException::class, 'Purchase token not found');
});

test("acknowledge treats Google's 'already acknowledged' 400 as success", function () use ($saPrivatePem) {
    $log = [];
    $http = fakeHttp($log, [[
        'match'    => ':acknowledge',
        'response' => ['status' => 400, 'body' => '', 'json' => ['error' => ['message' => 'The purchase is already acknowledged.']]],
    ]]);
    $client = new GooglePlayApiClient(serviceAccount($saPrivatePem), PACKAGE, $http);
    $client->acknowledgeSubscription('vh_premium', 'tok'); // must not throw
    assertTrue(true);
});

test('voided purchases follows pagination', function () use ($saPrivatePem) {
    $log = [];
    $http = fakeHttp($log, [
        ['match' => 'voidedpurchases?startTime', 'response' => ['status' => 200, 'body' => '', 'json' => [
            'voidedPurchases' => [['purchaseToken' => 'v1']],
            'tokenPagination' => ['nextPageToken' => 'page2'],
        ]]],
        ['match' => 'token=page2', 'response' => ['status' => 200, 'body' => '', 'json' => [
            'voidedPurchases' => [['purchaseToken' => 'v2']],
        ]]],
    ]);
    $client = new GooglePlayApiClient(serviceAccount($saPrivatePem), PACKAGE, $http);
    $voided = $client->listVoidedPurchases(1000);
    assertSame(['v1', 'v2'], array_column($voided, 'purchaseToken'));
});

// ---------------------------------------------------------------- adapter --

function adapterWith(array $subscriptionDoc, string $saPrivatePem, array &$log): GooglePlayAdapter
{
    $http = fakeHttp($log, [[
        'match'    => 'subscriptionsv2',
        'response' => ['status' => 200, 'body' => '', 'json' => $subscriptionDoc],
    ]]);
    $factory = fn (array $app) => new GooglePlayApiClient(serviceAccount($saPrivatePem), PACKAGE, $http);
    return new GooglePlayAdapter($factory);
}

test('verifyPurchase maps an active subscription document', function () use ($saPrivatePem) {
    $log = [];
    $adapter = adapterWith(activeSubscriptionDoc(), $saPrivatePem, $log);
    $record = $adapter->verifyPurchase(['id' => 1], ['purchaseToken' => 'tok-A']);

    assertSame(PurchaseRecord::STATE_ACTIVE, $record->state);
    assertSame('tok-A', $record->purchaseKey);
    assertSame('GPA.3333-4444-5555-66666', $record->storeOrderId);
    assertSame('vh_premium', $record->storeProductId);
    assertSame('monthly', $record->basePlanId);
    assertSame('c0ffee00-0000-4000-8000-000000000001', $record->obfuscatedUid);
    assertSame(true, $record->autoRenewing);
    assertSame(false, $record->acknowledged);
    assertSame(false, $record->isTest);
    assertTrue($record->isEntitled(), 'active unexpired subscription is entitled');
});

test('subscription state table maps every documented state', function () use ($saPrivatePem) {
    $expectations = [
        'SUBSCRIPTION_STATE_ACTIVE'          => PurchaseRecord::STATE_ACTIVE,
        'SUBSCRIPTION_STATE_CANCELED'        => PurchaseRecord::STATE_CANCELED,
        'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => PurchaseRecord::STATE_IN_GRACE,
        'SUBSCRIPTION_STATE_ON_HOLD'         => PurchaseRecord::STATE_ON_HOLD,
        'SUBSCRIPTION_STATE_PAUSED'          => PurchaseRecord::STATE_PAUSED,
        'SUBSCRIPTION_STATE_EXPIRED'         => PurchaseRecord::STATE_EXPIRED,
        'SUBSCRIPTION_STATE_PENDING'         => PurchaseRecord::STATE_PENDING,
    ];
    foreach ($expectations as $googleState => $normalized) {
        $doc = activeSubscriptionDoc();
        $doc['subscriptionState'] = $googleState;
        $log = [];
        $record = adapterWith($doc, $saPrivatePem, $log)->verifyPurchase([], ['purchaseToken' => 't']);
        assertSame($normalized, $record->state, $googleState);
    }
});

test('canceled-but-unexpired stays entitled; on-hold does not', function () use ($saPrivatePem) {
    $canceled = activeSubscriptionDoc();
    $canceled['subscriptionState'] = 'SUBSCRIPTION_STATE_CANCELED';
    $log = [];
    assertTrue(adapterWith($canceled, $saPrivatePem, $log)->verifyPurchase([], ['purchaseToken' => 't'])->isEntitled());

    $onHold = activeSubscriptionDoc();
    $onHold['subscriptionState'] = 'SUBSCRIPTION_STATE_ON_HOLD';
    $log = [];
    assertSame(false, adapterWith($onHold, $saPrivatePem, $log)->verifyPurchase([], ['purchaseToken' => 't'])->isEntitled());
});

test('proof without purchaseToken is rejected', function () use ($saPrivatePem) {
    $log = [];
    $adapter = adapterWith(activeSubscriptionDoc(), $saPrivatePem, $log);
    assertThrows(fn () => $adapter->verifyPurchase([], ['productId' => 'x']), \RuntimeException::class, 'purchaseToken');
});

// -------------------------------------------------- notifications (RTDN) --

function oidcHeaders(string $privatePem, array $overrides = []): array
{
    $claims = array_merge([
        'iss'            => 'https://accounts.google.com',
        'aud'            => 'https://whmcs.test/webhook.php?store=googleplay&t=s3cret',
        'email'          => 'push@test-project.iam.gserviceaccount.com',
        'email_verified' => true,
        'exp'            => time() + 3600,
    ], $overrides);
    return ['authorization' => 'Bearer ' . Jwt::signRs256($claims, $privatePem, ['kid' => 'oidc'])];
}

function pushEnvelope(array $rtdn, string $messageId = 'msg-1'): string
{
    return json_encode([
        'message'      => ['messageId' => $messageId, 'data' => base64_encode(json_encode($rtdn))],
        'subscription' => 'projects/test/subscriptions/rtdn-push',
    ]);
}

function rtdnApp(): array
{
    return [
        'id'                     => 1,
        'package_name'           => PACKAGE,
        'pubsub_service_account' => 'push@test-project.iam.gserviceaccount.com',
        'webhook_url'            => 'https://whmcs.test/webhook.php?store=googleplay&t=s3cret',
    ];
}

$oidcKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($oidcKey, $oidcPrivatePem);
$oidcPublicPem = openssl_pkey_get_details($oidcKey)['key'];
$oidcAdapter = new GooglePlayAdapter(
    fn (array $app) => throw new \RuntimeException('api client must not be used for parsing'),
    fn (): array => ['oidc' => $oidcPublicPem]
);

test('authentic RTDN subscription notification parses and normalizes', function () use ($oidcAdapter, $oidcPrivatePem) {
    $rtdn = [
        'version'                  => '1.0',
        'packageName'              => PACKAGE,
        'eventTimeMillis'          => '1700000123456',
        'subscriptionNotification' => ['version' => '1.0', 'notificationType' => 4, 'purchaseToken' => 'tok-N', 'subscriptionId' => 'vh_premium'],
    ];
    $notification = $oidcAdapter->parseNotification(rtdnApp(), oidcHeaders($oidcPrivatePem), pushEnvelope($rtdn), []);
    assertSame(StoreNotification::PURCHASED, $notification->eventType);
    assertSame('msg-1', $notification->messageId);
    assertSame('tok-N', $notification->purchaseKey);
    assertSame('vh_premium', $notification->storeProductId);
    assertSame(PACKAGE, $notification->packageName);
    assertSame(1700000123, $notification->eventTimeUnix);
});

test('RTDN type map: renewed / canceled / revoked / expired / test', function () use ($oidcAdapter, $oidcPrivatePem) {
    $cases = [2 => StoreNotification::RENEWED, 3 => StoreNotification::CANCELED, 12 => StoreNotification::REVOKED, 13 => StoreNotification::EXPIRED];
    foreach ($cases as $type => $expected) {
        $rtdn = ['packageName' => PACKAGE, 'subscriptionNotification' => ['notificationType' => $type, 'purchaseToken' => 't']];
        $notification = $oidcAdapter->parseNotification(rtdnApp(), oidcHeaders($oidcPrivatePem), pushEnvelope($rtdn, "m-$type"), []);
        assertSame($expected, $notification->eventType, "type $type");
    }
    $test = ['packageName' => PACKAGE, 'testNotification' => ['version' => '1.0']];
    assertSame(
        StoreNotification::TEST,
        $oidcAdapter->parseNotification(rtdnApp(), oidcHeaders($oidcPrivatePem), pushEnvelope($test, 'm-t'), [])->eventType
    );
    $unknownType = ['packageName' => PACKAGE, 'subscriptionNotification' => ['notificationType' => 8, 'purchaseToken' => 't']];
    assertSame(
        StoreNotification::UNKNOWN,
        $oidcAdapter->parseNotification(rtdnApp(), oidcHeaders($oidcPrivatePem), pushEnvelope($unknownType, 'm-u'), [])->eventType
    );
});

test('push without a bearer token is rejected', function () use ($oidcAdapter) {
    assertThrows(
        fn () => $oidcAdapter->parseNotification(rtdnApp(), [], pushEnvelope(['packageName' => PACKAGE]), []),
        \RuntimeException::class,
        'bearer'
    );
});

test('push from the wrong service account is rejected', function () use ($oidcAdapter, $oidcPrivatePem) {
    assertThrows(
        fn () => $oidcAdapter->parseNotification(
            rtdnApp(),
            oidcHeaders($oidcPrivatePem, ['email' => 'attacker@evil.iam.gserviceaccount.com']),
            pushEnvelope(['packageName' => PACKAGE]),
            []
        ),
        \RuntimeException::class,
        'service account'
    );
});

test('push with a wrong audience is rejected when the endpoint URL is known', function () use ($oidcAdapter, $oidcPrivatePem) {
    assertThrows(
        fn () => $oidcAdapter->parseNotification(
            rtdnApp(),
            oidcHeaders($oidcPrivatePem, ['aud' => 'https://other-victim.example/webhook.php']),
            pushEnvelope(['packageName' => PACKAGE]),
            []
        ),
        \RuntimeException::class,
        'audience'
    );
});

test('push signed by an unknown key is rejected', function () use ($oidcAdapter) {
    $rogueKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rogueKey, $roguePem);
    assertThrows(
        fn () => $oidcAdapter->parseNotification(rtdnApp(), oidcHeaders($roguePem), pushEnvelope(['packageName' => PACKAGE]), []),
        \RuntimeException::class,
        'signature'
    );
});
