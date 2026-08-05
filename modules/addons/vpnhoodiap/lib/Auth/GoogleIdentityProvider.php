<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Auth;

use WHMCS\Module\Addon\VpnHoodIap\Http;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Verifies "Sign in with Google" id tokens: RS256 signature against Google's
 * published X.509 certs, pinned issuers, audience allowlist from the app row.
 *
 * The certs fetcher is injectable so unit tests can verify the whole pipeline
 * against runtime-generated keys without any network.
 */
class GoogleIdentityProvider implements IdentityProviderInterface
{
    public const PROVIDER = 'google';

    /** kid => X.509 PEM. Rotated by Google; fetched per process, cached statically. */
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** @var callable(): array<string,string> */
    private $certsFetcher;
    private ?int $now;

    /**
     * @param callable():array<string,string>|null $certsFetcher kid => PEM map; null = fetch from Google
     * @param ?int $now clock override for tests
     */
    public function __construct(?callable $certsFetcher = null, ?int $now = null)
    {
        $this->certsFetcher = $certsFetcher ?? [self::class, 'fetchGoogleCerts'];
        $this->now = $now;
    }

    public function providerId(): string
    {
        return self::PROVIDER;
    }

    public function verifyIdToken(string $idToken, array $allowedAudiences): array
    {
        if ($allowedAudiences === []) {
            throw new \RuntimeException('No OAuth client ids are configured for this app.');
        }

        $claims = Jwt::verifyRs256($idToken, ($this->certsFetcher)());
        Jwt::assertTimeValid($claims, $this->now);

        $issuer = (string) ($claims['iss'] ?? '');
        if (!in_array($issuer, self::ISSUERS, true)) {
            throw new \RuntimeException("Unexpected token issuer: '$issuer'.");
        }

        $audience = (string) ($claims['aud'] ?? '');
        if (!in_array($audience, $allowedAudiences, true)) {
            throw new \RuntimeException('Token audience is not registered for this app.');
        }

        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            throw new \RuntimeException('Token has no subject.');
        }
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('Token has no email claim.');
        }

        return [
            'subject'       => $subject,
            'email'         => $email,
            'emailVerified' => (bool) ($claims['email_verified'] ?? false),
            'name'          => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }

    /** @return array<string,string> kid => X.509 PEM */
    public static function fetchGoogleCerts(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $response = Http::request('GET', self::CERTS_URL);
        if ($response['status'] !== 200 || !is_array($response['json'])) {
            throw new \RuntimeException('Could not fetch Google signing certificates (HTTP ' . $response['status'] . ').');
        }
        $certs = array_filter($response['json'], 'is_string');
        if ($certs === []) {
            throw new \RuntimeException('Google signing certificate response is empty.');
        }
        return $cache = $certs;
    }
}
