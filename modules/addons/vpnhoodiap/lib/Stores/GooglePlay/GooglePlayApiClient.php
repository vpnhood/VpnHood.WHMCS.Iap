<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores\GooglePlay;

use WHMCS\Module\Addon\VpnHoodIap\Http;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Thin Android Publisher v3 client authenticated with a service-account
 * JWT-bearer assertion. OAuth access tokens are cached in-process for the
 * token lifetime minus a safety window. The HTTP transport is injectable so
 * unit tests run the full request/response mapping with no network.
 */
class GooglePlayApiClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';
    private const BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications';
    private const TOKEN_SAFETY_WINDOW = 300;

    /** @var array{client_email:string, private_key:string, token_uri:string} */
    private array $serviceAccount;
    private string $packageName;
    /** @var callable(string,string,array,?string):array{status:int,body:string,json:?array} */
    private $http;

    /** @var array<string,array{token:string, expiresAt:int}> per client_email */
    private static array $tokenCache = [];

    public function __construct(array $serviceAccount, string $packageName, ?callable $http = null)
    {
        foreach (['client_email', 'private_key', 'token_uri'] as $required) {
            if (empty($serviceAccount[$required]) || !is_string($serviceAccount[$required])) {
                throw new \RuntimeException("Service-account JSON is missing '$required'.");
            }
        }
        $this->serviceAccount = $serviceAccount;
        $this->packageName = $packageName;
        $this->http = $http ?? [Http::class, 'request'];
    }

    /** Build a client for an app row (decrypts the stored credentials). */
    public static function fromApp(array $app, IapRepository $repo): self
    {
        $credentialsJson = $repo->decryptSecret((string) ($app['credentials'] ?? ''));
        $serviceAccount = json_decode($credentialsJson, true);
        if (!is_array($serviceAccount)) {
            throw new \RuntimeException('The stored Google credentials are not valid service-account JSON.');
        }
        return new self($serviceAccount, (string) $app['package_name']);
    }

    // ------------------------------------------------------------- reads --

    /** purchases.subscriptionsv2.get — the modern subscription state document. */
    public function getSubscription(string $purchaseToken): array
    {
        return $this->call('GET', '/purchases/subscriptionsv2/tokens/' . rawurlencode($purchaseToken));
    }

    /** purchases.products.get — one-time products. */
    public function getProduct(string $productId, string $purchaseToken): array
    {
        return $this->call(
            'GET',
            '/purchases/products/' . rawurlencode($productId) . '/tokens/' . rawurlencode($purchaseToken)
        );
    }

    /**
     * orders.get — what the buyer actually paid for one store order (regional
     * price, currency, refund state). Purely informational for this module:
     * accounting always books the WHMCS product price; this is captured onto
     * the purchase row so support can see the real charge without Play Console.
     * Requires the "View financial data" Play permission (already mandatory
     * for the voided-purchases sweep).
     *
     * @return array{amount:string, currency:string}|null null when the order
     *         carries no total (should not happen for processed orders)
     */
    public function getOrderAmount(string $orderId): ?array
    {
        $order = $this->call('GET', '/orders/' . rawurlencode($orderId));
        $total = (array) ($order['total'] ?? []);
        if (!isset($total['currencyCode'])) {
            return null;
        }
        return [
            'amount'   => self::formatMoney((string) ($total['units'] ?? '0'), (int) ($total['nanos'] ?? 0)),
            'currency' => (string) $total['currencyCode'],
        ];
    }

    /** google.type.Money (units + nanos) → plain decimal string ("46.99"). */
    public static function formatMoney(string $units, int $nanos): string
    {
        $sign = str_starts_with($units, '-') || $nanos < 0 ? '-' : '';
        $units = ltrim($units, '-');
        $cents = (int) round(abs($nanos) / 10_000_000); // nanos → hundredths
        if ($cents >= 100) { // rounding overflow: .999999999 → +1 unit
            $units = (string) ((int) $units + intdiv($cents, 100));
            $cents %= 100;
        }
        return sprintf('%s%s.%02d', $sign, $units === '' ? '0' : $units, $cents);
    }

    /**
     * purchases.voidedpurchases.list — refunded/voided purchases since a time.
     * type=1 includes both one-time and subscription voids. Follows pagination.
     *
     * @return array<int,array> raw voidedPurchase resources
     */
    public function listVoidedPurchases(int $startTimeMillis): array
    {
        $all = [];
        $pageToken = null;
        do {
            $query = 'startTime=' . $startTimeMillis . '&type=1' . ($pageToken !== null ? '&token=' . rawurlencode($pageToken) : '');
            $page = $this->call('GET', '/purchases/voidedpurchases?' . $query);
            foreach ((array) ($page['voidedPurchases'] ?? []) as $voided) {
                $all[] = (array) $voided;
            }
            $pageToken = $page['tokenPagination']['nextPageToken'] ?? null;
        } while ($pageToken !== null);
        return $all;
    }

    // ------------------------------------------------------ acknowledges --

    /** purchases.subscriptions.acknowledge. Idempotent: "already acknowledged" is success. */
    public function acknowledgeSubscription(string $subscriptionId, string $purchaseToken): void
    {
        $this->callAcknowledge(
            '/purchases/subscriptions/' . rawurlencode($subscriptionId)
            . '/tokens/' . rawurlencode($purchaseToken) . ':acknowledge'
        );
    }

    /** purchases.products.acknowledge. Idempotent like the subscription variant. */
    public function acknowledgeProduct(string $productId, string $purchaseToken): void
    {
        $this->callAcknowledge(
            '/purchases/products/' . rawurlencode($productId)
            . '/tokens/' . rawurlencode($purchaseToken) . ':acknowledge'
        );
    }

    /** purchases.products.consume — for consumable one-time products. */
    public function consumeProduct(string $productId, string $purchaseToken): void
    {
        $this->call(
            'POST',
            '/purchases/products/' . rawurlencode($productId)
            . '/tokens/' . rawurlencode($purchaseToken) . ':consume',
            '{}'
        );
    }

    /**
     * purchases.subscriptionsv2.cancel — developer-initiated cancellation.
     * DEVELOPER_REQUESTED_STOP_PAYMENTS stops future charges; the subscription
     * stays valid until its current expiry, so the buyer loses nothing paid for.
     */
    public function cancelSubscription(string $purchaseToken): void
    {
        $this->call(
            'POST',
            '/purchases/subscriptionsv2/tokens/' . rawurlencode($purchaseToken) . ':cancel',
            json_encode(['cancellationType' => 'DEVELOPER_REQUESTED_STOP_PAYMENTS'])
        );
    }

    // ----------------------------------------------------------- plumbing --

    private function callAcknowledge(string $path): void
    {
        try {
            $this->call('POST', $path, '{}');
        } catch (\RuntimeException $e) {
            // Google answers 400 "purchase … already acknowledged" on repeats —
            // that is the idempotent success this pipeline depends on.
            if (stripos($e->getMessage(), 'already') === false) {
                throw $e;
            }
        }
    }

    /** @return array decoded JSON body ([] for empty 2xx responses) */
    private function call(string $method, string $pathAndQuery, ?string $body = null): array
    {
        $url = self::BASE . '/' . rawurlencode($this->packageName) . $pathAndQuery;
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken()];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = ($this->http)($method, $url, $headers, $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $reason = (string) ($response['json']['error']['message'] ?? substr($response['body'], 0, 300));
            throw new \RuntimeException("Google Play API error (HTTP {$response['status']}): $reason");
        }
        return $response['json'] ?? [];
    }

    private function accessToken(): string
    {
        $cacheKey = $this->serviceAccount['client_email'];
        $cached = self::$tokenCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expiresAt'] > time() + self::TOKEN_SAFETY_WINDOW) {
            return $cached['token'];
        }

        $now = time();
        $assertion = Jwt::signRs256([
            'iss'   => $this->serviceAccount['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $this->serviceAccount['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], $this->serviceAccount['private_key']);

        $response = ($this->http)(
            'POST',
            $this->serviceAccount['token_uri'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ])
        );
        $token = (string) ($response['json']['access_token'] ?? '');
        if ($response['status'] !== 200 || $token === '') {
            throw new \RuntimeException('Google OAuth token exchange failed (HTTP ' . $response['status'] . ').');
        }
        $expiresIn = (int) ($response['json']['expires_in'] ?? 3600);
        self::$tokenCache[$cacheKey] = ['token' => $token, 'expiresAt' => $now + $expiresIn];
        return $token;
    }
}
