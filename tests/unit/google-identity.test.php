<?php
/**
 * google-identity.test.php — GoogleIdentityProvider end-to-end against a
 * runtime-generated key injected as the "Google certs", so the whole
 * pipeline (signature → time → issuer → audience → claims) runs with no
 * network.
 */

require_once IAP_LIB . '/Jwt.php';
require_once IAP_LIB . '/Auth/IdentityProviderInterface.php';
require_once IAP_LIB . '/Auth/GoogleIdentityProvider.php';

use WHMCS\Module\Addon\VpnHoodIap\Auth\GoogleIdentityProvider;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;

$keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($keyResource, $privatePem);
$publicPem = openssl_pkey_get_details($keyResource)['key'];

$certsFetcher = fn (): array => ['g-kid' => $publicPem];
$now = 1_700_000_000;

/** @return string a signed Google-style id token with claim overrides */
function googleIdToken(string $privatePem, int $now, array $overrides = []): string
{
    $claims = array_merge([
        'iss'            => 'https://accounts.google.com',
        'aud'            => 'client-id-1.apps.googleusercontent.com',
        'sub'            => '10769150350006150715113082367',
        'email'          => 'Buyer@Example.com',
        'email_verified' => true,
        'name'           => 'Test Buyer',
        'iat'            => $now - 60,
        'exp'            => $now + 3600,
    ], $overrides);
    return Jwt::signRs256($claims, $privatePem, ['kid' => 'g-kid']);
}

test('valid Google id token verifies and normalizes (email lowercased)', function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    $identity = $provider->verifyIdToken(
        googleIdToken($privatePem, $now),
        ['other-client', 'client-id-1.apps.googleusercontent.com']
    );
    assertSame('10769150350006150715113082367', $identity['subject']);
    assertSame('buyer@example.com', $identity['email']);
    assertSame(true, $identity['emailVerified']);
    assertSame('Test Buyer', $identity['name']);
});

test("issuer 'accounts.google.com' (no scheme) is also accepted", function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    $identity = $provider->verifyIdToken(
        googleIdToken($privatePem, $now, ['iss' => 'accounts.google.com']),
        ['client-id-1.apps.googleusercontent.com']
    );
    assertSame('buyer@example.com', $identity['email']);
});

test('unregistered audience is rejected', function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    assertThrows(
        fn () => $provider->verifyIdToken(googleIdToken($privatePem, $now), ['some-other-app']),
        \RuntimeException::class,
        'audience'
    );
});

test('foreign issuer is rejected', function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    assertThrows(
        fn () => $provider->verifyIdToken(
            googleIdToken($privatePem, $now, ['iss' => 'https://evil.example.com']),
            ['client-id-1.apps.googleusercontent.com']
        ),
        \RuntimeException::class,
        'issuer'
    );
});

test('expired token is rejected', function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    assertThrows(
        fn () => $provider->verifyIdToken(
            googleIdToken($privatePem, $now, ['exp' => $now - 3600]),
            ['client-id-1.apps.googleusercontent.com']
        ),
        \RuntimeException::class,
        'expired'
    );
});

test('token without an email claim is rejected', function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    assertThrows(
        fn () => $provider->verifyIdToken(
            googleIdToken($privatePem, $now, ['email' => '']),
            ['client-id-1.apps.googleusercontent.com']
        ),
        \RuntimeException::class,
        'email'
    );
});

test('empty audience allowlist (unconfigured app) is rejected before any crypto', function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    assertThrows(
        fn () => $provider->verifyIdToken(googleIdToken($privatePem, $now), []),
        \RuntimeException::class,
        'client ids'
    );
});

test('email_verified=false is surfaced, not hidden', function () use ($certsFetcher, $privatePem, $now) {
    $provider = new GoogleIdentityProvider($certsFetcher, $now);
    $identity = $provider->verifyIdToken(
        googleIdToken($privatePem, $now, ['email_verified' => false]),
        ['client-id-1.apps.googleusercontent.com']
    );
    assertSame(false, $identity['emailVerified']);
});
