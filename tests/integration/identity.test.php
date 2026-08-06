<?php
/**
 * identity.test.php — the account is the PERSON, resolved in two steps:
 *
 *   1. a known (provider, subject) identity always wins — accounts survive
 *      provider-side email changes;
 *   2. a new identity whose verified email matches an existing account joins it —
 *      Google today, Apple tomorrow, same address, same account, same external_uid
 *      (so the store's obfuscated account id still matches the binding guard).
 *
 * Runs against the real module tables inside the deployed dev WHMCS; every write
 * stays in mod_vpnhood_iap_* (the capsule rule).
 */

require __DIR__ . '/lib/common.php';

requireIapLib('IapRepository.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
if (!tableExists($db, 'mod_vpnhood_iap_identities')) {
    bad('identities table missing — run the upgrade (open the addon page) first');
    finish();
}

$marker = 'itest-' . bin2hex(random_bytes(4));
$email = "$marker@vpnhood.test";
$repo = new IapRepository();

try {
    // -- first sign-in: Google ------------------------------------------------
    $google = $repo->findOrCreateUser('google', "google-$marker", $email, true);
    ok("google sign-in created account #{$google['id']} ({$google['external_uid']})");

    // -- rule 2: same person, different provider, same address ----------------
    $apple = $repo->findOrCreateUser('apple', "apple-$marker", $email, true);

    if ((int) $apple['id'] === (int) $google['id']) {
        ok('apple sign-in landed on the SAME account');
    } else {
        bad("apple sign-in created a second account (#{$google['id']} vs #{$apple['id']})");
    }

    if ($apple['external_uid'] === $google['external_uid']) {
        ok('external_uid is stable across providers (store purchases stay bound)');
    } else {
        bad("external_uid changed: {$google['external_uid']} -> {$apple['external_uid']}");
    }

    $identities = $repo->identitiesForUser((int) $google['id']);
    count($identities) === 2
        ? ok('both sign-in proofs linked to the one account')
        : bad(count($identities) . ' identities linked, expected 2');

    // -- rule 1: provider changes the email — the account survives ------------
    $renamed = $repo->findOrCreateUser('google', "google-$marker", "renamed-$email", true);
    if ((int) $renamed['id'] === (int) $google['id']) {
        ok('provider-side email change keeps the SAME account (identity wins)');
    } else {
        bad("email change at the provider split the account (#{$renamed['id']})");
    }
    if ($renamed['email'] === $email) {
        ok('the account keeps its original address');
    } else {
        bad("account address was re-keyed to {$renamed['email']}");
    }

    // -- rule 2 matches case/space-insensitively ------------------------------
    $shouty = $repo->findOrCreateUser('microsoft', "ms-$marker", '  ' . strtoupper($email) . ' ', true);
    if ((int) $shouty['id'] === (int) $google['id']) {
        ok('address matching ignores case and surrounding space');
    } else {
        bad("upper-cased address created account #{$shouty['id']} instead of #{$google['id']}");
    }

    // -- a different address from an unknown identity is a different person ---
    $other = $repo->findOrCreateUser('google', "google2-$marker", "other-$email", true);
    if ((int) $other['id'] !== (int) $google['id']) {
        ok('a different address is a different account');
    } else {
        bad('different addresses collapsed into one account');
    }

    // -- exactly one account row for the address ------------------------------
    $count = (int) Capsule::table('mod_vpnhood_iap_users')->where('email', $email)->count();
    $count === 1
        ? ok('exactly one account row for the address')
        : bad("$count account rows for the address");

    // -- the client link travels with the account, whatever proves it ---------
    Capsule::table('mod_vpnhood_iap_users')->where('id', $google['id'])->update(['client_id' => 424242]);
    $afterLink = $repo->findOrCreateUser('apple', "apple2-$marker", $email, true);
    if ((int) ($afterLink['client_id'] ?? 0) === 424242) {
        ok('WHMCS client link survives signing in with another provider');
    } else {
        bad('client link lost when the provider changed: ' . json_encode($afterLink['client_id'] ?? null));
    }
} finally {
    $userIds = Capsule::table('mod_vpnhood_iap_users')
        ->where('email', 'like', "%$marker%")->pluck('id')->all();
    Capsule::table('mod_vpnhood_iap_identities')->whereIn('user_id', $userIds)->delete();
    Capsule::table('mod_vpnhood_iap_users')->whereIn('id', $userIds)->delete();
}

finish();
