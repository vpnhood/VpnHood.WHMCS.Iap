<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Module\Addon\VpnHoodIap\ApiException;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Creates the WHMCS client for a brand-new store customer (email unknown to
 * WHMCS). Only ever called after AccountService reported no existing client —
 * duplicate clients are never created, and the module never emails panel
 * passwords: the customer bought in a store; the WHMCS account is
 * bookkeeping until they ever choose to use the web portal.
 */
class ClientProvisioner
{
    /**
     * @return int the new client id
     * @throws ApiException
     */
    public function createClient(string $email, ?string $displayName): int
    {
        $nameParts = preg_split('/\s+/', trim((string) $displayName), 2) ?: [];
        $result = localAPI('AddClient', [
            'firstname'       => $nameParts[0] !== '' && isset($nameParts[0]) ? $nameParts[0] : 'VpnHood',
            'lastname'        => $nameParts[1] ?? 'Customer',
            'email'           => $email,
            'password2'       => bin2hex(random_bytes(16)),
            'country'         => 'US',
            'skipvalidation'  => true,
            'noemail'         => true,
        ]);
        if (($result['result'] ?? '') !== 'success' || (int) ($result['clientid'] ?? 0) <= 0) {
            throw new ApiException('Could not create the customer account: ' . ($result['message'] ?? 'unknown error'), 502);
        }
        $clientId = (int) $result['clientid'];

        // The IdP already proved mailbox ownership (email_verified claim gates
        // sign-in), so mark the WHMCS-side verification complete. Best-effort:
        // a failure here only means WHMCS sends its own verification mail.
        try {
            $user = \WHMCS\User\User::where('email', $email)->first();
            $user?->setEmailVerificationCompleted();
        } catch (\Throwable $e) {
            // tolerated — see above
        }

        return $clientId;
    }
}
