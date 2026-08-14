<?php
/**
 * identity.test.php — the account is the PERSON, resolved in this order:
 *
 *   1. a known (provider, subject) identity always wins — accounts survive
 *      provider-side email changes;
 *   2. a new identity joins an account only via an address one of that account's
 *      sign-in methods CURRENTLY reports — Google today, Apple tomorrow, same
 *      address, same account, same external_uid (so the store's obfuscated
 *      account id still matches the binding guard). The account row's own email
 *      is a contact snapshot and must never resolve: a stale snapshot is how a
 *      recycled work address would open its previous owner's account.
 *   3. an address several accounts answer to joins NONE (loudly);
 *   4. otherwise a new person.
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
    $google = $repo->findOrCreateUser('google', "google-$marker", $email, true, 'Randy Blake');
    ok("google sign-in created account #{$google['id']} ({$google['external_uid']})");

    ($google['display_name'] ?? null) === 'Randy Blake'
        ? ok('the provider name is captured on the account')
        : bad('display_name not captured: ' . json_encode($google['display_name'] ?? null));

    // -- rule 2: same person, different provider, same address ----------------
    // Apple after the first sign-in carries no name — the known name must survive
    $apple = $repo->findOrCreateUser('apple', "apple-$marker", $email, true, null);

    ($apple['display_name'] ?? null) === 'Randy Blake'
        ? ok('a name-less sign-in leaves the last known name alone')
        : bad('name lost on name-less sign-in: ' . json_encode($apple['display_name'] ?? null));

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
    $renamed = $repo->findOrCreateUser('google', "google-$marker", "renamed-$email", true, 'Randy B. Blake');
    ($renamed['display_name'] ?? null) === 'Randy B. Blake'
        ? ok('a changed provider name updates the account')
        : bad('display_name not refreshed: ' . json_encode($renamed['display_name'] ?? null));
    if ((int) $renamed['id'] === (int) $google['id']) {
        ok('provider-side email change keeps the SAME account (identity wins)');
    } else {
        bad("email change at the provider split the account (#{$renamed['id']})");
    }
    if ($renamed['email'] === "renamed-$email") {
        ok('the account contact address follows the latest sign-in');
    } else {
        bad("contact address not refreshed: {$renamed['email']}");
    }

    // -- rule 2 matches case/space-insensitively ------------------------------
    $shouty = $repo->findOrCreateUser('microsoft', "ms-$marker", '  ' . strtoupper($email) . ' ', true, null);
    if ((int) $shouty['id'] === (int) $google['id']) {
        ok('address matching ignores case and surrounding space');
    } else {
        bad("upper-cased address created account #{$shouty['id']} instead of #{$google['id']}");
    }

    // -- a different address from an unknown identity is a different person ---
    $other = $repo->findOrCreateUser('google', "google2-$marker", "other-$email", true, null);
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
    $afterLink = $repo->findOrCreateUser('apple', "apple2-$marker", $email, true, null);
    if ((int) ($afterLink['client_id'] ?? 0) === 424242) {
        ok('WHMCS client link survives signing in with another provider');
    } else {
        bad('client link lost when the provider changed: ' . json_encode($afterLink['client_id'] ?? null));
    }

    // -- joining a sign-in method leaves a durable notice ---------------------
    $linkNotices = (int) Capsule::table('mod_vpnhood_iap_log')
        ->where('action', 'identity_linked')->where('user_id', $google['id'])->count();
    $linkNotices >= 1
        ? ok('joining a sign-in method leaves a durable notice (identity_linked)')
        : bad('no identity_linked log row for the join');

    // -- a stale contact snapshot must NOT resolve ----------------------------
    // Simulate a pre-identity-era row: the snapshot names an address no sign-in
    // method reports any more (the owner moved on years ago; an employer may have
    // handed the address to someone new). The person who verifiably holds that
    // address TODAY gets their own account — matching the snapshot would hand
    // them the previous owner's purchases.
    $stale = "stale-$marker@vpnhood.test";
    $victim = $repo->findOrCreateUser('google', "google-stale-$marker", $stale, true, 'First Owner');
    $repo->findOrCreateUser('google', "google-stale-$marker", "moved-$stale", true, null); // the owner's address moved on
    Capsule::table('mod_vpnhood_iap_users')->where('id', $victim['id'])->update(['email' => $stale]); // old install: snapshot never refreshed
    $claimant = $repo->findOrCreateUser('apple', "apple-stale-$marker", $stale, true, null);
    if ((int) $claimant['id'] !== (int) $victim['id']) {
        ok('an address no sign-in method reports opens NOBODY else\'s account');
    } else {
        bad('recycled address resolved to the previous owner\'s account');
    }

    // -- an address several accounts answer to joins none ---------------------
    $shared = "shared-$marker@vpnhood.test";
    $a = $repo->findOrCreateUser('google', "google-shared-a-$marker", $shared, true, null);
    $b = $repo->findOrCreateUser('google', "google-shared-b-$marker", "b-$shared", true, null);
    Capsule::table('mod_vpnhood_iap_identities')->where('user_id', $b['id'])->update(['email' => $shared]); // historic split
    $third = $repo->findOrCreateUser('apple', "apple-shared-$marker", $shared, true, null);
    if ((int) $third['id'] !== (int) $a['id'] && (int) $third['id'] !== (int) $b['id']) {
        ok('an address matching several accounts joins none of them');
    } else {
        bad('ambiguous address was silently joined to an existing account');
    }
    $alerts = (int) Capsule::table('mod_vpnhood_iap_log')
        ->where('action', 'alert')->where('response', 'like', "%$shared%")->count();
    $alerts >= 1
        ? ok('the collision is reported loudly')
        : bad('no alert row for the ambiguous address');
} finally {
    $userIds = Capsule::table('mod_vpnhood_iap_users')
        ->where('email', 'like', "%$marker%")->pluck('id')->all();
    Capsule::table('mod_vpnhood_iap_identities')->whereIn('user_id', $userIds)->delete();
    Capsule::table('mod_vpnhood_iap_users')->whereIn('id', $userIds)->delete();
}

finish();
