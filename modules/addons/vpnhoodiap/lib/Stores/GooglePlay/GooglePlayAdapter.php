<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores\GooglePlay;

use WHMCS\Module\Addon\VpnHoodIap\Auth\GoogleIdentityProvider;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Google Play: purchases re-fetched via the Android Publisher API
 * (subscriptionsv2 / products), RTDN delivered by Pub/Sub push whose OIDC
 * JWT is verified against Google's certs and pinned to the app's configured
 * push service account.
 *
 * The api-client factory and certs fetcher are injectable for tests; the
 * defaults build the real thing from the app row's encrypted credentials.
 */
class GooglePlayAdapter implements StoreAdapterInterface
{
    public const STORE = 'googleplay';

    /** RTDN subscriptionNotification.notificationType → normalized event. */
    private const SUBSCRIPTION_EVENTS = [
        1  => StoreNotification::RECOVERED,
        2  => StoreNotification::RENEWED,
        3  => StoreNotification::CANCELED,
        4  => StoreNotification::PURCHASED,
        5  => StoreNotification::ON_HOLD,
        6  => StoreNotification::IN_GRACE,
        7  => StoreNotification::RESTARTED,
        10 => StoreNotification::PAUSED,
        12 => StoreNotification::REVOKED,
        13 => StoreNotification::EXPIRED,
    ];

    private const SUBSCRIPTION_STATES = [
        'SUBSCRIPTION_STATE_ACTIVE'          => PurchaseRecord::STATE_ACTIVE,
        'SUBSCRIPTION_STATE_CANCELED'        => PurchaseRecord::STATE_CANCELED,
        'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => PurchaseRecord::STATE_IN_GRACE,
        'SUBSCRIPTION_STATE_ON_HOLD'         => PurchaseRecord::STATE_ON_HOLD,
        'SUBSCRIPTION_STATE_PAUSED'          => PurchaseRecord::STATE_PAUSED,
        'SUBSCRIPTION_STATE_EXPIRED'         => PurchaseRecord::STATE_EXPIRED,
        'SUBSCRIPTION_STATE_PENDING'         => PurchaseRecord::STATE_PENDING,
    ];

    /** @var callable(array):GooglePlayApiClient */
    private $apiClientFactory;
    /** @var callable():array<string,string> */
    private $certsFetcher;
    private ?int $now;

    public function __construct(?callable $apiClientFactory = null, ?callable $certsFetcher = null, ?int $now = null)
    {
        $this->apiClientFactory = $apiClientFactory
            ?? fn (array $app): GooglePlayApiClient => GooglePlayApiClient::fromApp($app, new IapRepository());
        $this->certsFetcher = $certsFetcher ?? [GoogleIdentityProvider::class, 'fetchGoogleCerts'];
        $this->now = $now;
    }

    public function storeId(): string
    {
        return self::STORE;
    }

    public function verifyPurchase(array $app, array $proof): PurchaseRecord
    {
        $purchaseToken = (string) ($proof['purchaseToken'] ?? '');
        if ($purchaseToken === '') {
            throw new \RuntimeException('The purchase proof has no purchaseToken.');
        }
        $productId = (string) ($proof['productId'] ?? '');
        return $this->fetchRecord($app, $purchaseToken, $productId);
    }

    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord
    {
        return $this->fetchRecord($app, $purchaseKey, $storeProductId);
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        $this->assertPushAuthentic($app, $headers);

        $envelope = json_decode($rawBody, true);
        $message = is_array($envelope) ? ($envelope['message'] ?? null) : null;
        if (!is_array($message) || !isset($message['messageId'], $message['data'])) {
            throw new \RuntimeException('Not a Pub/Sub push envelope.');
        }
        $rtdn = json_decode(base64_decode((string) $message['data'], true) ?: '', true);
        if (!is_array($rtdn)) {
            throw new \RuntimeException('Pub/Sub message data is not RTDN JSON.');
        }

        $messageId = (string) $message['messageId'];
        $packageName = isset($rtdn['packageName']) ? (string) $rtdn['packageName'] : null;
        $eventTime = isset($rtdn['eventTimeMillis']) ? intdiv((int) $rtdn['eventTimeMillis'], 1000) : null;

        if (isset($rtdn['testNotification'])) {
            return new StoreNotification(self::STORE, $messageId, StoreNotification::TEST, null, null, $packageName, $eventTime, $rtdn);
        }
        if (isset($rtdn['voidedPurchaseNotification'])) {
            $voided = (array) $rtdn['voidedPurchaseNotification'];
            return new StoreNotification(
                self::STORE,
                $messageId,
                StoreNotification::REVOKED,
                isset($voided['purchaseToken']) ? (string) $voided['purchaseToken'] : null,
                null,
                $packageName,
                $eventTime,
                $rtdn
            );
        }
        if (isset($rtdn['subscriptionNotification'])) {
            $sub = (array) $rtdn['subscriptionNotification'];
            $eventType = self::SUBSCRIPTION_EVENTS[(int) ($sub['notificationType'] ?? 0)] ?? StoreNotification::UNKNOWN;
            return new StoreNotification(
                self::STORE,
                $messageId,
                $eventType,
                isset($sub['purchaseToken']) ? (string) $sub['purchaseToken'] : null,
                isset($sub['subscriptionId']) ? (string) $sub['subscriptionId'] : null,
                $packageName,
                $eventTime,
                $rtdn
            );
        }
        if (isset($rtdn['oneTimeProductNotification'])) {
            $oneTime = (array) $rtdn['oneTimeProductNotification'];
            $eventType = match ((int) ($oneTime['notificationType'] ?? 0)) {
                1       => StoreNotification::PURCHASED,
                2       => StoreNotification::CANCELED,
                default => StoreNotification::UNKNOWN,
            };
            return new StoreNotification(
                self::STORE,
                $messageId,
                $eventType,
                isset($oneTime['purchaseToken']) ? (string) $oneTime['purchaseToken'] : null,
                isset($oneTime['sku']) ? (string) $oneTime['sku'] : null,
                $packageName,
                $eventTime,
                $rtdn
            );
        }

        return new StoreNotification(self::STORE, $messageId, StoreNotification::UNKNOWN, null, null, $packageName, $eventTime, $rtdn);
    }

    public function finalize(array $app, PurchaseRecord $record): void
    {
        if ($record->acknowledged) {
            return;
        }
        $client = ($this->apiClientFactory)($app);
        if (isset($record->raw['subscriptionState'])) {
            $client->acknowledgeSubscription($record->storeProductId, $record->purchaseKey);
        } else {
            $client->acknowledgeProduct($record->storeProductId, $record->purchaseKey);
        }
    }

    public function listVoidedPurchaseKeys(array $app, int $sinceUnix): array
    {
        $client = ($this->apiClientFactory)($app);
        $keys = [];
        foreach ($client->listVoidedPurchases($sinceUnix * 1000) as $voided) {
            if (!empty($voided['purchaseToken'])) {
                $keys[] = (string) $voided['purchaseToken'];
            }
        }
        return $keys;
    }

    public function stopRenewals(array $app, string $purchaseKey): bool
    {
        $client = ($this->apiClientFactory)($app);
        $client->cancelSubscription($purchaseKey);
        return true;
    }

    // ----------------------------------------------------------- internal --

    /** Subscriptions first (no product id needed); one-time products as fallback. */
    private function fetchRecord(array $app, string $purchaseToken, string $productId): PurchaseRecord
    {
        $client = ($this->apiClientFactory)($app);
        try {
            $record = $this->mapSubscription($purchaseToken, $client->getSubscription($purchaseToken));
        } catch (\RuntimeException $subscriptionError) {
            if ($productId === '') {
                throw $subscriptionError;
            }
            $record = $this->mapProduct($purchaseToken, $productId, $client->getProduct($productId, $purchaseToken));
        }
        return $this->withOrderAmount($client, $record);
    }

    /**
     * subscriptionsv2 carries no price, so the real charge (regional price and
     * currency) comes from orders.get on the current order id. Informational
     * only — a failure (missing financial permission, propagation lag on a
     * fresh order) must never fail verification, so the record simply stays
     * price-less for this cycle.
     */
    private function withOrderAmount(GooglePlayApiClient $client, PurchaseRecord $record): PurchaseRecord
    {
        if ($record->amount !== null || $record->storeOrderId === null) {
            return $record;
        }
        try {
            $paid = $client->getOrderAmount($record->storeOrderId);
        } catch (\Throwable $e) {
            return $record;
        }
        if ($paid === null) {
            return $record;
        }
        return new PurchaseRecord(
            store: $record->store,
            purchaseKey: $record->purchaseKey,
            storeOrderId: $record->storeOrderId,
            storeProductId: $record->storeProductId,
            basePlanId: $record->basePlanId,
            obfuscatedUid: $record->obfuscatedUid,
            state: $record->state,
            expiryTimeUnix: $record->expiryTimeUnix,
            autoRenewing: $record->autoRenewing,
            acknowledged: $record->acknowledged,
            linkedPurchaseKey: $record->linkedPurchaseKey,
            isTest: $record->isTest,
            amount: $paid['amount'],
            currency: $paid['currency'],
            raw: $record->raw,
        );
    }

    private function mapSubscription(string $purchaseToken, array $subscription): PurchaseRecord
    {
        $state = self::SUBSCRIPTION_STATES[(string) ($subscription['subscriptionState'] ?? '')] ?? null;
        if ($state === null) {
            throw new \RuntimeException('Unexpected subscriptionState: ' . ($subscription['subscriptionState'] ?? '(none)'));
        }

        $lineItems = (array) ($subscription['lineItems'] ?? []);
        $firstItem = (array) ($lineItems[0] ?? []);
        $productId = (string) ($firstItem['productId'] ?? '');
        if ($productId === '') {
            throw new \RuntimeException('Subscription has no line items.');
        }

        // a multi-line subscription expires when its last line does
        $expiry = null;
        $autoRenewing = false;
        foreach ($lineItems as $item) {
            $item = (array) $item;
            if (isset($item['expiryTime'])) {
                $itemExpiry = strtotime((string) $item['expiryTime']);
                $expiry = $expiry === null ? $itemExpiry : max($expiry, $itemExpiry);
            }
            if (!empty($item['autoRenewingPlan']['autoRenewEnabled'])) {
                $autoRenewing = true;
            }
        }

        return new PurchaseRecord(
            store: self::STORE,
            purchaseKey: $purchaseToken,
            storeOrderId: isset($subscription['latestOrderId']) ? (string) $subscription['latestOrderId'] : null,
            storeProductId: $productId,
            basePlanId: (string) ($firstItem['offerDetails']['basePlanId'] ?? ''),
            obfuscatedUid: isset($subscription['externalAccountIdentifiers']['obfuscatedExternalAccountId'])
                ? (string) $subscription['externalAccountIdentifiers']['obfuscatedExternalAccountId'] : null,
            state: $state,
            expiryTimeUnix: $expiry,
            autoRenewing: $autoRenewing,
            acknowledged: ($subscription['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            linkedPurchaseKey: isset($subscription['linkedPurchaseToken']) ? (string) $subscription['linkedPurchaseToken'] : null,
            isTest: isset($subscription['testPurchase']),
            amount: null, // subscriptionsv2 carries no price; store gross comes from reconciliation later
            currency: null,
            raw: $subscription,
        );
    }

    private function mapProduct(string $purchaseToken, string $productId, array $product): PurchaseRecord
    {
        $state = match ((int) ($product['purchaseState'] ?? -1)) {
            0       => PurchaseRecord::STATE_ACTIVE,
            1       => PurchaseRecord::STATE_REVOKED, // canceled one-time purchase
            2       => PurchaseRecord::STATE_PENDING,
            default => throw new \RuntimeException('Unexpected product purchaseState.'),
        };

        return new PurchaseRecord(
            store: self::STORE,
            purchaseKey: $purchaseToken,
            storeOrderId: isset($product['orderId']) ? (string) $product['orderId'] : null,
            storeProductId: $productId,
            basePlanId: '',
            obfuscatedUid: isset($product['obfuscatedExternalAccountId'])
                ? (string) $product['obfuscatedExternalAccountId'] : null,
            state: $state,
            expiryTimeUnix: null,
            autoRenewing: false,
            acknowledged: (int) ($product['acknowledgementState'] ?? 0) === 1,
            linkedPurchaseKey: null,
            isTest: isset($product['purchaseType']) && (int) $product['purchaseType'] === 0, // 0 = test
            amount: null,
            currency: null,
            raw: $product,
        );
    }

    /**
     * Pub/Sub push OIDC: Bearer JWT signed by Google, pinned issuer, the
     * app's configured push service-account email, email_verified, and — when
     * the caller provides the expected endpoint URL — the audience too.
     */
    private function assertPushAuthentic(array $app, array $headers): void
    {
        $authorization = (string) ($headers['authorization'] ?? '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m)) {
            throw new \RuntimeException('Missing Pub/Sub OIDC bearer token.');
        }

        $claims = Jwt::verifyRs256($m[1], ($this->certsFetcher)());
        Jwt::assertTimeValid($claims, $this->now);

        $issuer = (string) ($claims['iss'] ?? '');
        if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new \RuntimeException("Unexpected OIDC issuer: '$issuer'.");
        }

        $expectedEmail = strtolower(trim((string) ($app['pubsub_service_account'] ?? '')));
        if ($expectedEmail === '') {
            throw new \RuntimeException('No Pub/Sub push service account is configured for this app.');
        }
        $actualEmail = strtolower((string) ($claims['email'] ?? ''));
        if ($actualEmail !== $expectedEmail || empty($claims['email_verified'])) {
            throw new \RuntimeException('OIDC token is not from the configured push service account.');
        }

        $expectedAudience = (string) ($app['webhook_url'] ?? '');
        if ($expectedAudience !== '' && (string) ($claims['aud'] ?? '') !== $expectedAudience) {
            throw new \RuntimeException('OIDC token audience does not match the webhook endpoint.');
        }
    }
}
