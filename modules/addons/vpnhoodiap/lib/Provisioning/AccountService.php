<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Email → WHMCS client resolution with the verification gate.
 *
 * Threat model: an attacker pre-registers the victim's email as a WHMCS
 * account, then waits for the victim's store purchases to land in it. So an
 * EXISTING email is only ever attached when WHMCS itself has verified it.
 * A store purchase whose email exists unverified parks (never attaches,
 * never acknowledges) until the WHMCS side verifies.
 *
 * Client creation for brand-new emails happens at first purchase (redeem),
 * not at sign-in — signing in alone must not create WHMCS accounts.
 */
class AccountService
{
    public const STATE_LINKED = 'linked';
    public const STATE_NO_CLIENT = 'no_client';
    public const STATE_EMAIL_UNVERIFIED = 'email_unverified';

    /**
     * Resolve an email to an attachable WHMCS client.
     *
     * @return array{clientId:?int, state:string} state: linked | no_client | email_unverified
     */
    public function resolveClientForEmail(string $email): array
    {
        $email = strtolower(trim($email));
        $client = Capsule::table('tblclients')->whereRaw('LOWER(email) = ?', [$email])->first(['id']);
        if ($client === null) {
            return ['clientId' => null, 'state' => self::STATE_NO_CLIENT];
        }
        if ($this->isEmailVerified($email)) {
            return ['clientId' => (int) $client->id, 'state' => self::STATE_LINKED];
        }
        return ['clientId' => null, 'state' => self::STATE_EMAIL_UNVERIFIED];
    }

    /**
     * Whether WHMCS has verified this email. Fail-closed on purpose:
     *  - no WHMCS user record for the email → false (nobody proved ownership);
     *  - EnableEmailVerification disabled  → false for existing emails, because
     *    WHMCS then never verifies anyone. Enabling it is a documented ops
     *    requirement for every install running vpnhoodiap (see README).
     */
    public function isEmailVerified(string $email): bool
    {
        if (!$this->isVerificationEnabled()) {
            return false;
        }
        $user = \WHMCS\User\User::where('email', $email)->first();
        if ($user === null) {
            return false;
        }
        return (bool) $user->emailVerified();
    }

    /** Ask WHMCS to (re)send its own verification mail for the email's user. */
    public function sendVerificationEmail(string $email): bool
    {
        $user = \WHMCS\User\User::where('email', strtolower(trim($email)))->first();
        if ($user === null || !$this->isVerificationEnabled()) {
            return false;
        }
        try {
            $user->sendEmailVerification();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isVerificationEnabled(): bool
    {
        $value = (string) Capsule::table('tblconfiguration')
            ->where('setting', 'EnableEmailVerification')
            ->value('value');
        return $value === 'on' || $value === '1';
    }
}
