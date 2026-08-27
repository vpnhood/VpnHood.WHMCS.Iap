<?php

namespace WHMCS\Module\Addon\VpnHoodIap;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Minimal CBOR (RFC 8949) decoder for WebAuthn payloads: the attestation object
 * and the COSE public key inside authenticator data. Definite lengths only —
 * that is all an authenticator may emit (WebAuthn §6 mandates the CTAP2
 * canonical form). Pure PHP, no extensions.
 */
final class Cbor
{
    /**
     * Decode one item and require it to consume the whole input.
     *
     * @throws \RuntimeException on malformed input or trailing bytes
     */
    public static function decode(string $bytes): mixed
    {
        [$value, $offset] = self::decodeItem($bytes, 0);
        if ($offset !== strlen($bytes)) {
            throw new \RuntimeException('CBOR item leaves trailing bytes.');
        }
        return $value;
    }

    /**
     * Decode one item starting at $offset.
     *
     * @return array{0:mixed, 1:int} the value and the offset just past it —
     *         needed for the COSE key, which sits mid-buffer in authenticator data
     * @throws \RuntimeException on malformed input
     */
    public static function decodeItem(string $bytes, int $offset): array
    {
        if ($offset >= strlen($bytes)) {
            throw new \RuntimeException('CBOR input is truncated.');
        }
        $initial = ord($bytes[$offset]);
        $offset++;
        $majorType = $initial >> 5;
        $info = $initial & 0x1f;

        [$argument, $offset] = self::readArgument($bytes, $offset, $info);

        switch ($majorType) {
            case 0: // unsigned integer
                return [$argument, $offset];
            case 1: // negative integer
                return [-1 - $argument, $offset];
            case 2: // byte string
            case 3: // text string
                if ($offset + $argument > strlen($bytes)) {
                    throw new \RuntimeException('CBOR string is truncated.');
                }
                return [substr($bytes, $offset, $argument), $offset + $argument];
            case 4: // array
                $items = [];
                for ($i = 0; $i < $argument; $i++) {
                    [$items[], $offset] = self::decodeItem($bytes, $offset);
                }
                return [$items, $offset];
            case 5: // map
                $map = [];
                for ($i = 0; $i < $argument; $i++) {
                    [$key, $offset] = self::decodeItem($bytes, $offset);
                    if (!is_int($key) && !is_string($key)) {
                        throw new \RuntimeException('CBOR map key is neither an integer nor a string.');
                    }
                    [$map[$key], $offset] = self::decodeItem($bytes, $offset);
                }
                return [$map, $offset];
            case 6: // tag — irrelevant to WebAuthn; unwrap to the tagged item
                return self::decodeItem($bytes, $offset);
            default: // 7: simple values / floats — only the simples WebAuthn can carry
                return [match ($info) {
                    20 => false,
                    21 => true,
                    22 => null,
                    default => throw new \RuntimeException("Unsupported CBOR simple/float value: $info"),
                }, $offset];
        }
    }

    /**
     * The argument that follows the initial byte: the value itself for 0–23,
     * else a 1/2/4/8-byte big-endian integer. Indefinite lengths (31) are
     * rejected — see the class doc.
     *
     * @return array{0:int, 1:int}
     */
    private static function readArgument(string $bytes, int $offset, int $info): array
    {
        if ($info < 24) {
            return [$info, $offset];
        }
        $lengths = [24 => 1, 25 => 2, 26 => 4, 27 => 8];
        $length = $lengths[$info]
            ?? throw new \RuntimeException("Unsupported CBOR additional info: $info");
        if ($offset + $length > strlen($bytes)) {
            throw new \RuntimeException('CBOR argument is truncated.');
        }
        $argument = 0;
        for ($i = 0; $i < $length; $i++) {
            $argument = $argument * 256 + ord($bytes[$offset + $i]);
        }
        return [$argument, $offset + $length];
    }
}
