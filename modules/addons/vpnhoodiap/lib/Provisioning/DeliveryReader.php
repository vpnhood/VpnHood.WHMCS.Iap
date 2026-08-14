<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * THE one provisioning-aware component in this module (see CLAUDE.md).
 * Reads the delivered access code from a service's serviceProperties:
 *
 *   1. `accessCode` present → return it (vpnhoodpartner installs store the
 *      relayed code on the service);
 *   2. else `accessTokenId` present AND the vpnhoodstore module exists on
 *      this install → live-fetch from the access manager (the hub never
 *      persists codes) — guarded by file_exists, never a hard require;
 *   3. else → null: not provisioned (yet).
 *
 * Nothing here writes anything, and nothing here knows WHY a property is
 * missing — callers decide whether null is an error.
 */
class DeliveryReader
{
    public function readAccessCode(int $serviceId): ?string
    {
        $service = \WHMCS\Service\Service::find($serviceId);
        if ($service === null) {
            return null;
        }
        $properties = $service->serviceProperties;

        $accessCode = (string) $properties->get('accessCode');
        if ($accessCode !== '') {
            return $accessCode;
        }

        $accessTokenId = (string) $properties->get('accessTokenId');
        if ($accessTokenId !== '') {
            return $this->fetchLiveAccessCode($accessTokenId);
        }

        return null;
    }

    /**
     * The key's own clock, read where it can be (lifecycle §4: a one-time key
     * starts on first use, so its expiry lives on the ACCESS MANAGER, not in
     * WHMCS). Hub installs read it live; a partner install has no path to the
     * manager, so it answers 'unknown' and callers stay conservative.
     *
     * @return array{state: 'active'|'expired'|'not-started'|'unknown', expiresAt: ?string}
     */
    public function readCodeState(int $serviceId): array
    {
        $service = \WHMCS\Service\Service::find($serviceId);
        $accessTokenId = $service === null ? '' : (string) $service->serviceProperties->get('accessTokenId');
        if ($accessTokenId === '') {
            return ['state' => 'unknown', 'expiresAt' => null];
        }
        $storeLib = ROOTDIR . '/modules/servers/vpnhoodstore/lib';
        if (!file_exists($storeLib . '/ApiService.php')) {
            return ['state' => 'unknown', 'expiresAt' => null];
        }
        try {
            if (file_exists($storeLib . '/AsyncApiClientFactory.php')) {
                require_once $storeLib . '/AsyncApiClientFactory.php';
            }
            require_once $storeLib . '/ApiService.php';
            $apiService = new \WHMCS\Module\Server\VpnHoodStore\ApiService();
            $json = json_decode((string) $apiService->getAccessCode($accessTokenId));
            $token = $json->accessToken ?? null;
            if ($token === null) {
                return ['state' => 'unknown', 'expiresAt' => null];
            }
            $expiration = $token->expirationTime ?? null;
            if ($expiration === null) {
                return ['state' => 'not-started', 'expiresAt' => null]; // one-time, never used
            }
            $expiresAt = gmdate('c', strtotime((string) $expiration));
            return [
                'state'     => strtotime((string) $expiration) < time() ? 'expired' : 'active',
                'expiresAt' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            logModuleCall('vpnhoodiap', 'DeliveryReader.readCodeState', $accessTokenId, $e->getMessage(), '');
            return ['state' => 'unknown', 'expiresAt' => null];
        }
    }

    /** Live-fetch via vpnhoodstore's own ApiService, only where that module exists. */
    private function fetchLiveAccessCode(string $accessTokenId): ?string
    {
        $storeLib = ROOTDIR . '/modules/servers/vpnhoodstore/lib';
        if (!file_exists($storeLib . '/ApiService.php')) {
            return null; // partner-shaped install without a stored accessCode: not deliverable
        }
        if (file_exists($storeLib . '/AsyncApiClientFactory.php')) {
            require_once $storeLib . '/AsyncApiClientFactory.php';
        }
        require_once $storeLib . '/ApiService.php';

        $apiService = new \WHMCS\Module\Server\VpnHoodStore\ApiService();
        $json = json_decode((string) $apiService->getAccessCode($accessTokenId));
        $accessCode = $json->accessToken->accessCode ?? null;
        return is_string($accessCode) && $accessCode !== '' ? $accessCode : null;
    }
}
