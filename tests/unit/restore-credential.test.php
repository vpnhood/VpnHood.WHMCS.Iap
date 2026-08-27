<?php
/**
 * restore-credential.test.php — the pure verification core of zero-tap
 * sign-in restoration: CBOR decoding, COSE→PEM, registration parsing and the
 * assertion ceremony. Vectors are synthesized at runtime with openssl (a real
 * P-256 key, a real ES256 signature); nothing is committed. The DB-backed
 * paths (challenge burn, storage) belong to the integration tests.
 */

require_once IAP_LIB . '/Jwt.php';
require_once IAP_LIB . '/Jwk.php';
require_once IAP_LIB . '/Cbor.php';
require_once IAP_LIB . '/Auth/RestoreCredentialService.php';

use WHMCS\Module\Addon\VpnHoodIap\Auth\RestoreCredentialService;
use WHMCS\Module\Addon\VpnHoodIap\Cbor;
use WHMCS\Module\Addon\VpnHoodIap\Jwk;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;

// -- tiny CBOR encoder (tests only — the module only ever decodes) ------------
function cborUint(int $value, int $majorType = 0): string
{
    $head = $majorType << 5;
    if ($value < 24) {
        return chr($head | $value);
    }
    if ($value < 256) {
        return chr($head | 24) . chr($value);
    }
    if ($value < 65536) {
        return chr($head | 25) . pack('n', $value);
    }
    return chr($head | 26) . pack('N', $value);
}

function cborInt(int $value): string
{
    return $value >= 0 ? cborUint($value) : cborUint(-1 - $value, 1);
}

function cborBytes(string $bytes): string
{
    return cborUint(strlen($bytes), 2) . $bytes;
}

function cborText(string $text): string
{
    return cborUint(strlen($text), 3) . $text;
}

/** @param array<int|string, string> $entries key => ALREADY-ENCODED value */
function cborMap(array $entries): string
{
    $out = cborUint(count($entries), 5);
    foreach ($entries as $key => $encodedValue) {
        $out .= (is_int($key) ? cborInt($key) : cborText($key)) . $encodedValue;
    }
    return $out;
}

// -- runtime fixtures ---------------------------------------------------------
$keyResource = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
if ($keyResource === false) {
    throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
}
$keyDetails = openssl_pkey_get_details($keyResource);
$publicPem = $keyDetails['key'];
$x = $keyDetails['ec']['x'];
$y = $keyDetails['ec']['y'];

$rpId = 'account.example.com';
$origin = 'android:apk-key-hash:UNIT_TEST_HASH';
$credentialId = random_bytes(16);
$credentialIdB64u = Jwt::base64UrlEncode($credentialId);

function makeAuthData(string $rpId, int $flags, int $signCount, string $attested = ''): string
{
    return hash('sha256', $rpId, true) . chr($flags) . pack('N', $signCount) . $attested;
}

function makeRegistrationResponse(string $rpId, string $credentialId, string $x, string $y,
    string $challenge, string $origin, string $fmt = 'none', ?string $wireId = null): string
{
    $coseKey = cborMap([1 => cborInt(2), 3 => cborInt(-7), -1 => cborInt(1), -2 => cborBytes($x), -3 => cborBytes($y)]);
    $attested = str_repeat("\x00", 16) . pack('n', strlen($credentialId)) . $credentialId . $coseKey;
    $authData = makeAuthData($rpId, 0x41, 0, $attested); // UP + AT
    $attestationObject = cborMap(['fmt' => cborText($fmt), 'attStmt' => cborMap([]), 'authData' => cborBytes($authData)]);
    $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => $origin]);
    return (string) json_encode([
        'id' => $wireId ?? Jwt::base64UrlEncode($credentialId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Jwt::base64UrlEncode($clientData),
            'attestationObject' => Jwt::base64UrlEncode($attestationObject),
        ],
    ]);
}

function makeAssertionResponse($keyResource, string $rpId, string $credentialIdB64u,
    string $challenge, string $origin, int $signCount = 7, ?string $tamperClientData = null): string
{
    $authData = makeAuthData($rpId, 0x00, $signCount); // silent: no UP, by design
    $clientData = $tamperClientData
        ?? (string) json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin]);
    openssl_sign($authData . hash('sha256', $clientData, true), $signature, $keyResource, OPENSSL_ALGO_SHA256);
    return (string) json_encode([
        'id' => $credentialIdB64u,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Jwt::base64UrlEncode($clientData),
            'authenticatorData' => Jwt::base64UrlEncode($authData),
            'signature' => Jwt::base64UrlEncode($signature),
        ],
    ]);
}

// -- Cbor ---------------------------------------------------------------------
test('Cbor decodes maps with negative keys, byte and text strings', function () {
    $encoded = cborMap([1 => cborInt(2), -2 => cborBytes("\x01\x02"), 'fmt' => cborText('none')]);
    $decoded = Cbor::decode($encoded);
    assertSame(2, $decoded[1]);
    assertSame("\x01\x02", $decoded[-2]);
    assertSame('none', $decoded['fmt']);
});

test('Cbor decodes nested arrays and multi-byte lengths', function () {
    $long = str_repeat('a', 300); // needs the 2-byte length form
    $decoded = Cbor::decode(cborMap(['list' => cborUint(2, 4) . cborInt(255) . cborText($long)]));
    assertSame(255, $decoded['list'][0]);
    assertSame($long, $decoded['list'][1]);
});

test('Cbor::decodeItem reports the consumed offset (the COSE-key-mid-buffer case)', function () {
    $first = cborMap([1 => cborInt(2)]);
    [$value, $offset] = Cbor::decodeItem($first . 'TRAILER', 0);
    assertSame(2, $value[1]);
    assertSame(strlen($first), $offset);
});

test('Cbor rejects truncation, trailing bytes and indefinite lengths', function () {
    assertThrows(fn () => Cbor::decode(substr(cborBytes('abcdef'), 0, 3)), \RuntimeException::class, 'truncated');
    assertThrows(fn () => Cbor::decode(cborInt(1) . cborInt(2)), \RuntimeException::class, 'trailing');
    assertThrows(fn () => Cbor::decode("\xbf"), \RuntimeException::class, 'additional info'); // indefinite map
});

// -- Jwk::ecP256ToPem ---------------------------------------------------------
test('ecP256ToPem reproduces exactly the SPKI openssl exports for the same key', function () use ($x, $y, $publicPem) {
    assertSame(trim($publicPem), trim(Jwk::ecP256ToPem($x, $y)));
});

test('ecP256ToPem rejects coordinates that do not fit the field', function () {
    assertThrows(fn () => Jwk::ecP256ToPem(str_repeat("\x11", 33), str_repeat("\x22", 32)),
        \RuntimeException::class, 'P-256');
    assertThrows(fn () => Jwk::ecP256ToPem('', str_repeat("\x22", 32)), \RuntimeException::class, 'P-256');
});

// -- registration parsing -----------------------------------------------------
test('parseRegistration extracts the credential and its key from a valid response', function () use (
    $rpId, $credentialId, $credentialIdB64u, $x, $y, $origin, $publicPem) {
    $response = makeRegistrationResponse($rpId, $credentialId, $x, $y, 'chal-1', $origin);
    $parsed = RestoreCredentialService::parseRegistration($response, $rpId);
    assertSame($credentialIdB64u, $parsed['credentialId']);
    assertSame(trim($publicPem), trim($parsed['publicKeyPem']));
    assertSame(0, $parsed['signCount']);
});

test('parseRegistration refuses a foreign rp, a non-none format and a mismatched wire id', function () use (
    $rpId, $credentialId, $x, $y, $origin) {
    $foreignRp = makeRegistrationResponse('evil.example.com', $credentialId, $x, $y, 'c', $origin);
    assertThrows(fn () => RestoreCredentialService::parseRegistration($foreignRp, $rpId),
        \RuntimeException::class, 'rpId');

    $packed = makeRegistrationResponse($rpId, $credentialId, $x, $y, 'c', $origin, fmt: 'packed');
    assertThrows(fn () => RestoreCredentialService::parseRegistration($packed, $rpId),
        \RuntimeException::class, 'none');

    $wrongId = makeRegistrationResponse($rpId, $credentialId, $x, $y, 'c', $origin,
        wireId: Jwt::base64UrlEncode('someone-else'));
    assertThrows(fn () => RestoreCredentialService::parseRegistration($wrongId, $rpId),
        \RuntimeException::class, 'id does not match');
});

test('parseClientData enforces the ceremony type and surfaces challenge and origin', function () use (
    $rpId, $credentialId, $x, $y, $origin) {
    $response = makeRegistrationResponse($rpId, $credentialId, $x, $y, 'chal-2', $origin);
    $clientData = RestoreCredentialService::parseClientData($response, 'webauthn.create');
    assertSame('chal-2', $clientData['challenge']);
    assertSame($origin, $clientData['origin']);
    assertThrows(fn () => RestoreCredentialService::parseClientData($response, 'webauthn.get'),
        \RuntimeException::class, 'webauthn.get');
});

// -- the assertion ceremony ---------------------------------------------------
test('verifyAssertion accepts a genuine silent assertion and returns its sign count', function () use (
    $keyResource, $rpId, $credentialIdB64u, $origin, $publicPem) {
    $assertion = makeAssertionResponse($keyResource, $rpId, $credentialIdB64u, 'chal-3', $origin, signCount: 7);
    assertSame(7, RestoreCredentialService::verifyAssertion($assertion, $publicPem, $rpId));
});

test('verifyAssertion refuses a tampered clientDataJSON', function () use (
    $keyResource, $rpId, $credentialIdB64u, $origin, $publicPem) {
    $assertion = makeAssertionResponse($keyResource, $rpId, $credentialIdB64u, 'chal-4', $origin);
    $decoded = json_decode($assertion, true);
    $clientData = json_decode(Jwt::base64UrlDecode($decoded['response']['clientDataJSON']), true);
    $clientData['challenge'] = 'a-challenge-the-attacker-wants';
    $decoded['response']['clientDataJSON'] = Jwt::base64UrlEncode((string) json_encode($clientData));
    assertThrows(fn () => RestoreCredentialService::verifyAssertion((string) json_encode($decoded), $publicPem, $rpId),
        \RuntimeException::class, 'signature');
});

test('verifyAssertion refuses a signature from a different key', function () use (
    $rpId, $credentialIdB64u, $origin, $publicPem) {
    $otherKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $assertion = makeAssertionResponse($otherKey, $rpId, $credentialIdB64u, 'chal-5', $origin);
    assertThrows(fn () => RestoreCredentialService::verifyAssertion($assertion, $publicPem, $rpId),
        \RuntimeException::class, 'signature');
});

test('verifyAssertion refuses an assertion for a different rp', function () use (
    $keyResource, $rpId, $credentialIdB64u, $origin, $publicPem) {
    $assertion = makeAssertionResponse($keyResource, 'evil.example.com', $credentialIdB64u, 'chal-6', $origin);
    assertThrows(fn () => RestoreCredentialService::verifyAssertion($assertion, $publicPem, $rpId),
        \RuntimeException::class, 'rpId');
});
