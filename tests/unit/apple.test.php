<?php
/**
 * apple.test.php — the Apple stack with runtime-generated key material:
 *  - Jwk: RSA JWK → PEM round trip proven by verifying a real signature
 *  - AppleIdentityProvider: sign-in token verification + Apple's stringly booleans
 *  - AppleJws: ES256 x5c chain verification against an injected pinned root
 *    (broken chain / wrong pin / tampered payload / alg confusion all rejected)
 *  - AppStoreApiClient: ES256 API token shape
 *  - AppStoreAdapter: statuses mapping + ASSN V2 notification type table
 */

require_once IAP_LIB . '/Jwt.php';
require_once IAP_LIB . '/Jwk.php';
require_once IAP_LIB . '/Auth/IdentityProviderInterface.php';
require_once IAP_LIB . '/Auth/AppleIdentityProvider.php';
require_once IAP_LIB . '/Stores/Dto/PurchaseRecord.php';
require_once IAP_LIB . '/Stores/Dto/StoreNotification.php';
require_once IAP_LIB . '/Stores/StoreAdapterInterface.php';
require_once IAP_LIB . '/Stores/AppStore/AppleJws.php';
require_once IAP_LIB . '/Stores/AppStore/AppStoreApiClient.php';
require_once IAP_LIB . '/Stores/AppStore/AppStoreAdapter.php';

use WHMCS\Module\Addon\VpnHoodIap\Auth\AppleIdentityProvider;
use WHMCS\Module\Addon\VpnHoodIap\Jwk;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;
use WHMCS\Module\Addon\VpnHoodIap\Stores\AppStore\AppleJws;
use WHMCS\Module\Addon\VpnHoodIap\Stores\AppStore\AppStoreAdapter;
use WHMCS\Module\Addon\VpnHoodIap\Stores\AppStore\AppStoreApiClient;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;

const BUNDLE_ID = 'com.vpnhood.connect.ios';

// ================================================================== Jwk ==

$rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsaKey, $rsaPrivatePem);
$rsaDetails = openssl_pkey_get_details($rsaKey);

test('Jwk: RSA JWK (n,e) converts to a PEM that verifies real signatures', function () use ($rsaDetails, $rsaPrivatePem) {
    $jwkPem = Jwk::rsaToPem(
        Jwt::base64UrlEncode($rsaDetails['rsa']['n']),
        Jwt::base64UrlEncode($rsaDetails['rsa']['e'])
    );
    $token = Jwt::signRs256(['sub' => 'jwk-proof', 'exp' => time() + 60], $rsaPrivatePem, ['kid' => 'k1']);
    $claims = Jwt::verifyRs256($token, ['k1' => $jwkPem]);
    assertSame('jwk-proof', $claims['sub']);
});

test('Jwk: a JWK set maps kid => PEM and rejects sets with no usable key', function () use ($rsaDetails) {
    $pems = Jwk::setToPems(['keys' => [
        ['kty' => 'RSA', 'kid' => 'a', 'n' => Jwt::base64UrlEncode($rsaDetails['rsa']['n']), 'e' => Jwt::base64UrlEncode($rsaDetails['rsa']['e'])],
        ['kty' => 'EC', 'kid' => 'ignored'],
    ]]);
    assertSame(['a'], array_keys($pems));
    assertThrows(fn () => Jwk::setToPems(['keys' => [['kty' => 'EC', 'kid' => 'x']]]), \RuntimeException::class, 'usable');
});

// ================================================ AppleIdentityProvider ==

function appleIdToken(string $privatePem, array $overrides = []): string
{
    $claims = array_merge([
        'iss'            => 'https://appleid.apple.com',
        'aud'            => BUNDLE_ID,
        'sub'            => '001234.abcdef1234567890.1234',
        'email'          => 'Hidden@PrivateRelay.appleid.com',
        'email_verified' => 'true', // Apple sends the STRING
        'exp'            => time() + 3600,
        'iat'            => time() - 60,
    ], $overrides);
    return Jwt::signRs256($claims, $privatePem, ['kid' => 'apple-k1']);
}

$appleKeysFetcher = fn (): array => ['apple-k1' => $rsaDetails['key']];

test('Apple sign-in token verifies; string email_verified normalizes to bool', function () use ($appleKeysFetcher, $rsaPrivatePem) {
    $provider = new AppleIdentityProvider($appleKeysFetcher);
    $identity = $provider->verifyIdToken(appleIdToken($rsaPrivatePem), [BUNDLE_ID]);
    assertSame('001234.abcdef1234567890.1234', $identity['subject']);
    assertSame('hidden@privaterelay.appleid.com', $identity['email']);
    assertSame(true, $identity['emailVerified']);
    assertSame(null, $identity['name']);
});

test('Apple sign-in rejects a foreign issuer and an unregistered bundle id', function () use ($appleKeysFetcher, $rsaPrivatePem) {
    $provider = new AppleIdentityProvider($appleKeysFetcher);
    assertThrows(
        fn () => $provider->verifyIdToken(appleIdToken($rsaPrivatePem, ['iss' => 'https://accounts.google.com']), [BUNDLE_ID]),
        \RuntimeException::class,
        'issuer'
    );
    assertThrows(
        fn () => $provider->verifyIdToken(appleIdToken($rsaPrivatePem), ['com.other.app']),
        \RuntimeException::class,
        'audience'
    );
});

// ========================================================== mini Apple CA ==

/**
 * Build a root + leaf EC (P-256) chain at runtime, mimicking Apple's x5c.
 * @return array{rootDerSha256:string, x5c:array, leafPrivatePem:string, rogueX5c:array}
 */
function makeChain(): array
{
    $config = ['digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];

    $rootKey = openssl_pkey_new($config);
    $rootCsr = openssl_csr_new(['commonName' => 'Test Apple Root CA'], $rootKey, $config);
    $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 3650, $config, 1);
    openssl_x509_export($rootCert, $rootPem);

    $leafKey = openssl_pkey_new($config);
    $leafCsr = openssl_csr_new(['commonName' => 'Test StoreKit Signing'], $leafKey, $config);
    $leafCert = openssl_csr_sign($leafCsr, $rootCert, $rootKey, 365, $config, 2);
    openssl_x509_export($leafCert, $leafPem);
    openssl_pkey_export($leafKey, $leafPrivatePem);

    // an unrelated self-signed cert for the broken-chain case
    $rogueKey = openssl_pkey_new($config);
    $rogueCsr = openssl_csr_new(['commonName' => 'Rogue'], $rogueKey, $config);
    $rogueCert = openssl_csr_sign($rogueCsr, null, $rogueKey, 365, $config, 3);
    openssl_x509_export($rogueCert, $roguePem);

    $toDer = fn (string $pem): string => base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
    return [
        'rootDerSha256'  => hash('sha256', $toDer($rootPem)),
        'x5c'            => [base64_encode($toDer($leafPem)), base64_encode($toDer($rootPem))],
        'leafPrivatePem' => $leafPrivatePem,
        'rogueX5c'       => [base64_encode($toDer($leafPem)), base64_encode($toDer($roguePem))],
    ];
}

$chain = makeChain();

function signAppleJws(array $payload, array $chain): string
{
    return AppleJws::signEs256($payload, $chain['leafPrivatePem'], ['x5c' => $chain['x5c']]);
}

// =============================================================== AppleJws ==

test('AppleJws verifies an ES256 JWS whose x5c chain ends at the pinned root', function () use ($chain) {
    $jws = signAppleJws(['transactionId' => 't-1', 'bundleId' => BUNDLE_ID], $chain);
    $payload = AppleJws::verify($jws, [$chain['rootDerSha256']]);
    assertSame('t-1', $payload['transactionId']);
});

test('AppleJws rejects an unpinned root, a broken chain, tampering and non-ES256', function () use ($chain) {
    $jws = signAppleJws(['transactionId' => 't-1'], $chain);

    assertThrows(fn () => AppleJws::verify($jws, [hash('sha256', 'some-other-root')]), \RuntimeException::class, 'pinned');

    $broken = $chain;
    $broken['x5c'] = $chain['rogueX5c']; // leaf not signed by this "root"
    assertThrows(
        fn () => AppleJws::verify(signAppleJws(['x' => 1], $broken), [$chain['rootDerSha256']]),
        \RuntimeException::class,
        'chain'
    );

    [$h, , $s] = explode('.', $jws);
    $forged = "$h." . Jwt::base64UrlEncode(json_encode(['transactionId' => 'attacker'])) . ".$s";
    assertThrows(fn () => AppleJws::verify($forged, [$chain['rootDerSha256']]), \RuntimeException::class, 'signature');

    $rs256 = Jwt::base64UrlEncode(json_encode(['alg' => 'RS256', 'x5c' => $chain['x5c']]))
        . '.' . Jwt::base64UrlEncode(json_encode(['x' => 1])) . '.' . Jwt::base64UrlEncode(str_repeat('a', 64));
    assertThrows(fn () => AppleJws::verify($rs256, [$chain['rootDerSha256']]), \RuntimeException::class, 'algorithm');
});

test('AppleJws raw<->DER ES256 signature forms round trip', function () {
    $raw = random_bytes(64);
    // normalize: high bits force DER padding both ways
    $roundTripped = AppleJws::derSignatureToRaw(AppleJws::rawSignatureToDer($raw));
    assertSame(bin2hex($raw), bin2hex($roundTripped));
});

// ======================================================= AppStoreApiClient ==

test('AppStoreApiClient authenticates with a well-formed ES256 token and falls back to sandbox on 404', function () {
    $ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($ecKey, $ecPrivatePem);
    $ecPublicPem = openssl_pkey_get_details($ecKey)['key'];

    $log = [];
    $http = function (string $method, string $url, array $headers, ?string $body) use (&$log): array {
        $log[] = ['url' => $url, 'headers' => $headers];
        if (str_contains($url, 'api.storekit.itunes.apple.com')) {
            return ['status' => 404, 'body' => '', 'json' => ['errorMessage' => 'Transaction id not found.']];
        }
        return ['status' => 200, 'body' => '', 'json' => ['data' => []]];
    };
    $client = new AppStoreApiClient(
        ['issuerId' => 'issuer-1', 'keyId' => 'KEY123', 'privateKey' => $ecPrivatePem],
        BUNDLE_ID,
        $http
    );
    $client->getSubscriptionStatuses('orig-1');

    assertSame(2, count($log), 'production then sandbox');
    assertTrue(str_contains($log[1]['url'], 'api.storekit-sandbox.itunes.apple.com'), 'sandbox fallback');

    // the bearer is a valid ES256 JWT with Apple's required claims
    $bearer = substr((string) $log[0]['headers']['Authorization'], 7);
    $parsed = Jwt::parse($bearer);
    assertSame('ES256', $parsed['header']['alg']);
    assertSame('KEY123', $parsed['header']['kid']);
    assertSame('issuer-1', $parsed['claims']['iss']);
    assertSame('appstoreconnect-v1', $parsed['claims']['aud']);
    assertSame(BUNDLE_ID, $parsed['claims']['bid']);
    $der = AppleJws::rawSignatureToDer(Jwt::base64UrlDecode(explode('.', $bearer)[2]));
    assertSame(1, openssl_verify($parsed['signedPart'], $der, $ecPublicPem, OPENSSL_ALGO_SHA256), 'token signature');
});

// ========================================================= AppStoreAdapter ==

function statusesDoc(array $chain, int $status, array $transactionOverrides = []): array
{
    $transaction = array_merge([
        'transactionId'         => '2000000999',
        'originalTransactionId' => '2000000001',
        'bundleId'              => BUNDLE_ID,
        'productId'             => 'vh.premium.monthly',
        'appAccountToken'       => 'C0FFEE00-0000-4000-8000-000000000001',
        'expiresDate'           => (time() + 30 * 86400) * 1000,
        'environment'           => 'Production',
        'price'                 => 9990,
        'currency'              => 'USD',
    ], $transactionOverrides);
    return [
        'bundleId' => BUNDLE_ID,
        'data'     => [[
            'subscriptionGroupIdentifier' => 'grp1',
            'lastTransactions'            => [[
                'originalTransactionId' => '2000000001',
                'status'                => $status,
                'signedTransactionInfo' => signAppleJws($transaction, $chain),
                'signedRenewalInfo'     => signAppleJws(['autoRenewStatus' => 1], $chain),
            ]],
        ]],
    ];
}

function appStoreAdapter(array $chain, array $statuses): AppStoreAdapter
{
    $fakeClient = new class($statuses) extends AppStoreApiClient {
        public function __construct(private array $statuses)
        {
            // bypass the parent ctor: no credentials needed for a canned client
        }

        public function getSubscriptionStatuses(string $originalTransactionId): array
        {
            return $this->statuses;
        }

        public function getTransactionInfo(string $transactionId): array
        {
            throw new \RuntimeException('not used');
        }
    };
    return new AppStoreAdapter(
        fn (array $app) => $fakeClient,
        fn (string $jws): array => AppleJws::verify($jws, [$chain['rootDerSha256']])
    );
}

test('verifyPurchase: SK2 JWS proof → statuses re-fetch → normalized record', function () use ($chain) {
    $adapter = appStoreAdapter($chain, statusesDoc($chain, 1));
    $proofJws = signAppleJws([
        'transactionId' => '2000000999', 'originalTransactionId' => '2000000001', 'bundleId' => BUNDLE_ID,
    ], $chain);

    $record = $adapter->verifyPurchase(['package_name' => BUNDLE_ID], ['jws' => $proofJws]);
    assertSame(PurchaseRecord::STATE_ACTIVE, $record->state);
    assertSame('2000000001', $record->purchaseKey, 'purchase key is the ORIGINAL transaction id');
    assertSame('2000000999', $record->storeOrderId, 'order id is the latest transaction id');
    assertSame('vh.premium.monthly', $record->storeProductId);
    assertSame('c0ffee00-0000-4000-8000-000000000001', $record->obfuscatedUid, 'appAccountToken lowercased');
    assertSame(true, $record->autoRenewing);
    assertSame(true, $record->acknowledged, 'Apple has no acknowledge — always true');
    assertSame('9.99', $record->amount);
    assertTrue($record->isEntitled());
});

test('verifyPurchase rejects a proof for a different bundle id', function () use ($chain) {
    $adapter = appStoreAdapter($chain, statusesDoc($chain, 1));
    $foreign = signAppleJws(['transactionId' => 't', 'bundleId' => 'com.other.app'], $chain);
    assertThrows(
        fn () => $adapter->verifyPurchase(['package_name' => BUNDLE_ID], ['jws' => $foreign]),
        \RuntimeException::class,
        'different app'
    );
});

test('Apple subscription status table maps to normalized states', function () use ($chain) {
    $expectations = [
        1 => PurchaseRecord::STATE_ACTIVE,
        2 => PurchaseRecord::STATE_EXPIRED,
        3 => PurchaseRecord::STATE_ON_HOLD,
        4 => PurchaseRecord::STATE_IN_GRACE,
        5 => PurchaseRecord::STATE_REVOKED,
    ];
    foreach ($expectations as $appleStatus => $normalized) {
        $adapter = appStoreAdapter($chain, statusesDoc($chain, $appleStatus));
        $record = $adapter->refresh(['package_name' => BUNDLE_ID], '2000000001', '');
        assertSame($normalized, $record->state, "status $appleStatus");
    }
});

test('ASSN V2 notification types map to the normalized table', function () use ($chain) {
    $adapter = appStoreAdapter($chain, statusesDoc($chain, 1));
    $cases = [
        ['SUBSCRIBED', 'INITIAL_BUY', StoreNotification::PURCHASED],
        ['DID_RENEW', '', StoreNotification::RENEWED],
        ['DID_RENEW', 'BILLING_RECOVERY', StoreNotification::RECOVERED],
        ['DID_FAIL_TO_RENEW', 'GRACE_PERIOD', StoreNotification::IN_GRACE],
        ['DID_FAIL_TO_RENEW', '', StoreNotification::ON_HOLD],
        ['DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_DISABLED', StoreNotification::CANCELED],
        ['DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_ENABLED', StoreNotification::RESTARTED],
        ['EXPIRED', 'VOLUNTARY', StoreNotification::EXPIRED],
        ['REFUND', '', StoreNotification::REVOKED],
        ['TEST', '', StoreNotification::TEST],
        ['PRICE_INCREASE', '', StoreNotification::UNKNOWN],
    ];
    foreach ($cases as [$type, $subtype, $expected]) {
        $payload = [
            'notificationType' => $type,
            'subtype'          => $subtype,
            'notificationUUID' => "uuid-$type-$subtype",
            'signedDate'       => 1700000123456,
            'data'             => [
                'bundleId'              => BUNDLE_ID,
                'signedTransactionInfo' => signAppleJws([
                    'originalTransactionId' => '2000000001', 'productId' => 'vh.premium.monthly', 'bundleId' => BUNDLE_ID,
                ], $chain),
            ],
        ];
        $body = json_encode(['signedPayload' => signAppleJws($payload, $chain)]);
        $notification = $adapter->parseNotification(['package_name' => BUNDLE_ID], [], $body, []);
        assertSame($expected, $notification->eventType, "$type/$subtype");
        assertSame('2000000001', $notification->purchaseKey);
        assertSame(BUNDLE_ID, $notification->packageName);
        assertSame(1700000123, $notification->eventTimeUnix);
    }
});

test('an unsigned or foreign-signed notification body is rejected', function () use ($chain) {
    $adapter = appStoreAdapter($chain, statusesDoc($chain, 1));
    assertThrows(
        fn () => $adapter->parseNotification(['package_name' => BUNDLE_ID], [], '{"foo":1}', []),
        \RuntimeException::class,
        'Notification'
    );

    $rogue = $chain;
    $rogue['x5c'] = $chain['rogueX5c'];
    $body = json_encode(['signedPayload' => signAppleJws(['notificationType' => 'TEST', 'notificationUUID' => 'u'], $rogue)]);
    assertThrows(
        fn () => $adapter->parseNotification(['package_name' => BUNDLE_ID], [], $body, []),
        \RuntimeException::class
    );
});
