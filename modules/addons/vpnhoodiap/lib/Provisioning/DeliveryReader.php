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
