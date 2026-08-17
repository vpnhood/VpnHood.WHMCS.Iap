<?php
/**
 * sessions.test.php — SessionService against the real module tables inside
 * the deployed dev WHMCS: issue/resolve/revoke/expiry semantics, tokens
 * hashed at rest. All writes stay in mod_vpnhood_iap_* (the capsule rule).
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'Auth/SessionService.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
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
ok("fixture user #$userId created");

$sessions = new SessionService();

try {
    // -- issue + resolve ------------------------------------------------------
    $issued = $sessions->issue($userId);
    if (strlen($issued['token']) === 64 && ctype_xdigit($issued['token'])) {
        ok('issued token is 64 hex chars');
    } else {
        bad('unexpected token format: ' . $issued['token']);
    }

    $resolved = $sessions->resolve($issued['token']);
    if ((int) $resolved['id'] === $userId && $resolved['email'] === "$marker@vpnhood.test") {
        ok('token resolves to the issuing user');
    } else {
        bad('resolve returned the wrong user: ' . json_encode($resolved));
    }

    // -- the device's home store rides on the session -------------------------
    // GET /account prefers the subscription THIS device's store bills, and the
    // session is the only thing that knows which store that is.
    // array_key_exists, not ??: the answer under test IS null, which ?? cannot tell from absent
    if (array_key_exists('session_store', $resolved) && $resolved['session_store'] === null) {
        ok('a session issued without a store resolves to none — the account-wide choice serves');
    } else {
        bad('expected a null session_store, got: ' . var_export($resolved['session_store'] ?? 'ABSENT', true));
    }

    $appleSession = $sessions->issue($userId, 'appstore');
    $appleResolved = $sessions->resolve($appleSession['token']);
    if (($appleResolved['session_store'] ?? null) === 'appstore') {
        ok('the store the device signed in with survives on its session');
    } else {
        bad('session_store was not carried: ' . var_export($appleResolved['session_store'] ?? null, true));
    }
    $sessions->revoke($appleSession['token']);

    // -- hashed at rest -------------------------------------------------------
    $storedRaw = one($db, 'SELECT 1 x FROM mod_vpnhood_iap_sessions WHERE token_hash = ?', [$issued['token']]);
    $storedHash = one($db, 'SELECT 1 x FROM mod_vpnhood_iap_sessions WHERE token_hash = ?', [hash('sha256', $issued['token'])]);
    if ($storedRaw === null && $storedHash !== null) {
        ok('token is stored hashed, never in the clear');
    } else {
        bad('token storage is wrong (raw=' . json_encode($storedRaw) . ', hash=' . json_encode($storedHash) . ')');
    }

    // -- unknown / tampered ---------------------------------------------------
    foreach (['', str_repeat('a', 64), substr($issued['token'], 0, -2) . 'ff'] as $badToken) {
        try {
            $sessions->resolve($badToken);
            bad('bad token was accepted: ' . var_export($badToken, true));
        } catch (ApiException $e) {
            $e->getHttpStatus() === 401
                ? ok('bad token rejected with 401')
                : bad('bad token rejected with wrong status ' . $e->getHttpStatus());
        }
    }

    // -- revoke ---------------------------------------------------------------
    $sessions->revoke($issued['token']);
    try {
        $sessions->resolve($issued['token']);
        bad('revoked token still resolves');
    } catch (ApiException $e) {
        ok('revoked token no longer resolves');
    }
    $sessions->revoke($issued['token']); // idempotent
    ok('double revoke is harmless');

    // -- expiry ---------------------------------------------------------------
    $expired = $sessions->issue($userId);
    Capsule::table('mod_vpnhood_iap_sessions')
        ->where('token_hash', hash('sha256', $expired['token']))
        ->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
    try {
        $sessions->resolve($expired['token']);
        bad('expired token still resolves');
    } catch (ApiException $e) {
        ok('expired token no longer resolves');
    }

    // -- revokeAllForUser -----------------------------------------------------
    $a = $sessions->issue($userId);
    $b = $sessions->issue($userId);
    $sessions->revokeAllForUser($userId);
    $stillValid = 0;
    foreach ([$a, $b] as $s) {
        try {
            $sessions->resolve($s['token']);
            $stillValid++;
        } catch (ApiException $e) {
            // expected
        }
    }
    $stillValid === 0
        ? ok('revokeAllForUser kills every session')
        : bad("revokeAllForUser left $stillValid sessions alive");
} finally {
    // -- cleanup (module tables only) ----------------------------------------
    Capsule::table('mod_vpnhood_iap_sessions')->where('user_id', $userId)->delete();
    Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->delete();
    ok('fixture rows removed');
}

finish();
