<?php
/**
 * password-login.test.php — the password grant (portal sign-in without a WHMCS
 * page) against the deployed dev WHMCS. Pins the rules that make it safe:
 *
 *   - sign-in only: it NEVER creates an account — a pure web client gets the
 *     app-side handle of its EXISTING client, bound from birth; repeat sign-in
 *     lands on the same row;
 *   - anti-enumeration: unknown email and wrong password are ONE answer (same
 *     code, same message), and the cooldown fires for correct passwords and
 *     nonexistent addresses alike — then ends by itself, never extended by
 *     hammering;
 *   - the squatting guard: an unverified WHMCS email never joins an existing
 *     app account — it gets its own client-bound handle instead;
 *   - the second factor: TOTP verified through WHMCS's machinery (their replay
 *     guard included), backup code on the same step, rotation on use, and the
 *     challenge token dies by use, by attempts and by package mismatch.
 *
 * Writes: localAPI (AddClient), module tables, and WHMCS's own two-factor
 * activation for the fixtures — reverted in cleanup.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'IapRepository.php', 'Auth/SessionService.php', 'Auth/PasswordSignInService.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Auth\PasswordSignInService;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\User\User;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
if (!tableExists($db, 'mod_vpnhood_iap_login_challenges') || !tableExists($db, 'mod_vpnhood_iap_login_attempts')) {
    bad('login tables missing — open the addon admin page so _upgrade() (1.0.15) runs');
    finish();
}
ok('1.0.15 login tables exist');

const API_URL = 'https://whmcs-dev.vpnhood.com/modules/addons/vpnhoodiap/api.php/v1';

$marker = 'pwtest-' . bin2hex(random_bytes(4));
$testStart = date('Y-m-d H:i:s');
$clientIds = [];
$emailHashes = [];

function makeClient(string $email, string $password): int
{
    $result = localAPI('AddClient', [
        'firstname'      => 'Password',
        'lastname'       => 'Test',
        'email'          => $email,
        'password2'      => $password,
        'country'        => 'US',
        'skipvalidation' => true,
        'noemail'        => true,
    ]);
    if (($result['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddClient failed: ' . json_encode($result));
    }
    return (int) $result['clientid'];
}

function emailHash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

/** RFC 6238, 6 digits, 30 s — matches WHMCS's totp module (proven by spike). */
function b32decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($b32)) as $ch) {
        $v = strpos($alphabet, $ch);
        if ($v !== false) {
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totpCode(string $secret, int $t): string
{
    $counter = pack('N2', 0, intdiv($t, 30));
    $hash = hash_hmac('sha1', $counter, b32decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $num = (unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff) % 1000000;
    return str_pad((string) $num, 6, '0', STR_PAD_LEFT);
}

/** Never let a 30-second window edge split a test in two. */
function waitOutWindowEdge(): void
{
    $remain = 30 - (time() % 30);
    if ($remain < 4) {
        sleep($remain);
    }
}

function httpJson(string $method, string $path, ?array $body, ?string $token = null): array
{
    $curl = curl_init(API_URL . $path);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$status, json_decode($raw, true), $raw];
}

$repo = new IapRepository();
$service = new PasswordSignInService($repo);

$pwEmail = "pw-$marker@vpnhood.test";
$pwPassword = 'Pw-' . bin2hex(random_bytes(8)) . '!a';
$mfaEmail = "mfa-$marker@vpnhood.test";
$mfaPassword = 'Mfa-' . bin2hex(random_bytes(8)) . '!a';
$squatEmail = "squat-$marker@vpnhood.test";
$squatPassword = 'Squat-' . bin2hex(random_bytes(8)) . '!a';
$emailHashes = [emailHash($pwEmail), emailHash($mfaEmail), emailHash($squatEmail)];

$tfa = null;
$mfaUser = null;

try {
    // ---------------------------------------------------------------- fixtures
    $pwClientId = makeClient($pwEmail, $pwPassword);
    $mfaClientId = makeClient($mfaEmail, $mfaPassword);
    $clientIds = [$pwClientId, $mfaClientId];
    ok("fixture clients #$pwClientId (plain) and #$mfaClientId (mfa) created");

    // ------------------------------------------------- happy path, no 2FA
    $outcome = $service->signInWithPassword($pwEmail, $pwPassword, 'com.vpnhood.test');
    isset($outcome['whmcsUser'])
        ? ok('correct password answers a user, no challenge (2FA off)')
        : bad('expected a user outcome: ' . json_encode(array_keys($outcome)));

    $before = (int) Capsule::table('mod_vpnhood_iap_users')->where('email', $pwEmail)->count();
    $before === 0
        ? ok('no app account exists yet for the web client')
        : bad("expected no app account before first sign-in, found $before");

    $user = $service->signInToModuleAccount($outcome['whmcsUser']);
    ((int) $user['client_id'] === $pwClientId && $user['provider'] === 'whmcs')
        ? ok('first sign-in created the handle of the EXISTING client, bound from birth')
        : bad('handle is wrong: ' . json_encode([$user['client_id'], $user['provider']]));

    // ------------------------------------------------- idempotent: no second row
    $again = $service->signInToModuleAccount($service->signInWithPassword($pwEmail, $pwPassword, 'com.vpnhood.test')['whmcsUser']);
    ((int) $again['id'] === (int) $user['id'])
        ? ok('repeat sign-in lands on the same account — nothing new is created')
        : bad("repeat sign-in made a different account: #{$again['id']} vs #{$user['id']}");
    $rows = (int) Capsule::table('mod_vpnhood_iap_users')->where('email', $pwEmail)->count();
    $rows === 1
        ? ok('exactly one app account for the address after two sign-ins')
        : bad("$rows app accounts after two sign-ins");

    // ------------------------------------------------- anti-enumeration
    $wrongPw = null;
    try {
        $service->signInWithPassword($pwEmail, 'definitely-wrong', 'com.vpnhood.test');
        bad('wrong password was accepted');
    } catch (ApiException $e) {
        $wrongPw = $e;
        ($e->getHttpStatus() === 401 && $e->getErrorCode() === 'invalid_credentials')
            ? ok('wrong password → 401 invalid_credentials')
            : bad('wrong password → ' . $e->getHttpStatus() . ' ' . $e->getErrorCode());
    }
    try {
        $service->signInWithPassword("ghost-$marker@vpnhood.test", 'whatever', 'com.vpnhood.test');
        bad('unknown email was accepted');
    } catch (ApiException $e) {
        ($e->getHttpStatus() === 401 && $e->getErrorCode() === 'invalid_credentials')
            ? ok('unknown email → 401 invalid_credentials')
            : bad('unknown email → ' . $e->getHttpStatus() . ' ' . $e->getErrorCode());
        ($wrongPw !== null && $e->getMessage() === $wrongPw->getMessage())
            ? ok('unknown email and wrong password are ONE answer — identical message')
            : bad('the two answers differ — enumeration is possible');
    }
    $emailHashes[] = emailHash("ghost-$marker@vpnhood.test");

    // ------------------------------------------------- cooldown, nonexistent address
    $ghost = "cooled-$marker@vpnhood.test";
    $emailHashes[] = emailHash($ghost);
    for ($i = 0; $i < 5; $i++) {
        try {
            $service->signInWithPassword($ghost, 'x', 'com.vpnhood.test');
        } catch (ApiException) {
        }
    }
    try {
        $service->signInWithPassword($ghost, 'x', 'com.vpnhood.test');
        bad('6th attempt on a hammered NONEXISTENT address was not cooled down');
    } catch (ApiException $e) {
        ($e->getHttpStatus() === 429 && $e->getErrorCode() === 'too_many_attempts')
            ? ok('nonexistent address cools down exactly like a real one')
            : bad('hammered ghost → ' . $e->getHttpStatus() . ' ' . $e->getErrorCode());
    }

    // the cooldown is bounded: refused attempts are NOT recorded, so hammering
    // during the wait cannot extend it
    try {
        $service->signInWithPassword($ghost, 'x', 'com.vpnhood.test');
    } catch (ApiException) {
    }
    $recorded = (int) Capsule::table('mod_vpnhood_iap_login_attempts')
        ->where('email_hash', emailHash($ghost))->count();
    $recorded === 5
        ? ok('refused attempts are not counted — the wait ends on schedule, hammering or not')
        : bad("expected 5 recorded failures, found $recorded — the cooldown would extend itself");

    // ------------------------------------------------- cooldown beats a correct password
    for ($i = 0; $i < 5; $i++) {
        try {
            $service->signInWithPassword($mfaEmail, 'wrong-' . $i, 'com.vpnhood.test');
        } catch (ApiException) {
        }
    }
    try {
        $service->signInWithPassword($mfaEmail, $mfaPassword, 'com.vpnhood.test');
        bad('a cooling-down address accepted the CORRECT password — the cooldown is an oracle');
    } catch (ApiException $e) {
        ($e->getHttpStatus() === 429 && $e->getErrorCode() === 'too_many_attempts')
            ? ok('a cooling-down address refuses even the correct password')
            : bad('cooldown+correct → ' . $e->getHttpStatus() . ' ' . $e->getErrorCode());
    }
    // age the failures past the window instead of waiting 10 real minutes
    Capsule::table('mod_vpnhood_iap_login_attempts')->where('email_hash', emailHash($mfaEmail))
        ->update(['created_at' => date('Y-m-d H:i:s', time() - 3600)]);
    $outcome = $service->signInWithPassword($mfaEmail, $mfaPassword, 'com.vpnhood.test');
    isset($outcome['whmcsUser'])
        ? ok('the cooldown ends by itself — aged past the window, the password works again')
        : bad('post-cooldown sign-in failed');

    // the plain fixture was never touched by any of that: cooldowns are per address
    $service->signInWithPassword($pwEmail, $pwPassword, 'com.vpnhood.test');
    ok('hammering one address never cools another');

    // ------------------------------------------------- squatting guard
    $googleUser = $repo->findOrCreateUser('google', "google-$marker", $squatEmail, true, 'Original Owner');
    $squatClientId = makeClient($squatEmail, $squatPassword);
    $clientIds[] = $squatClientId;
    $squatWhmcsUser = User::where('email', $squatEmail)->first();
    if ($squatWhmcsUser->getAttribute('email_verified_at') !== null) {
        bad('premise broken: AddClient(skipvalidation) verified the email — the squat pin below is void');
    } else {
        ok('premise holds: the squatted WHMCS email is unverified');
    }
    $squatOutcome = $service->signInWithPassword($squatEmail, $squatPassword, 'com.vpnhood.test');
    $squatUser = $service->signInToModuleAccount($squatOutcome['whmcsUser']);
    ((int) $squatUser['id'] !== (int) $googleUser['id'])
        ? ok('an UNVERIFIED WHMCS email never joins the existing app account (squatting guard)')
        : bad('SQUATTING: the password sign-in opened the Google account');
    ((int) $squatUser['client_id'] === $squatClientId)
        ? ok('the squatter-shaped login got its own client-bound handle instead')
        : bad('squat handle bound wrongly: ' . json_encode($squatUser['client_id']));

    // ------------------------------------------------- ambiguity refuses
    Capsule::table('mod_vpnhood_iap_identities')
        ->where('provider', 'whmcs')->where('provider_subject', (string) $squatWhmcsUser->id)
        ->delete();
    $extraId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider'             => 'google',
        'provider_subject'     => "extra-$marker",
        'email'                => "extra-$marker@vpnhood.test",
        'email_verified_claim' => 1,
        'client_id'            => $squatClientId,
        'external_uid'         => sprintf('%s-0000-4000-8000-000000000001', substr(md5($marker), 0, 8)),
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ]);
    try {
        $service->signInToModuleAccount($squatWhmcsUser);
        bad('two app accounts on the same owned client did not refuse');
    } catch (ApiException $e) {
        ($e->getHttpStatus() === 403 && $e->getErrorCode() === 'account_ambiguous')
            ? ok('several app accounts on the login\'s own client → 403 account_ambiguous, no guessing')
            : bad('ambiguity → ' . $e->getHttpStatus() . ' ' . $e->getErrorCode());
    }
    Capsule::table('mod_vpnhood_iap_users')->where('id', $extraId)->delete();

    // ------------------------------------------------- second factor (TOTP)
    $mfaUser = User::where('email', $mfaEmail)->first();
    $tfa = new \WHMCS\TwoFactorAuthentication();
    $tfa->setUser($mfaUser);
    $secret = implode('', array_map(fn () => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[random_int(0, 31)], range(1, 16)));
    $backupCode = (string) $tfa->activateUser('totp', ['secret' => $secret]);
    $mfaUser = User::where('email', $mfaEmail)->first();
    $mfaUser->hasTwoFactorAuthEnabled()
        ? ok('fixture 2FA activated (totp, our secret)')
        : bad('2FA activation did not stick');

    $challenge = $service->signInWithPassword($mfaEmail, $mfaPassword, 'com.vpnhood.test');
    if (!isset($challenge['challenge'])) {
        bad('2FA account answered no challenge: ' . json_encode(array_keys($challenge)));
        finish();
    }
    ($challenge['challenge']['type'] === 'totp' && strlen($challenge['challenge']['token']) === 64)
        ? ok('password + 2FA → challenge {token, type totp, expiresAt}, no session')
        : bad('challenge shape wrong: ' . json_encode($challenge['challenge']));

    // wrong code burns an attempt but not the challenge
    $token = $challenge['challenge']['token'];
    try {
        $service->completeChallenge($token, '000000', 'com.vpnhood.test');
        bad('garbage code accepted');
    } catch (ApiException $e) {
        $e->getErrorCode() === 'invalid_code'
            ? ok('wrong code → invalid_code, attempts remain')
            : bad('wrong code → ' . $e->getErrorCode());
    }

    // package mismatch is a dead challenge, not a hint
    try {
        $service->completeChallenge($token, '111111', 'com.other.app');
        bad('package mismatch accepted');
    } catch (ApiException $e) {
        $e->getErrorCode() === 'invalid_challenge'
            ? ok('a challenge answers only the package it was issued to')
            : bad('package mismatch → ' . $e->getErrorCode());
    }

    waitOutWindowEdge();
    $code = totpCode($secret, time());
    $usedWindow = intdiv(time(), 30);
    $done = $service->completeChallenge($token, $code, 'com.vpnhood.test');
    ((int) $done['whmcsUser']->id === (int) $mfaUser->id && $done['newBackupCode'] === null)
        ? ok('authenticator code completes the challenge; no backup rotation')
        : bad('completion wrong: ' . json_encode([$done['whmcsUser']->id ?? null, $done['newBackupCode']]));

    // single use: the token is spent
    try {
        $service->completeChallenge($token, totpCode($secret, time()), 'com.vpnhood.test');
        bad('a spent challenge completed twice');
    } catch (ApiException $e) {
        $e->getErrorCode() === 'invalid_challenge'
            ? ok('a challenge is single-use')
            : bad('spent challenge → ' . $e->getErrorCode());
    }

    // WHMCS replay guard: the SAME code on a NEW challenge is refused
    $challenge2 = $service->signInWithPassword($mfaEmail, $mfaPassword, 'com.vpnhood.test');
    try {
        $service->completeChallenge($challenge2['challenge']['token'], $code, 'com.vpnhood.test');
        bad('a replayed TOTP code was accepted');
    } catch (ApiException $e) {
        $e->getErrorCode() === 'invalid_code'
            ? ok('a used TOTP code is dead (WHMCS replay guard, not ours)')
            : bad('replay → ' . $e->getErrorCode());
    }

    // backup code on the same step, with rotation
    $done2 = $service->completeChallenge($challenge2['challenge']['token'], $backupCode, 'com.vpnhood.test');
    (is_string($done2['newBackupCode']) && $done2['newBackupCode'] !== '' && $done2['newBackupCode'] !== $backupCode)
        ? ok('backup code signs in and is rotated — the replacement comes back once')
        : bad('backup rotation wrong: ' . json_encode($done2['newBackupCode']));
    // check with a FRESH instance — the old one holds the pre-rotation settings
    $freshTfa = new \WHMCS\TwoFactorAuthentication();
    $freshTfa->setUser(User::find($mfaUser->id));
    $freshTfa->verifyBackupCode($backupCode) === false
        ? ok('the spent backup code is dead')
        : bad('the spent backup code still verifies — rotation did not take');

    // attempts budget burns the challenge
    $challenge3 = $service->signInWithPassword($mfaEmail, $mfaPassword, 'com.vpnhood.test');
    $token3 = $challenge3['challenge']['token'];
    for ($i = 0; $i < 5; $i++) {
        try {
            $service->completeChallenge($token3, '00000' . $i, 'com.vpnhood.test');
        } catch (ApiException) {
        }
    }
    try {
        $service->completeChallenge($token3, totpCode($secret, time()), 'com.vpnhood.test');
        bad('an exhausted challenge still accepted the right code');
    } catch (ApiException $e) {
        $e->getErrorCode() === 'invalid_challenge'
            ? ok('five wrong codes burn the challenge — even the right code is too late')
            : bad('exhausted challenge → ' . $e->getErrorCode());
    }

    // ------------------------------------------------- the wire
    Capsule::table('mod_vpnhood_iap_apps')->insert([
        'store'            => 'googleplay',
        'package_name'     => "com.vpnhood.$marker",
        'status'           => 'active',
        'oauth_client_ids' => '',
        'webhook_token'    => bin2hex(random_bytes(24)),
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
    ]);

    [$status, $body] = httpJson('POST', '/auth/sessions',
        ['email' => $pwEmail, 'password' => $pwPassword, 'packageName' => "com.vpnhood.$marker"]);
    // the session answers what this device may do, never who the person is: no email here,
    // GET /account below is the one place an address comes from
    ($status === 201 && isset($body['accessToken'], $body['userId']) && !isset($body['email']))
        ? ok('wire: password form → 201 with the same session shape as the provider form')
        : bad("wire password → $status: " . json_encode($body));
    $wireToken = $body['accessToken'] ?? '';

    [$status, $body] = httpJson('GET', '/account', null, $wireToken);
    ($status === 200 && ($body['email'] ?? '') === $pwEmail)
        ? ok('wire: the password-issued session token works like any other')
        : bad("wire GET /account → $status: " . json_encode($body));

    [$status, $wrongBody] = httpJson('POST', '/auth/sessions',
        ['email' => $pwEmail, 'password' => 'wrong', 'packageName' => "com.vpnhood.$marker"]);
    [$status2, $ghostBody] = httpJson('POST', '/auth/sessions',
        ['email' => "ghost2-$marker@vpnhood.test", 'password' => 'wrong', 'packageName' => "com.vpnhood.$marker"]);
    $emailHashes[] = emailHash($pwEmail);
    $emailHashes[] = emailHash("ghost2-$marker@vpnhood.test");
    ($status === 401 && $status2 === 401
        && $wrongBody['code'] === 'invalid_credentials'
        && $wrongBody['code'] === $ghostBody['code']
        && $wrongBody['detail'] === $ghostBody['detail'])
        ? ok('wire: unknown email and wrong password are byte-identical problems')
        : bad("wire anti-enum: $status/$status2 " . json_encode([$wrongBody, $ghostBody]));

    // the current window's code was consumed in-process above; WHMCS's replay
    // guard (rightly) refuses it forever, so wait for a window of its own
    while (intdiv(time(), 30) <= $usedWindow) {
        sleep(1);
    }
    waitOutWindowEdge();
    [$status, $body] = httpJson('POST', '/auth/sessions',
        ['email' => $mfaEmail, 'password' => $mfaPassword, 'packageName' => "com.vpnhood.$marker"]);
    ($status === 200 && isset($body['challenge']['token']) && !isset($body['accessToken']))
        ? ok('wire: 2FA answers 200 challenge, not a session')
        : bad("wire mfa step 1 → $status: " . json_encode($body));

    [$status, $body] = httpJson('POST', '/auth/sessions', [
        'challengeToken' => $body['challenge']['token'] ?? '',
        'code'           => totpCode($secret, time()),
        'packageName'    => "com.vpnhood.$marker",
    ]);
    ($status === 201 && isset($body['accessToken']) && !isset($body['newBackupCode']))
        ? ok('wire: challenge completion → 201 session; no newBackupCode for a TOTP code')
        : bad("wire mfa step 2 → $status: " . json_encode($body));

    [$status, $body] = httpJson('POST', '/auth/sessions',
        ['email' => $pwEmail, 'packageName' => "com.vpnhood.$marker"]);
    ($status === 400 && ($body['code'] ?? '') === 'bad_request')
        ? ok('wire: a half-filled password form is a 400, not a guess')
        : bad("wire missing-fields → $status: " . json_encode($body));

    // the log never stores the password
    $leak = one($db,
        "SELECT id FROM mod_vpnhood_iap_log WHERE action = 'POST /auth/sessions' AND created_at >= ? AND request LIKE ?",
        [$testStart, '%' . $pwPassword . '%']);
    $leak === null
        ? ok('the request log carries [redacted], never the password')
        : bad("the password appears in log row #{$leak['id']}");
} finally {
    // ---------------------------------------------------------------- cleanup
    try {
        if ($tfa !== null && $mfaUser !== null && $mfaUser->hasTwoFactorAuthEnabled()) {
            $tfa->setUser($mfaUser);
            $tfa->disableUser();
        }
    } catch (\Throwable $e) {
        bad('cleanup: could not disable fixture 2FA: ' . $e->getMessage());
    }

    $whmcsUserIds = [];
    foreach ([$pwEmail, $mfaEmail, $squatEmail] as $email) {
        $u = one($db, 'SELECT id FROM tblusers WHERE email = ?', [$email]);
        if ($u !== null) {
            $whmcsUserIds[] = (int) $u['id'];
        }
    }
    $moduleUserIds = Capsule::table('mod_vpnhood_iap_identities')
        ->where(function ($q) use ($whmcsUserIds, $marker) {
            $q->where(function ($qq) use ($whmcsUserIds) {
                $qq->where('provider', 'whmcs')->whereIn('provider_subject', array_map('strval', $whmcsUserIds));
            })->orWhere('provider_subject', 'like', "%$marker%");
        })
        ->pluck('user_id')->all();
    if ($moduleUserIds !== []) {
        Capsule::table('mod_vpnhood_iap_sessions')->whereIn('user_id', $moduleUserIds)->delete();
        Capsule::table('mod_vpnhood_iap_identities')->whereIn('user_id', $moduleUserIds)->delete();
        Capsule::table('mod_vpnhood_iap_users')->whereIn('id', $moduleUserIds)->delete();
    }
    if ($whmcsUserIds !== []) {
        Capsule::table('mod_vpnhood_iap_login_challenges')->whereIn('whmcs_user_id', $whmcsUserIds)->delete();
    }
    Capsule::table('mod_vpnhood_iap_login_attempts')->whereIn('email_hash', array_unique($emailHashes))->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('package_name', "com.vpnhood.$marker")->delete();
    // our own wire calls must not push later suites into the per-IP limit
    Capsule::table('mod_vpnhood_iap_log')
        ->where('action', 'POST /auth/sessions')
        ->where('created_at', '>=', $testStart)
        ->delete();
    foreach ($clientIds as $cid) {
        $r = localAPI('DeleteClient', ['clientid' => $cid, 'deleteusers' => true, 'deletetransactions' => false]);
        if (($r['result'] ?? '') !== 'success') {
            // dev box, marker emails — a leftover client is harmless
            break;
        }
    }
    ok('fixtures cleaned');
}

finish();
