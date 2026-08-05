<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores\Dto;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * A store purchase, normalized across stores. Always built from a fresh
 * store-API fetch — never from a client proof or webhook body alone.
 */
final class PurchaseRecord
{
    // normalized states
    public const STATE_ACTIVE = 'active';       // entitled
    public const STATE_PENDING = 'pending';     // payment not complete yet
    public const STATE_CANCELED = 'canceled';   // auto-renew off, entitled until expiry
    public const STATE_IN_GRACE = 'in_grace';   // payment problem, still entitled
    public const STATE_ON_HOLD = 'on_hold';     // payment problem, NOT entitled
    public const STATE_PAUSED = 'paused';       // user-paused, NOT entitled
    public const STATE_EXPIRED = 'expired';
    public const STATE_REVOKED = 'revoked';     // refunded / voided

    public function __construct(
        public readonly string $store,
        public readonly string $purchaseKey,       // googleplay purchaseToken / appstore originalTransactionId
        public readonly ?string $storeOrderId,     // googleplay orderId (changes per renewal)
        public readonly string $storeProductId,
        public readonly string $basePlanId,        // googleplay base plan; '' elsewhere
        public readonly ?string $obfuscatedUid,    // must equal the session's external_uid
        public readonly string $state,             // one of the STATE_* constants
        public readonly ?int $expiryTimeUnix,
        public readonly bool $autoRenewing,
        public readonly bool $acknowledged,
        public readonly ?string $linkedPurchaseKey, // superseded purchase (resubscribe/upgrade)
        public readonly bool $isTest,
        public readonly ?string $amount,           // store gross, reconciliation only
        public readonly ?string $currency,
        public readonly array $raw,
    ) {
    }

    /** Whether this purchase currently grants the entitlement. */
    public function isEntitled(): bool
    {
        return in_array($this->state, [self::STATE_ACTIVE, self::STATE_CANCELED, self::STATE_IN_GRACE], true)
            && ($this->expiryTimeUnix === null || $this->expiryTimeUnix > time());
    }
}
