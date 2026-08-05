<?php

namespace WHMCS\Module\Addon\VpnHoodIap;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Minimal cURL wrapper for the store adapters (Google Android Publisher, Apple App
 * Store Server API, Microsoft Collections). Always sets a User-Agent (Cloudflare and
 * some store fronts reject UA-less requests), enforces timeouts, and JSON-decodes.
 */
class Http
{
    public const USER_AGENT = 'VpnHoodIap/1.0 (+WHMCS)';

    /**
     * @param array<string,string> $headers extra headers, name => value
     * @return array{status:int, body:string, json:?array}
     * @throws \RuntimeException on transport failure
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 30
    ): array {
        $curlHeaders = ['User-Agent: ' . self::USER_AGENT];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request failed: $error ($method $url)");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode((string) $responseBody, true);
        return [
            'status' => $status,
            'body'   => (string) $responseBody,
            'json'   => is_array($json) ? $json : null,
        ];
    }

    /** POST a JSON payload. */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeoutSeconds = 30): array
    {
        $headers['Content-Type'] = 'application/json';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Could not encode the request payload as JSON.');
        }
        return self::request('POST', $url, $headers, $body, $timeoutSeconds);
    }
}
