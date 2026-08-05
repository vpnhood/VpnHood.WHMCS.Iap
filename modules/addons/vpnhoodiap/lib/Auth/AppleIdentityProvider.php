<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Auth;

use WHMCS\Module\Addon\VpnHoodIap\Http;
use WHMCS\Module\Addon\VpnHoodIap\Jwk;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Verifies "Sign in with Apple" identity tokens: RS256 against Apple's JWK
 * set (converted to PEM — see Jwk), pinned issuer, audience allowlist (the
 * app's bundle ids).
 *
 * Apple particulars this normalizes away:
 *  - email_verified / is_private_email arrive as the STRINGS "true"/"false";
 *  - the email may be an @privaterelay.appleid.com address — still a working,
 *    Apple-verified mailbox, so the attach gate treats it like any other.
 */
class AppleIdentityProvider implements IdentityProviderInterface
{
    public const PROVIDER = 'apple';

    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';

    /** @var callable(): array<string,string> */
    private $keysFetcher;
    private ?int $now;

    /** @param callable():array<string,string>|null $keysFetcher kid => PEM map; null = fetch from Apple */
    public function __construct(?callable $keysFetcher = null, ?int $now = null)
    {
        $this->keysFetcher = $keysFetcher ?? [self::class, 'fetchAppleKeys'];
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

        $claims = Jwt::verifyRs256($idToken, ($this->keysFetcher)());
        Jwt::assertTimeValid($claims, $this->now);

        $issuer = (string) ($claims['iss'] ?? '');
        if ($issuer !== self::ISSUER) {
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
            'emailVerified' => self::claimIsTrue($claims['email_verified'] ?? false),
            'name'          => null, // Apple sends the name once, out of band, in the sign-in response body — never in the token
        ];
    }

    /** Apple booleans arrive as true, "true", false or "false". */
    private static function claimIsTrue(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    /** @return array<string,string> kid => PEM */
    public static function fetchAppleKeys(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $response = Http::request('GET', self::JWKS_URL);
        if ($response['status'] !== 200 || !is_array($response['json'])) {
            throw new \RuntimeException('Could not fetch Apple signing keys (HTTP ' . $response['status'] . ').');
        }
        return $cache = Jwk::setToPems($response['json']);
    }
}
