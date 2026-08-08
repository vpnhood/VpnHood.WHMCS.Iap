<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Email → WHMCS client resolution.
 *
 * The identity provider is the proof of mailbox ownership: sign-in is refused
 * unless the IdP itself reports the address as verified, so any address that
 * reaches this class has already been proven by Google or Apple. Asking WHMCS
 * to verify the same address a second time only parked purchases behind another
 * click-through mail, so it is not asked — an existing WHMCS client whose email
 * matches is attached as-is.
 *
 * Client creation for brand-new emails happens at first purchase (redeem),
 * not at sign-in — signing in alone must not create WHMCS accounts.
 */
class AccountService
{
    public const STATE_LINKED = 'linked';
    public const STATE_NO_CLIENT = 'no_client';

    /**
     * Resolve an email to an attachable WHMCS client.
     *
     * @return array{clientId:?int, state:string} state: linked | no_client
     */
    public function resolveClientForEmail(string $email): array
    {
        $email = strtolower(trim($email));
        $client = Capsule::table('tblclients')->whereRaw('LOWER(email) = ?', [$email])->first(['id']);
        if ($client === null) {
            return ['clientId' => null, 'state' => self::STATE_NO_CLIENT];
        }
        return ['clientId' => (int) $client->id, 'state' => self::STATE_LINKED];
    }

    /**
     * Whether WHMCS itself has seen this address confirmed, read per user from
     * tblusers.email_verified_at. Deliberately independent of the global
     * EnableEmailVerification switch: that setting turns verification on for
     * EVERY client, and the portal-login gate this feeds is meant to apply only
     * to the accounts a store purchase attached itself to.
     *
     * Only ever used to decide whether that one account must confirm before the
     * client area opens — never to gate a purchase.
     */
    public function isEmailVerified(string $email): bool
    {
        $user = \WHMCS\User\User::where('email', strtolower(trim($email)))->first();
        return $user !== null && (bool) $user->emailVerified();
    }

    /**
     * Ask WHMCS to issue its own verification mail for the address. Works with
     * the global switch off (WHMCS still mints the token), which is what lets
     * the gate be per-account. The link WHMCS sends lives for 60 minutes, so the
     * gate page must be able to call this again rather than assume one mail is
     * enough. Best-effort: a mail that cannot be sent must never break a caller.
     */
    public function sendVerificationEmail(string $email): bool
    {
        $user = \WHMCS\User\User::where('email', strtolower(trim($email)))->first();
        if ($user === null) {
            return false;
        }
        try {
            return (bool) $user->sendEmailVerification();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
