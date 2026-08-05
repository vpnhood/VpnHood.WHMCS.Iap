<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores\AppStore;

use WHMCS\Module\Addon\VpnHoodIap\Http;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Thin App Store Server API client authenticated with an ES256 JWT signed by
 * the app's In-App Purchase key (.p8). Production first; a 404 retries the
 * sandbox host, which is Apple's documented pattern for sandbox purchases
 * (TestFlight / review) hitting a production-configured server.
 *
 * Credentials JSON stored on the app row:
 *   { "issuerId": "...", "keyId": "...", "privateKey": "-----BEGIN PRIVATE KEY-----..." }
 */
class AppStoreApiClient
{
    private const PRODUCTION_BASE = 'https://api.storekit.itunes.apple.com/inApps/v1';
    private const SANDBOX_BASE = 'https://api.storekit-sandbox.itunes.apple.com/inApps/v1';
    private const TOKEN_TTL = 19 * 60; // Apple caps the token at 20 minutes

    /** @var array{issuerId:string, keyId:string, privateKey:string} */
    private array $credentials;
    private string $bundleId;
    /** @var callable(string,string,array,?string):array{status:int,body:string,json:?array} */
    private $http;

    /** @var array<string,array{token:string, expiresAt:int}> per keyId */
    private static array $tokenCache = [];

    public function __construct(array $credentials, string $bundleId, ?callable $http = null)
    {
        foreach (['issuerId', 'keyId', 'privateKey'] as $required) {
            if (empty($credentials[$required]) || !is_string($credentials[$required])) {
                throw new \RuntimeException("Apple credentials JSON is missing '$required'.");
            }
        }
        $this->credentials = $credentials;
        $this->bundleId = $bundleId;
        $this->http = $http ?? [Http::class, 'request'];
    }

    public static function fromApp(array $app, IapRepository $repo): self
    {
        $credentialsJson = $repo->decryptSecret((string) ($app['credentials'] ?? ''));
        $credentials = json_decode($credentialsJson, true);
        if (!is_array($credentials)) {
            throw new \RuntimeException('The stored Apple credentials are not valid JSON.');
        }
        return new self($credentials, (string) $app['package_name']);
    }

    /**
     * Get All Subscription Statuses — the truth document for a subscription
     * family, keyed by the ORIGINAL transaction id.
     */
    public function getSubscriptionStatuses(string $originalTransactionId): array
    {
        return $this->call('GET', '/subscriptions/' . rawurlencode($originalTransactionId));
    }

    /** Get Transaction Info — any transaction id → {signedTransactionInfo}. */
    public function getTransactionInfo(string $transactionId): array
    {
        return $this->call('GET', '/transactions/' . rawurlencode($transactionId));
    }

    // ----------------------------------------------------------- plumbing --

    private function call(string $method, string $path, ?string $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiToken()];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = ($this->http)($method, self::PRODUCTION_BASE . $path, $headers, $body);
        if ($response['status'] === 404) {
            // sandbox purchases 404 on the production host — retry there
            $response = ($this->http)($method, self::SANDBOX_BASE . $path, $headers, $body);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $reason = (string) ($response['json']['errorMessage'] ?? substr($response['body'], 0, 300));
            throw new \RuntimeException("App Store Server API error (HTTP {$response['status']}): $reason");
        }
        return $response['json'] ?? [];
    }

    private function apiToken(): string
    {
        $cacheKey = $this->credentials['keyId'] . '|' . $this->bundleId;
        $cached = self::$tokenCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expiresAt'] > time() + 60) {
            return $cached['token'];
        }

        $now = time();
        $token = AppleJws::signEs256([
            'iss' => $this->credentials['issuerId'],
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL,
            'aud' => 'appstoreconnect-v1',
            'bid' => $this->bundleId,
        ], $this->credentials['privateKey'], ['kid' => $this->credentials['keyId']]);

        self::$tokenCache[$cacheKey] = ['token' => $token, 'expiresAt' => $now + self::TOKEN_TTL];
        return $token;
    }
}
