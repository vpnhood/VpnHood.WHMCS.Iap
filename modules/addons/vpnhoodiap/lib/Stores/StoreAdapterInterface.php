<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores;

use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * The store extension point. Adding Apple or Microsoft = one implementation
 * of this interface (+ an identity provider + catalog rows); nothing in
 * provisioning, api.php or webhook.php changes.
 *
 * Every method receives the mod_vpnhood_iap_apps row ($app) it operates for —
 * adapters are stateless; credentials come decrypted inside $app['credentials'].
 */
interface StoreAdapterInterface
{
    /** Stable store id: googleplay | appstore | microsoft. */
    public function storeId(): string;

    /**
     * Validate a client-posted purchase proof by re-fetching the purchase
     * from the store API. The proof is only a pointer; the returned record
     * reflects the store's current truth.
     *
     * @param array $proof store-specific, e.g. googleplay: {purchaseToken, productId}
     * @throws \RuntimeException when the proof is invalid or the store API rejects it
     */
    public function verifyPurchase(array $app, array $proof): PurchaseRecord;

    /**
     * Authenticate and normalize a webhook delivery. MUST throw unless the
     * store-native proof (Pub/Sub OIDC JWT / Apple JWS) verifies — the secret
     * path token alone is not authentication.
     *
     * @param array<string,string> $headers normalized lowercase header map
     * @throws \RuntimeException when authentication fails (caller answers 401)
     */
    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification;

    /** Re-fetch the current state of a known purchase (reconciliation cron, lifecycle). */
    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord;

    /**
     * Tell the store the purchase was delivered (acknowledge/consume/finish).
     * MUST be idempotent, and MUST only ever be called after provisioning
     * succeeded — an unacknowledged purchase is the customer's refund valve.
     */
    public function finalize(array $app, PurchaseRecord $record): void;

    /**
     * Purchase keys refunded/voided since $sinceUnix (empty when the store
     * has no such API — the webhook path covers it).
     *
     * @return array<int,string>
     */
    public function listVoidedPurchaseKeys(array $app, int $sinceUnix): array;
}
