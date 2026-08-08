<?php

/**
 * VpnHood! IAP — per-account client-area gate for unconfirmed email addresses.
 *
 * A store purchase proves the BUYER owns the address (the identity provider says so,
 * and api.php refuses to sign anyone in otherwise). It proves nothing about a WHMCS
 * client record that already held that address before the purchase arrived — anyone
 * can register any address in the client area. So when a purchase attaches itself to
 * a pre-existing client, EntitlementService flags the account and this hook keeps the
 * client area shut for it until WHMCS confirms the address.
 *
 * What it deliberately does NOT do is hold up the purchase: the order is placed, the
 * access code ships, and the app works throughout. Only the WHMCS portal waits.
 *
 * Per account, never global: WHMCS's own EnableEmailVerification switch would demand
 * this of every client on the install, which is not what is wanted — so the state is
 * our own flag AND WHMCS's per-user tblusers.email_verified_at, and the global switch
 * is left alone. Confirming the address opens the portal even if the flag is stale,
 * because the flag alone never decides.
 *
 * The gate has to stay open just wide enough to escape itself: WHMCS's verification
 * link lives 60 minutes and its own advice for an expired one is "login to our client
 * area to request a new link". A hook that bounced every page would make that
 * impossible, so logout and this module's own resend page are always reachable.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

require_once __DIR__ . '/../../modules/addons/vpnhoodiap/lib/IapRepository.php';

/**
 * Pages a gated client may still reach: the gate page itself (which offers the
 * resend), logout, and WHMCS's own verification handler that the mailed link
 * lands on. Everything else redirects to the gate.
 */
function vpnhoodiap_gateAllowsCurrentPage(string $filename, array $vars): bool
{
    if (in_array($filename, ['logout', 'verifyemail', 'password-reset'], true)) {
        return true;
    }
    // the addon's own client-area page (index.php?m=vpnhoodiap) is the gate page
    if (($_GET['m'] ?? '') === 'vpnhoodiap') {
        return true;
    }
    // WHMCS routes the mailed verification link through the user endpoints
    $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return str_contains($path, '/user/verify') || str_contains($path, 'verifyemail');
}

add_hook('ClientAreaPage', 1, function (array $vars) {
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if ($clientId <= 0) {
        return [];
    }

    try {
        if (vpnhoodiap_gateAllowsCurrentPage((string) ($vars['filename'] ?? ''), $vars)) {
            return [];
        }

        $repo = new IapRepository();
        if (!$repo->clientRequiresEmailVerification($clientId)) {
            return [];
        }

        // WHMCS's own per-user state is the authority on whether the address is
        // confirmed; the flag only says this account is subject to the gate.
        $email = (string) Capsule::table('tblclients')->where('id', $clientId)->value('email');
        $user = $email !== '' ? \WHMCS\User\User::where('email', $email)->first() : null;
        if ($user !== null && (bool) $user->emailVerified()) {
            return [];
        }

        header('Location: ' . rtrim((string) ($vars['systemurl'] ?? ''), '/')
            . '/index.php?m=vpnhoodiap&action=verify-email');
        exit;
    } catch (\Throwable $e) {
        // a gate that cannot read its own state must not lock anyone out of the
        // portal — fail open and leave a trail for the admin
        logModuleCall('vpnhoodiap', 'hook.verifyGate', (string) $clientId, $e->getMessage(), '');
        return [];
    }
});
