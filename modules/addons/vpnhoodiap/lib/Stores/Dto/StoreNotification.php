<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores\Dto;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * A store lifecycle notification, normalized across stores, authenticated by
 * the adapter that produced it. The body is a POINTER: processing always
 * re-fetches the purchase from the store API.
 */
final class StoreNotification
{
    // normalized event types
    public const PURCHASED = 'purchased';
    public const RENEWED = 'renewed';
    public const RECOVERED = 'recovered';   // back from on-hold
    public const RESTARTED = 'restarted';   // re-enabled auto-renew / resumed
    public const CANCELED = 'canceled';     // auto-renew off; entitled until expiry
    public const ON_HOLD = 'on_hold';
    public const IN_GRACE = 'in_grace';
    public const PAUSED = 'paused';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';       // refund / voided
    public const TEST = 'test';
    public const UNKNOWN = 'unknown';       // recorded, acked, never processed

    public function __construct(
        public readonly string $store,
        public readonly string $messageId,      // dedup anchor: unique (store, message_id)
        public readonly string $eventType,      // one of the constants above
        public readonly ?string $purchaseKey,
        public readonly ?string $storeProductId,
        public readonly ?string $packageName,   // as claimed by the payload; caller compares to the app row
        public readonly ?int $eventTimeUnix,
        public readonly array $raw,
    ) {
    }
}
