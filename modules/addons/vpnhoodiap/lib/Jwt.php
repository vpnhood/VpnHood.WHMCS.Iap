<?php

namespace WHMCS\Module\Addon\VpnHoodIap;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Minimal JWT: RS256 verify + sign via openssl. No Composer, no other deps.
 *
 * Used for: Google sign-in id tokens, Pub/Sub push OIDC tokens (both verified
 * against Google's published X.509 certs) and the Google service-account
 * OAuth assertion (signed with the SA private key).
 *
 * Deliberately supports ONLY RS256. The alg header is pinned before any
 * cryptography happens, so alg-confusion forgeries ("none", HS256 with the
 * public key as HMAC secret) fail on the first check.
 */
final class Jwt
{
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Strict decoder: rejects any input that is not canonical base64url. */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        $padded = strtr($data, '-_', '+/') . ($remainder > 0 ? str_repeat('=', 4 - $remainder) : '');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url data in token.');
        }
        return $decoded;
    }

    /**
     * Split and decode a compact JWT. No verification happens here.
     *
     * @return array{header:array, claims:array, signature:string, signedPart:string}
     */
    public static function parse(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token: expected three segments.');
        }
        [$headerB64, $claimsB64, $signatureB64] = $parts;

        $header = json_decode(self::base64UrlDecode($headerB64), true);
        $claims = json_decode(self::base64UrlDecode($claimsB64), true);
        if (!is_array($header) || !is_array($claims)) {
            throw new \RuntimeException('Malformed token: header/claims are not JSON objects.');
        }

        return [
            'header'     => $header,
            'claims'     => $claims,
            'signature'  => self::base64UrlDecode($signatureB64),
            'signedPart' => $headerB64 . '.' . $claimsB64,
        ];
    }

    /**
     * Verify an RS256 JWT against a set of PEM public keys or X.509 certs,
     * keyed by kid. When the token names a kid that exists in the set, only
     * that key is tried; otherwise every key is. Returns the claims.
     *
     * Time validity (exp/nbf/iat) is NOT checked here — call assertTimeValid.
     *
     * @param array<string,string> $pemKeysByKid kid => PEM (public key or certificate)
     * @throws \RuntimeException when the token is malformed, not RS256, or unsigned by any key
     */
    public static function verifyRs256(string $jwt, array $pemKeysByKid): array
    {
        $parsed = self::parse($jwt);

        $alg = $parsed['header']['alg'] ?? '';
        if ($alg !== 'RS256') {
            throw new \RuntimeException("Unsupported token algorithm: '$alg' (only RS256 is accepted).");
        }

        $kid = $parsed['header']['kid'] ?? null;
        $candidates = is_string($kid) && isset($pemKeysByKid[$kid])
            ? [$kid => $pemKeysByKid[$kid]]
            : $pemKeysByKid;
        if ($candidates === []) {
            throw new \RuntimeException('No verification keys available.');
        }

        foreach ($candidates as $pem) {
            $publicKey = openssl_pkey_get_public($pem);
            if ($publicKey === false) {
                continue; // an unparsable key must not veto the others
            }
            if (openssl_verify($parsed['signedPart'], $parsed['signature'], $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                return $parsed['claims'];
            }
        }

        throw new \RuntimeException('Token signature verification failed.');
    }

    /**
     * Assert exp/nbf/iat sanity with clock-skew leeway. exp is mandatory:
     * every token this module accepts (Google id tokens, OIDC push tokens)
     * carries one, and a missing exp would otherwise live forever.
     */
    public static function assertTimeValid(array $claims, ?int $now = null, int $leewaySeconds = 300): void
    {
        $now ??= time();

        $exp = $claims['exp'] ?? null;
        if (!is_numeric($exp)) {
            throw new \RuntimeException('Token has no expiry.');
        }
        if ($now > (int) $exp + $leewaySeconds) {
            throw new \RuntimeException('Token is expired.');
        }
        $nbf = $claims['nbf'] ?? null;
        if (is_numeric($nbf) && $now < (int) $nbf - $leewaySeconds) {
            throw new \RuntimeException('Token is not valid yet.');
        }
        $iat = $claims['iat'] ?? null;
        if (is_numeric($iat) && $now < (int) $iat - $leewaySeconds) {
            throw new \RuntimeException('Token is issued in the future.');
        }
    }

    /**
     * Sign claims as an RS256 JWT (used for the Google service-account OAuth
     * assertion). $privateKeyPem is a PKCS#8/PKCS#1 PEM private key.
     */
    public static function signRs256(array $claims, string $privateKeyPem, array $extraHeader = []): string
    {
        $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT'], $extraHeader);
        $signedPart = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new \RuntimeException('Could not load the signing key.');
        }
        $signature = '';
        if (!openssl_sign($signedPart, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing failed.');
        }

        return $signedPart . '.' . self::base64UrlEncode($signature);
    }
}
