<?php

namespace WHMCS\Module\Addon\VpnHoodIap;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * JWK → PEM. Apple publishes its sign-in keys as an RFC 7517 JWK set
 * (modulus/exponent), unlike Google's X.509 PEM endpoint — openssl can't load
 * a JWK directly, so the RSA public key is assembled as a DER
 * SubjectPublicKeyInfo by hand. Pure PHP, no extensions beyond openssl.
 */
final class Jwk
{
    /**
     * Build a PEM public key from an RSA JWK's base64url n and e.
     *
     * @throws \RuntimeException when the JWK members are not decodable
     */
    public static function rsaToPem(string $modulusB64u, string $exponentB64u): string
    {
        $modulus = Jwt::base64UrlDecode($modulusB64u);
        $exponent = Jwt::base64UrlDecode($exponentB64u);
        if ($modulus === '' || $exponent === '') {
            throw new \RuntimeException('JWK modulus/exponent is empty.');
        }

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = self::derSequence(self::derUnsignedInteger($modulus) . self::derUnsignedInteger($exponent));

        // AlgorithmIdentifier for rsaEncryption (OID 1.2.840.113549.1.1.1) + NULL params
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithm === false) {
            throw new \RuntimeException('Could not build the algorithm identifier.');
        }

        // SubjectPublicKeyInfo ::= SEQUENCE { algorithm, subjectPublicKey BIT STRING }
        $bitString = "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = self::derSequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** @param array $jwks decoded {keys:[...]} document @return array<string,string> kid => PEM (RSA sig keys only) */
    public static function setToPems(array $jwks): array
    {
        $pems = [];
        foreach ((array) ($jwks['keys'] ?? []) as $key) {
            $key = (array) $key;
            if (($key['kty'] ?? '') !== 'RSA' || empty($key['kid']) || empty($key['n']) || empty($key['e'])) {
                continue;
            }
            $pems[(string) $key['kid']] = self::rsaToPem((string) $key['n'], (string) $key['e']);
        }
        if ($pems === []) {
            throw new \RuntimeException('The JWK set contains no usable RSA keys.');
        }
        return $pems;
    }

    /**
     * Build a PEM public key from a P-256 point's raw x and y (32 bytes each,
     * shorter accepted and left-padded). Used for WebAuthn restore-credential
     * keys, which arrive as COSE EC2 coordinates.
     *
     * @throws \RuntimeException when a coordinate does not fit the curve field
     */
    public static function ecP256ToPem(string $x, string $y): string
    {
        foreach (['x' => &$x, 'y' => &$y] as $name => &$coordinate) {
            $coordinate = ltrim($coordinate, "\x00");
            if ($coordinate === '' || strlen($coordinate) > 32) {
                throw new \RuntimeException("EC coordinate $name is not a P-256 field element.");
            }
            $coordinate = str_pad($coordinate, 32, "\x00", STR_PAD_LEFT);
        }
        unset($coordinate);

        // AlgorithmIdentifier: id-ecPublicKey (1.2.840.10045.2.1) + prime256v1 (1.2.840.10045.3.1.7)
        $algorithm = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
        if ($algorithm === false) {
            throw new \RuntimeException('Could not build the algorithm identifier.');
        }

        // subjectPublicKey: the uncompressed point 04 || x || y as a BIT STRING
        $point = "\x04" . $x . $y;
        $bitString = "\x03" . self::derLength(strlen($point) + 1) . "\x00" . $point;
        $spki = self::derSequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    // ---------------------------------------------------------------- DER --

    /** INTEGER from unsigned big-endian bytes (leading 0x00 when the high bit is set). */
    private static function derUnsignedInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
