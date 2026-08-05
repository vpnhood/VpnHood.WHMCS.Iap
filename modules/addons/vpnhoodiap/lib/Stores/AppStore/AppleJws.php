<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores\AppStore;

use WHMCS\Module\Addon\VpnHoodIap\Jwt;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Verifies the JWS format Apple uses for App Store Server API responses and
 * App Store Server Notifications V2: ES256, with the signing certificate
 * chain embedded in the x5c header.
 *
 * Verification order matters and is strict:
 *   1. alg must be ES256 exactly (no alg confusion);
 *   2. every x5c link must be signed by the next and be time-valid;
 *   3. the chain's root must match a PINNED Apple root (sha256 fingerprint) —
 *      an embedded chain is attacker-supplied data until it is pinned;
 *   4. only then is the payload signature checked against the LEAF key.
 *
 * The pin set is injectable for tests; production uses Apple Root CA - G3.
 */
final class AppleJws
{
    /**
     * sha256 fingerprint(s) of the DER form of trusted Apple roots.
     * Apple Root CA - G3 (https://www.apple.com/certificateauthority/).
     */
    public const APPLE_ROOT_SHA256 = [
        '63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179',
    ];

    /**
     * Verify and return the payload claims.
     *
     * @param array<int,string>|null $pinnedRootSha256 override for tests; null = Apple roots
     * @throws \RuntimeException on any verification failure
     */
    public static function verify(string $jws, ?array $pinnedRootSha256 = null, ?int $now = null): array
    {
        $now ??= time();
        $parsed = Jwt::parse($jws);

        $alg = $parsed['header']['alg'] ?? '';
        if ($alg !== 'ES256') {
            throw new \RuntimeException("Unsupported JWS algorithm: '$alg' (only ES256 is accepted).");
        }

        $x5c = $parsed['header']['x5c'] ?? null;
        if (!is_array($x5c) || $x5c === []) {
            throw new \RuntimeException('The JWS carries no x5c certificate chain.');
        }

        // -- decode the chain -------------------------------------------------
        $certs = [];
        foreach ($x5c as $index => $certB64) {
            $der = base64_decode((string) $certB64, true);
            if ($der === false) {
                throw new \RuntimeException("x5c[$index] is not valid base64.");
            }
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
            $cert = openssl_x509_read($pem);
            if ($cert === false) {
                throw new \RuntimeException("x5c[$index] is not a certificate.");
            }
            $certs[] = ['x509' => $cert, 'der' => $der, 'pem' => $pem];
        }

        // -- each link signed by the next, and time-valid ---------------------
        foreach ($certs as $index => $cert) {
            $info = openssl_x509_parse($cert['x509']);
            if (!is_array($info)) {
                throw new \RuntimeException("x5c[$index] could not be parsed.");
            }
            if ($now < (int) $info['validFrom_time_t'] || $now > (int) $info['validTo_time_t']) {
                throw new \RuntimeException("x5c[$index] is not time-valid.");
            }
            $issuerIndex = min($index + 1, count($certs) - 1); // the root vouches for itself
            $issuerKey = openssl_pkey_get_public($certs[$issuerIndex]['pem']);
            if ($issuerKey === false || openssl_x509_verify($cert['x509'], $issuerKey) !== 1) {
                throw new \RuntimeException("x5c[$index] is not signed by its issuer — broken chain.");
            }
        }

        // -- the chain must end at a pinned Apple root ------------------------
        $rootFingerprint = hash('sha256', $certs[count($certs) - 1]['der']);
        $pins = $pinnedRootSha256 ?? self::APPLE_ROOT_SHA256;
        if (!in_array($rootFingerprint, $pins, true)) {
            throw new \RuntimeException('The x5c chain does not end at a pinned Apple root.');
        }

        // -- finally: the payload signature, against the LEAF -----------------
        $leafKey = openssl_pkey_get_public($certs[0]['pem']);
        if ($leafKey === false) {
            throw new \RuntimeException('Could not load the leaf public key.');
        }
        $derSignature = self::rawSignatureToDer($parsed['signature']);
        if (openssl_verify($parsed['signedPart'], $derSignature, $leafKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('JWS signature verification failed.');
        }

        return $parsed['claims'];
    }

    // ------------------------------------------------- ES256 signature form --

    /** JWS ES256 signatures are raw r||s (64 bytes); openssl wants DER. */
    public static function rawSignatureToDer(string $raw): string
    {
        if (strlen($raw) !== 64) {
            throw new \RuntimeException('ES256 signature must be 64 raw bytes.');
        }
        $r = self::derUnsignedInteger(substr($raw, 0, 32));
        $s = self::derUnsignedInteger(substr($raw, 32));
        $content = $r . $s;
        return "\x30" . chr(strlen($content)) . $content;
    }

    /** openssl produces DER; JWS wants raw r||s (64 bytes). Used when SIGNING (App Store API client). */
    public static function derSignatureToRaw(string $der): string
    {
        $offset = 2; // SEQUENCE header (assumes short form — ES256 sigs are < 128 bytes)
        $read = static function () use ($der, &$offset): string {
            if (($der[$offset] ?? '') !== "\x02") {
                throw new \RuntimeException('Malformed DER signature.');
            }
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            return ltrim($value, "\x00");
        };
        $r = $read();
        $s = $read();
        if (strlen($r) > 32 || strlen($s) > 32) {
            throw new \RuntimeException('DER integer longer than the P-256 field.');
        }
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /** Sign compact-JWS with ES256 (used by the App Store Server API client JWT). */
    public static function signEs256(array $claims, string $privateKeyPem, array $extraHeader = []): string
    {
        $header = array_merge(['alg' => 'ES256', 'typ' => 'JWT'], $extraHeader);
        $signedPart = Jwt::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . Jwt::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new \RuntimeException('Could not load the ES256 signing key.');
        }
        $derSignature = '';
        if (!openssl_sign($signedPart, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('ES256 signing failed.');
        }
        return $signedPart . '.' . Jwt::base64UrlEncode(self::derSignatureToRaw($derSignature));
    }

    private static function derUnsignedInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    }
}
