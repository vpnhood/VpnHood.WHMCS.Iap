<?php
/**
 * jwt.test.php — Jwt sign/verify/time-validity, including the classic JWT
 * forgery vectors (alg=none, HS256 key confusion, tampered payload, wrong
 * key). Key material is generated at runtime; nothing is committed.
 */

require_once IAP_LIB . '/Jwt.php';

use WHMCS\Module\Addon\VpnHoodIap\Jwt;

// -- runtime fixtures ---------------------------------------------------------
$keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($keyResource === false) {
    throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
}
openssl_pkey_export($keyResource, $privatePem);
$publicPem = openssl_pkey_get_details($keyResource)['key'];

// a second, unrelated key for negative tests
$otherResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($otherResource, $otherPrivatePem);
$otherPublicPem = openssl_pkey_get_details($otherResource)['key'];

// a self-signed certificate for the same key — Google's cert endpoint serves
// X.509 PEM certs, not bare public keys, so this path must work too
$csr = openssl_csr_new(['commonName' => 'vpnhoodiap-test'], $keyResource, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $keyResource, 1, ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);

$claims = ['iss' => 'https://accounts.google.com', 'aud' => 'client-1', 'sub' => 'user-1', 'exp' => time() + 3600];
$token = Jwt::signRs256($claims, $privatePem, ['kid' => 'key-a']);

// -- round trips --------------------------------------------------------------
test('sign/verify round trip with a bare public key', function () use ($token, $publicPem, $claims) {
    $verified = Jwt::verifyRs256($token, ['key-a' => $publicPem]);
    assertSame($claims['sub'], $verified['sub']);
    assertSame($claims['aud'], $verified['aud']);
});

test('verify accepts an X.509 certificate PEM (Google cert endpoint format)', function () use ($token, $certPem) {
    $verified = Jwt::verifyRs256($token, ['key-a' => $certPem]);
    assertSame('user-1', $verified['sub']);
});

test('kid routing: only the named key is tried when it exists', function () use ($token, $publicPem, $otherPublicPem) {
    // right key stored under the token's kid, wrong key under another — passes
    $verified = Jwt::verifyRs256($token, ['key-a' => $publicPem, 'key-b' => $otherPublicPem]);
    assertSame('user-1', $verified['sub']);
    // ONLY the wrong key under the token's kid — must fail even though the
    // right key sits in the set under a different kid
    assertThrows(
        fn () => Jwt::verifyRs256($token, ['key-a' => $otherPublicPem, 'key-b' => $publicPem]),
        \RuntimeException::class,
        'signature'
    );
});

test('unknown kid falls back to trying every key', function () use ($claims, $privatePem, $publicPem, $otherPublicPem) {
    $token = Jwt::signRs256($claims, $privatePem, ['kid' => 'rotated-away']);
    $verified = Jwt::verifyRs256($token, ['x' => $otherPublicPem, 'y' => $publicPem]);
    assertSame('user-1', $verified['sub']);
});

// -- forgery vectors ----------------------------------------------------------
test('tampered payload is rejected', function () use ($token, $publicPem) {
    [$h, $c, $s] = explode('.', $token);
    $forgedClaims = Jwt::base64UrlEncode(json_encode(['sub' => 'attacker', 'exp' => time() + 3600]));
    assertThrows(
        fn () => Jwt::verifyRs256("$h.$forgedClaims.$s", ['key-a' => $publicPem]),
        \RuntimeException::class,
        'signature'
    );
});

test("alg 'none' token is rejected before any cryptography", function () use ($publicPem) {
    $forged = Jwt::base64UrlEncode(json_encode(['alg' => 'none', 'typ' => 'JWT']))
        . '.' . Jwt::base64UrlEncode(json_encode(['sub' => 'attacker', 'exp' => time() + 3600]))
        . '.' . Jwt::base64UrlEncode('');
    assertThrows(
        fn () => Jwt::verifyRs256($forged, ['key-a' => $publicPem]),
        \RuntimeException::class,
        'algorithm'
    );
});

test('HS256 key-confusion token is rejected', function () use ($publicPem) {
    // classic attack: HMAC the token with the server's PUBLIC key as secret
    $signedPart = Jwt::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']))
        . '.' . Jwt::base64UrlEncode(json_encode(['sub' => 'attacker', 'exp' => time() + 3600]));
    $mac = hash_hmac('sha256', $signedPart, $publicPem, true);
    assertThrows(
        fn () => Jwt::verifyRs256($signedPart . '.' . Jwt::base64UrlEncode($mac), ['key-a' => $publicPem]),
        \RuntimeException::class,
        'algorithm'
    );
});

test('token signed by an unrelated key is rejected', function () use ($claims, $otherPrivatePem, $publicPem) {
    $forged = Jwt::signRs256($claims, $otherPrivatePem, ['kid' => 'key-a']);
    assertThrows(
        fn () => Jwt::verifyRs256($forged, ['key-a' => $publicPem]),
        \RuntimeException::class,
        'signature'
    );
});

test('malformed tokens are rejected', function () use ($publicPem) {
    assertThrows(fn () => Jwt::verifyRs256('only.two', ['k' => $publicPem]), \RuntimeException::class, 'segments');
    assertThrows(fn () => Jwt::verifyRs256('not base64!.b.c', ['k' => $publicPem]), \RuntimeException::class);
    $garbageJson = Jwt::base64UrlEncode('"just a string"');
    assertThrows(
        fn () => Jwt::verifyRs256("$garbageJson.$garbageJson.AA", ['k' => $publicPem]),
        \RuntimeException::class,
        'JSON'
    );
});

// -- time validity ------------------------------------------------------------
test('assertTimeValid accepts a live token and applies leeway', function () {
    Jwt::assertTimeValid(['exp' => 1000 + 60], 1000);
    Jwt::assertTimeValid(['exp' => 1000 - 100], 1000, 300); // expired inside leeway
    Jwt::assertTimeValid(['exp' => 2000, 'nbf' => 1100, 'iat' => 1100], 1000, 300); // future inside leeway
});

test('assertTimeValid rejects expired / not-yet-valid / missing-exp tokens', function () {
    assertThrows(fn () => Jwt::assertTimeValid(['exp' => 1000], 2000, 300), \RuntimeException::class, 'expired');
    assertThrows(fn () => Jwt::assertTimeValid(['exp' => 9000, 'nbf' => 5000], 1000, 300), \RuntimeException::class, 'not valid yet');
    assertThrows(fn () => Jwt::assertTimeValid(['exp' => 9000, 'iat' => 5000], 1000, 300), \RuntimeException::class, 'future');
    assertThrows(fn () => Jwt::assertTimeValid(['sub' => 'x'], 1000), \RuntimeException::class, 'expiry');
});

// -- base64url ---------------------------------------------------------------
test('base64url round trip and strict rejection', function () {
    $bytes = random_bytes(33);
    assertSame($bytes, Jwt::base64UrlDecode(Jwt::base64UrlEncode($bytes)));
    assertThrows(fn () => Jwt::base64UrlDecode('a b c!'), \RuntimeException::class, 'base64url');
});
