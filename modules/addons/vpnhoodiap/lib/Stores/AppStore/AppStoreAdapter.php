<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores\AppStore;

use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Apple App Store: purchases re-fetched via the App Store Server API,
 * notifications delivered as App Store Server Notifications V2 (a signed JWS
 * whose x5c chain is verified to a pinned Apple root — see AppleJws).
 *
 * The client's proof is the StoreKit 2 signed transaction (JWS). It is used
 * only as a POINTER: the original transaction id is extracted and the state
 * re-fetched server-to-server, same rule as every store.
 *
 * Apple has no acknowledge step, so finalize() is a no-op — the
 * never-ack-before-provision refund valve does not exist here; a wedged
 * provisioning surfaces through POST /billing/purchases's error and the daily
 * reconciliation instead.
 */
class AppStoreAdapter implements StoreAdapterInterface
{
    public const STORE = 'appstore';

    /** statuses[].lastTransactions[].status → normalized state */
    private const SUBSCRIPTION_STATES = [
        1 => PurchaseRecord::STATE_ACTIVE,
        2 => PurchaseRecord::STATE_EXPIRED,
        3 => PurchaseRecord::STATE_ON_HOLD,  // billing retry (grace period already over)
        4 => PurchaseRecord::STATE_IN_GRACE, // billing grace period
        5 => PurchaseRecord::STATE_REVOKED,
    ];

    /** @var callable(array):AppStoreApiClient */
    private $apiClientFactory;
    /** @var callable(string):array verified-JWS payload decoder */
    private $jwsVerifier;
    private ?int $now;

    public function __construct(?callable $apiClientFactory = null, ?callable $jwsVerifier = null, ?int $now = null)
    {
        $this->apiClientFactory = $apiClientFactory
            ?? fn (array $app): AppStoreApiClient => AppStoreApiClient::fromApp($app, new IapRepository());
        $this->jwsVerifier = $jwsVerifier
            ?? fn (string $jws): array => AppleJws::verify($jws, null, $now);
        $this->now = $now;
    }

    public function storeId(): string
    {
        return self::STORE;
    }

    public function verifyPurchase(array $app, array $proof): PurchaseRecord
    {
        // the proof is either the SK2 signed transaction (jws) or a bare transaction id
        $originalTransactionId = '';
        if (!empty($proof['jws'])) {
            $transaction = ($this->jwsVerifier)((string) $proof['jws']);
            if (($transaction['bundleId'] ?? '') !== $app['package_name']) {
                throw new \RuntimeException('The transaction belongs to a different app.');
            }
            $originalTransactionId = (string) ($transaction['originalTransactionId']
                ?? $transaction['transactionId'] ?? '');
        } elseif (!empty($proof['transactionId'])) {
            $originalTransactionId = (string) $proof['transactionId'];
        }
        if ($originalTransactionId === '') {
            throw new \RuntimeException('The purchase proof has no transaction.');
        }

        return $this->fetchRecord($app, $originalTransactionId);
    }

    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord
    {
        return $this->fetchRecord($app, $purchaseKey);
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        $body = json_decode($rawBody, true);
        $signedPayload = is_array($body) ? (string) ($body['signedPayload'] ?? '') : '';
        if ($signedPayload === '') {
            throw new \RuntimeException('Not an App Store Server Notification V2 body.');
        }

        // the x5c verification IS the authentication (plus the secret path token upstream)
        $payload = ($this->jwsVerifier)($signedPayload);

        $notificationType = (string) ($payload['notificationType'] ?? '');
        $subtype = (string) ($payload['subtype'] ?? '');
        $messageId = (string) ($payload['notificationUUID'] ?? '');
        if ($messageId === '') {
            throw new \RuntimeException('Notification carries no notificationUUID.');
        }
        $data = (array) ($payload['data'] ?? []);

        $transaction = [];
        if (!empty($data['signedTransactionInfo'])) {
            $transaction = ($this->jwsVerifier)((string) $data['signedTransactionInfo']);
        }

        return new StoreNotification(
            self::STORE,
            $messageId,
            self::mapEvent($notificationType, $subtype),
            isset($transaction['originalTransactionId']) ? (string) $transaction['originalTransactionId'] : null,
            isset($transaction['productId']) ? (string) $transaction['productId'] : null,
            isset($data['bundleId']) ? (string) $data['bundleId'] : null,
            isset($payload['signedDate']) ? intdiv((int) $payload['signedDate'], 1000) : null,
            ['notificationType' => $notificationType, 'subtype' => $subtype, 'data' => $data]
        );
    }

    public function finalize(array $app, PurchaseRecord $record): void
    {
        // Apple has no acknowledge/consume step — nothing to do, by design.
    }

    public function listVoidedPurchaseKeys(array $app, int $sinceUnix): array
    {
        // Apple pushes REFUND/REVOKE notifications and the reconciliation cron
        // re-fetches statuses (status 5 = revoked) — no separate voided list exists.
        return [];
    }

    // ----------------------------------------------------------- internal --

    private static function mapEvent(string $type, string $subtype): string
    {
        return match ($type) {
            'TEST'                      => StoreNotification::TEST,
            'SUBSCRIBED', 'ONE_TIME_CHARGE' => StoreNotification::PURCHASED,
            'DID_RENEW'                 => $subtype === 'BILLING_RECOVERY'
                ? StoreNotification::RECOVERED : StoreNotification::RENEWED,
            'DID_FAIL_TO_RENEW'         => $subtype === 'GRACE_PERIOD'
                ? StoreNotification::IN_GRACE : StoreNotification::ON_HOLD,
            'GRACE_PERIOD_EXPIRED'      => StoreNotification::ON_HOLD,
            'DID_CHANGE_RENEWAL_STATUS' => $subtype === 'AUTO_RENEW_ENABLED'
                ? StoreNotification::RESTARTED : StoreNotification::CANCELED,
            'EXPIRED'                   => StoreNotification::EXPIRED,
            'REFUND', 'REVOKE'          => StoreNotification::REVOKED,
            default                     => StoreNotification::UNKNOWN,
        };
    }

    /** statuses first (subscriptions); transaction-info fallback (one-time products). */
    private function fetchRecord(array $app, string $originalTransactionId): PurchaseRecord
    {
        $client = ($this->apiClientFactory)($app);
        try {
            return $this->mapStatuses($originalTransactionId, $client->getSubscriptionStatuses($originalTransactionId));
        } catch (\RuntimeException $subscriptionError) {
            $info = $client->getTransactionInfo($originalTransactionId);
            if (empty($info['signedTransactionInfo'])) {
                throw $subscriptionError;
            }
            return $this->mapOneTime(($this->jwsVerifier)((string) $info['signedTransactionInfo']));
        }
    }

    private function mapStatuses(string $originalTransactionId, array $statuses): PurchaseRecord
    {
        foreach ((array) ($statuses['data'] ?? []) as $group) {
            foreach ((array) (((array) $group)['lastTransactions'] ?? []) as $last) {
                $last = (array) $last;
                if ((string) ($last['originalTransactionId'] ?? '') !== $originalTransactionId) {
                    continue;
                }
                $state = self::SUBSCRIPTION_STATES[(int) ($last['status'] ?? 0)] ?? null;
                if ($state === null) {
                    throw new \RuntimeException('Unexpected subscription status: ' . ($last['status'] ?? '(none)'));
                }
                $transaction = ($this->jwsVerifier)((string) ($last['signedTransactionInfo'] ?? ''));
                $renewal = !empty($last['signedRenewalInfo'])
                    ? ($this->jwsVerifier)((string) $last['signedRenewalInfo'])
                    : [];
                return $this->buildRecord($transaction, $state, (int) ($renewal['autoRenewStatus'] ?? 0) === 1);
            }
        }
        throw new \RuntimeException('The transaction is not part of any subscription on this app.');
    }

    private function mapOneTime(array $transaction): PurchaseRecord
    {
        $state = isset($transaction['revocationDate'])
            ? PurchaseRecord::STATE_REVOKED
            : PurchaseRecord::STATE_ACTIVE;
        return $this->buildRecord($transaction, $state, false);
    }

    private function buildRecord(array $transaction, string $state, bool $autoRenewing): PurchaseRecord
    {
        $originalTransactionId = (string) ($transaction['originalTransactionId'] ?? '');
        $productId = (string) ($transaction['productId'] ?? '');
        if ($originalTransactionId === '' || $productId === '') {
            throw new \RuntimeException('The transaction payload is incomplete.');
        }

        // price arrives in milliunits of the currency (iOS 16.4+); reconciliation only
        $amount = isset($transaction['price'])
            ? number_format(((int) $transaction['price']) / 1000, 2, '.', '')
            : null;

        return new PurchaseRecord(
            store: self::STORE,
            purchaseKey: $originalTransactionId,
            storeOrderId: isset($transaction['transactionId']) ? (string) $transaction['transactionId'] : null,
            storeProductId: $productId,
            basePlanId: '', // Apple has no base plans; the product IS the plan+cycle
            obfuscatedUid: isset($transaction['appAccountToken'])
                ? strtolower((string) $transaction['appAccountToken']) : null,
            state: $state,
            expiryTimeUnix: isset($transaction['expiresDate'])
                ? intdiv((int) $transaction['expiresDate'], 1000) : null,
            autoRenewing: $autoRenewing,
            acknowledged: true, // no acknowledge concept on Apple
            linkedPurchaseKey: null,
            isTest: ($transaction['environment'] ?? '') === 'Sandbox',
            amount: $amount,
            currency: isset($transaction['currency']) ? (string) $transaction['currency'] : null,
            raw: $transaction,
        );
    }
}
