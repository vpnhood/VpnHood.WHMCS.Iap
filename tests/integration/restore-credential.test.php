<?php
/**
 * restore-credential.test.php — RestoreCredentialService against the real
 * module tables inside the deployed dev WHMCS: challenge issue/burn semantics,
 * key storage, the assertion → user resolution, replay refusal, the per-user
 * cap and deletion. Device responses are synthesized here with openssl exactly
 * like the unit suite; the cryptographic core itself is unit-tested — this
 * file is about the DB glue. All writes stay in mod_vpnhood_iap_* (the
 * capsule rule).
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'Jwt.php', 'Jwk.php', 'Cbor.php', 'Auth/RestoreCredentialService.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\Auth\RestoreCredentialService;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
if (!tableExists($db, 'mod_vpnhood_iap_restore_credentials') || !tableExists($db, 'mod_vpnhood_iap_restore_challenges')) {
    bad('restore-credential tables missing — WHMCS has not run the module upgrade yet');
    finish();
}

// -- tiny CBOR encoder + WebAuthn synthesis (mirrors the unit suite) ----------
function cborUint(int $value, int $majorType = 0): string
{
    $head = $majorType << 5;
    if ($value < 24) {
        return chr($head | $value);
    }
    if ($value < 256) {
        return chr($head | 24) . chr($value);
    }
    return chr($head | 25) . pack('n', $value);
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

function cborMap(array $entries): string
{
    $out = cborUint(count($entries), 5);
    foreach ($entries as $key => $encodedValue) {
        $out .= (is_int($key) ? cborInt($key) : cborText($key)) . $encodedValue;
    }
    return $out;
}

function makeRegistrationResponse(string $requestJson, $keyResource, string $credentialId, string $origin): string
{
    $options = json_decode($requestJson, true);
    $details = openssl_pkey_get_details($keyResource);
    $coseKey = cborMap([1 => cborInt(2), 3 => cborInt(-7), -1 => cborInt(1),
        -2 => cborBytes($details['ec']['x']), -3 => cborBytes($details['ec']['y'])]);
    $attested = str_repeat("\x00", 16) . pack('n', strlen($credentialId)) . $credentialId . $coseKey;
    $authData = hash('sha256', $options['rp']['id'], true) . chr(0x41) . pack('N', 0) . $attested;
    $attestationObject = cborMap(['fmt' => cborText('none'), 'attStmt' => cborMap([]), 'authData' => cborBytes($authData)]);
    $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin]);
    return (string) json_encode([
        'id' => Jwt::base64UrlEncode($credentialId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Jwt::base64UrlEncode($clientData),
            'attestationObject' => Jwt::base64UrlEncode($attestationObject),
        ],
    ]);
}

function makeAssertionResponse(string $requestJson, $keyResource, string $credentialId, string $origin,
    int $signCount = 1): string
{
    $options = json_decode($requestJson, true);
    $authData = hash('sha256', $options['rpId'], true) . chr(0x00) . pack('N', $signCount);
    $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin]);
    openssl_sign($authData . hash('sha256', $clientData, true), $signature, $keyResource, OPENSSL_ALGO_SHA256);
    return (string) json_encode([
        'id' => Jwt::base64UrlEncode($credentialId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Jwt::base64UrlEncode($clientData),
            'authenticatorData' => Jwt::base64UrlEncode($authData),
            'signature' => Jwt::base64UrlEncode($signature),
        ],
    ]);
}

// -- fixture user (module table, cleaned up below) ---------------------------
$marker = 'itest-' . bin2hex(random_bytes(4));
$userId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
    'provider'             => 'google',
    'provider_subject'     => $marker,
    'email'                => "$marker@vpnhood.test",
    'email_verified_claim' => 1,
    'external_uid'         => sprintf('%s-0000-4000-8000-000000000000', substr(md5($marker), 0, 8)),
    'created_at'           => date('Y-m-d H:i:s'),
    'updated_at'           => date('Y-m-d H:i:s'),
]);
$user = (array) Capsule::table('mod_vpnhood_iap_users')->find($userId);
ok("fixture user #$userId created");

$rpId = 'itest.vpnhood.test';
$origin = 'android:apk-key-hash:ITEST';
$service = new RestoreCredentialService($rpId);
$keyResource = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$credentialId = random_bytes(16);

try {
    // -- registration ---------------------------------------------------------
    $requestJson = $service->registrationOptions($user);
    $options = json_decode($requestJson, true);
    if (($options['rp']['id'] ?? '') === $rpId &&
        ($options['user']['id'] ?? '') === Jwt::base64UrlEncode($user['external_uid'])) {
        ok('registration options carry the rp and the stable account id');
    } else {
        bad('unexpected registration options: ' . $requestJson);
    }

    $registrationResponse = makeRegistrationResponse($requestJson, $keyResource, $credentialId, $origin);
    $storedId = $service->register($user, $registrationResponse);
    $row = Capsule::table('mod_vpnhood_iap_restore_credentials')->where('credential_id', $storedId)->first();
    if ($row !== null && (int) $row->user_id === $userId && str_contains($row->public_key_pem, 'BEGIN PUBLIC KEY')
        && $row->origin === $origin) {
        ok('registration stores the key, its origin and its owner');
    } else {
        bad('stored credential row is wrong: ' . json_encode($row));
    }

    try {
        $service->register($user, $registrationResponse);
        bad('a spent registration challenge was accepted again');
    } catch (\RuntimeException) {
        ok('a registration challenge is single-use');
    }

    // -- assertion → session user --------------------------------------------
    $assertionRequest = $service->assertionOptions();
    $assertion = makeAssertionResponse($assertionRequest, $keyResource, $credentialId, $origin, signCount: 3);
    $resolved = $service->signInUser($assertion);
    if ((int) $resolved['id'] === $userId) {
        ok('a genuine assertion resolves to the registering user');
    } else {
        bad('assertion resolved the wrong user: ' . json_encode($resolved));
    }
    $row = Capsule::table('mod_vpnhood_iap_restore_credentials')->where('credential_id', $storedId)->first();
    if ((int) $row->sign_count === 3 && $row->last_used_at !== null) {
        ok('sign count and last_used_at are recorded');
    } else {
        bad('credential row not touched: ' . json_encode($row));
    }

    try {
        $service->signInUser($assertion);
        bad('a replayed assertion was accepted');
    } catch (\RuntimeException) {
        ok('an assertion challenge is single-use (replay refused)');
    }

    // -- origin binding -------------------------------------------------------
    $foreign = makeAssertionResponse($service->assertionOptions(), $keyResource, $credentialId,
        'android:apk-key-hash:EVIL');
    try {
        $service->signInUser($foreign);
        bad('an assertion from a foreign origin was accepted');
    } catch (\RuntimeException) {
        ok('a foreign origin is refused');
    }

    // -- per-user cap ---------------------------------------------------------
    for ($i = 0; $i < 6; $i++) {
        $extraKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $service->register($user, makeRegistrationResponse($service->registrationOptions($user),
            $extraKey, random_bytes(16), $origin));
    }
    $count = Capsule::table('mod_vpnhood_iap_restore_credentials')->where('user_id', $userId)->count();
    if ($count === 5) {
        ok('the per-user cap keeps the newest 5 keys');
    } else {
        bad("expected 5 credentials after the cap, got $count");
    }
    if (Capsule::table('mod_vpnhood_iap_restore_credentials')->where('credential_id', $storedId)->first() === null) {
        ok('the oldest key (the first one) fell off');
    } else {
        bad('the first key survived the cap');
    }

    // -- deletion -------------------------------------------------------------
    $lastId = (string) Capsule::table('mod_vpnhood_iap_restore_credentials')
        ->where('user_id', $userId)->orderByDesc('id')->value('credential_id');
    $service->deleteCredential($user, $lastId);
    if (Capsule::table('mod_vpnhood_iap_restore_credentials')->where('credential_id', $lastId)->first() === null) {
        ok('a device retires its own key');
    } else {
        bad('deleteCredential left the row behind');
    }
    $service->deleteCredential($user, $lastId); // idempotent
    ok('deleting an already-gone key is a no-op');
} finally {
    // -- cleanup --------------------------------------------------------------
    Capsule::table('mod_vpnhood_iap_restore_credentials')->where('user_id', $userId)->delete();
    Capsule::table('mod_vpnhood_iap_restore_challenges')->where('user_id', $userId)->delete();
    Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->delete();
    ok('fixture rows cleaned up');
}

finish();
