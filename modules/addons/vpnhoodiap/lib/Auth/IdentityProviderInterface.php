<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Auth;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * A sign-in identity provider (Google now; Apple later; username/password
 * possibly one day). Verifies an id token cryptographically and returns the
 * normalized identity. Adding a provider = one implementation + registry row;
 * nothing in sessions, api.php or provisioning changes.
 */
interface IdentityProviderInterface
{
    /** Stable provider id as stored in mod_vpnhood_iap_users.provider. */
    public function providerId(): string;

    /**
     * Verify an id token end-to-end (signature, expiry, issuer, audience) and
     * return the normalized identity.
     *
     * @param array<int,string> $allowedAudiences the app's registered OAuth client ids
     * @return array{subject:string, email:string, emailVerified:bool, name:?string}
     * @throws \RuntimeException when the token fails any check
     */
    public function verifyIdToken(string $idToken, array $allowedAudiences): array;
}
