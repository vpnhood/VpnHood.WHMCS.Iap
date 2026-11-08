<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Controllers;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\RenewalService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\TermSync;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Webhook processing: authenticate (adapter), dedup (event inbox), dispatch
 * by normalized type. Google/Apple retry on 5xx, so a processing failure is
 * recorded and answered 200, never left to the store's redelivery loop.
 * Nothing replays it: the nightly reconciliation re-drives only purchases
 * already provisioned, so one that failed before provisioning waits for the
 * app's own redeem, and the digest reports it.
 *
 * The one exception is work that only a redelivery can redo (code
 * IapRepository::RETRY_LATER): a store refund that could not even be recorded,
 * a purchase busy in another request, or a revocation arriving before this
 * install has its refund ledger. Its inbox row is forgotten and the answer is
 * 503, so the store sends it again; Pub/Sub retries with backoff for days, and
 * Apple at 1, 12, 24, 48 and 72 hours. For the same reason a delivery whose
 * first copy is still unfinished is answered 503, not "duplicate", and one
 * abandoned by a request that died is taken over after ABANDONED_AFTER.
 */
class NotificationController
{
    /** Seconds after which a delivery still unfinished counts as abandoned: its request died. */
    private const ABANDONED_AFTER = 600;

    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * @return array{status:int, body:array} HTTP response to send
     */
    public function handle(array $app, StoreAdapterInterface $adapter, array $headers, string $rawBody, array $query): array
    {
        try {
            $notification = $adapter->parseNotification($app, $headers, $rawBody, $query);
        } catch (\RuntimeException $e) {
            $this->repo->log(null, 'webhook', (string) ($headers['x-real-ip'] ?? ''), 401, substr($rawBody, 0, 2000), $e->getMessage());
            return ['status' => 401, 'body' => ['success' => false, 'error' => 'Unauthorized.']];
        }

        // tenant guard: the payload's package must be the app the token selected
        if ($notification->packageName !== null && $notification->packageName !== $app['package_name']) {
            $this->recordEvent($notification, 'skipped', 'payload package does not match this app');
            return ['status' => 200, 'body' => ['success' => true, 'data' => ['handled' => 'skipped-package-mismatch']]];
        }

        // a revocation needs the refund ledger, which an install activated before it gets by hand:
        // until then the store keeps it, and sends it again
        if ($notification->eventType === StoreNotification::REVOKED && !RefundService::ledgerReady()) {
            $this->repo->log(null, 'webhook', '', 503, ['message' => $notification->messageId], 'no refund ledger on this install yet');
            return self::retryLater();
        }

        // inbox dedup: unique (store, message_id). A delivery seen before is a duplicate once the first
        // one finished; one still unfinished (another request working on it, or one that died there)
        // gets 503, so a redelivery never meets a false "duplicate" while the work is not done
        if (!$this->recordEvent($notification, 'received', null)) {
            $claim = $this->claimUnfinished($notification);
            if ($claim === 'finished') {
                return ['status' => 200, 'body' => ['success' => true, 'data' => ['handled' => 'duplicate']]];
            }
            if ($claim === 'busy') {
                return self::retryLater();
            }
            // claimed: an abandoned delivery, taken over as this one's own
        }

        try {
            $handled = $this->dispatch($app, $adapter, $notification);
            $this->finishEvent($notification, 'processed', null);
            return ['status' => 200, 'body' => ['success' => true, 'data' => ['handled' => $handled]]];
        } catch (\Throwable $e) {
            logModuleCall('vpnhoodiap', 'webhook', $notification->eventType, $e->getMessage(), $e->getTraceAsString());
            if ($e->getCode() === IapRepository::RETRY_LATER) {
                // only a redelivery redoes it: forget this delivery, so the next one is not a duplicate
                // (should forgetting fail, the row stays unfinished, and is taken over once abandoned)
                try {
                    $this->forgetEvent($notification);
                } catch (\Throwable) {
                }
                return self::retryLater();
            }
            // recorded as failed for the digest. NEVER 5xx — the store would loop.
            $this->finishEvent($notification, 'failed', $e->getMessage());
            return ['status' => 200, 'body' => ['success' => true, 'data' => ['handled' => 'failed-recorded']]];
        }
    }

    /** @return array{status:int, body:array} */
    private static function retryLater(): array
    {
        return ['status' => 503, 'body' => ['success' => false, 'error' => 'Try again later.']];
    }

    private function dispatch(array $app, StoreAdapterInterface $adapter, StoreNotification $notification): string
    {
        $purchaseKey = $notification->purchaseKey;

        switch ($notification->eventType) {
            case StoreNotification::TEST:
                return 'test';

            case StoreNotification::PURCHASED:
            case StoreNotification::RESTARTED:
                // a purchase, or auto-renew switched back on: redeem is idempotent — a live
                // service is reused (re-enabled if it was held), never doubled
                if ($purchaseKey === null) {
                    return 'skipped-no-key';
                }
                $record = $adapter->refresh($app, $purchaseKey, (string) $notification->storeProductId);
                (new EntitlementService($this->repo))->redeem($app, $record, null, $adapter);
                return $notification->eventType;

            case StoreNotification::RENEWED:
            case StoreNotification::RECOVERED:
                // the store charged (again). After a hold the charge lands on the suspended
                // service, which the renewal re-enables — a recovery was once a redeem, and
                // the held row failed its replay guard into a second service.
                if ($purchaseKey === null) {
                    return 'skipped-no-key';
                }
                $handled = (new RenewalService($this->repo))->renew($app, $purchaseKey, $adapter);
                return $notification->eventType === StoreNotification::RECOVERED ? 'recovered-' . $handled : $handled;

            case StoreNotification::CANCELED:
                // auto-renew turned off — entitled until expiry, nothing revoked, and the row
                // stays provisioned: the store is still billing the time already paid. A status
                // of its own made the next restore or RESTARTED provision a second service.
                $this->updatePurchase($notification, ['auto_renewing' => 0]);
                return 'canceled';

            case StoreNotification::IN_GRACE:
                // payment problem but still entitled: the store extends the paid time through
                // the grace period, and the term follows it so the code does not die while the
                // store retries the charge
                if ($purchaseKey === null) {
                    return 'skipped-no-key';
                }
                $serviceId = $this->serviceIdFor($notification);
                if ($serviceId === null) {
                    return 'in-grace-noted';
                }
                $record = $adapter->refresh($app, $purchaseKey, (string) $notification->storeProductId);
                $this->updatePurchase($notification, [
                    'auto_renewing' => $record->autoRenewing ? 1 : 0,
                    'expiry_time'   => $record->expiryTimeUnix !== null ? date('Y-m-d H:i:s', $record->expiryTimeUnix) : null,
                ]);
                return 'in-grace-' . (new TermSync($this->repo))->sync($serviceId, $record);

            case StoreNotification::ON_HOLD:
            case StoreNotification::PAUSED:
                if ($purchaseKey === null) {
                    return 'skipped-no-key';
                }
                return $this->suspendService($app, $adapter, $notification);

            case StoreNotification::EXPIRED:
                // ended under the purchase's lifecycle lock, and only while the store, read fresh, still
                // says so: a renewal recorded meanwhile keeps its service. A revocation's trailing EXPIRED
                // never relabels the refunded purchase (it is no longer open)
                $row = $purchaseKey === null ? null : Capsule::table('mod_vpnhood_iap_purchases')
                    ->where('store', $notification->store)->where('purchase_key', $purchaseKey)->first();
                if ($row === null) {
                    return 'expired-unknown-purchase';
                }
                return 'expired-' . (new RefundService($this->repo))->endEntitlement((array) $row,
                    fn () => $adapter->refresh($app, $purchaseKey, (string) $notification->storeProductId),
                    $notification->eventType, true, 'expired');

            case StoreNotification::REVOKED:
                try {
                    $row = $purchaseKey === null ? null : Capsule::table('mod_vpnhood_iap_purchases')
                        ->where('store', $notification->store)
                        ->where('purchase_key', $purchaseKey)
                        ->first();
                } catch (\Throwable $e) {
                    // nothing of this refund is recorded yet: only a redelivery brings it back
                    throw new \RuntimeException('The revoked purchase could not be read: ' . $e->getMessage(),
                        IapRepository::RETRY_LATER, $e);
                }
                if ($row === null) {
                    return 'revoked-unknown-purchase';
                }
                return $this->revoke($app, $adapter, $notification, (array) $row);

            default:
                return 'unknown-recorded';
        }
    }

    /**
     * The store took a purchase back (lifecycle §8), settled in its two halves (RefundService). The
     * money: the charge the notification names is recorded and booked; one naming none books nothing
     * (Google's voided notification names the order). The service: ended only when the store, read
     * fresh, no longer grants the purchase. Both halves run whatever the other did; then a refund not
     * even recorded, or a busy purchase, asks for a redelivery (RETRY_LATER), and any other failure
     * fails the event: a refund WHMCS refused stays due on the ledger, retried nightly, and a service
     * not ended keeps the purchase open for the nightly refresh.
     */
    private function revoke(array $app, StoreAdapterInterface $adapter, StoreNotification $notification, array $row): string
    {
        $refunds = new RefundService($this->repo);
        $failures = [];
        $money = 'unnamed';
        $recorded = true;
        if ($notification->refundedOrderId !== null) {
            try {
                // fresh: the inbox let it through, so it is no replay
                $money = $refunds->recordRefund($row, $notification->refundedOrderId, $notification->refundShare, 'webhook', true)['state'];
            } catch (\Throwable $e) {
                $failures[] = $e;
                $money = 'failed';
                $recorded = $e->getCode() !== IapRepository::RETRY_LATER;
            }
        }
        try {
            $service = $refunds->endEntitlement(
                $row,
                fn () => $adapter->refresh($app, (string) $notification->purchaseKey, (string) $notification->storeProductId),
                $notification->eventType,
                $recorded
            );
        } catch (\Throwable $e) {
            $failures[] = $e;
            $service = 'failed';
        }
        foreach ($failures as $failure) {
            if ($failure->getCode() === IapRepository::RETRY_LATER) {
                throw $failure;
            }
        }
        if ($failures !== []) {
            throw $failures[0];
        }
        return "revoked-$money-$service";
    }

    // ------------------------------------------------------------ actions --

    /**
     * A payment hold or a pause: the service is suspended under the purchase's lifecycle lock, which
     * its renewals hold too, and only while the store, read fresh, does not grant the purchase: a
     * recovery or renewal recorded meanwhile, or a hold notification arriving after the recovery, keeps
     * the service. A purchase the store has ended by then is ended (RefundService::endEntitlement), and
     * one already closed here (refunded, expired, failed) is left as it is.
     *
     * @return string the event type when held; '<event>-kept' when the store still grants it; '<event>-closed'
     */
    private function suspendService(array $app, StoreAdapterInterface $adapter, StoreNotification $notification): string
    {
        $purchaseKey = (string) $notification->purchaseKey;
        return IapRepository::withPurchaseLock($notification->store, $purchaseKey,
            function () use ($app, $adapter, $notification, $purchaseKey): string {
                $row = Capsule::table('mod_vpnhood_iap_purchases')
                    ->where('store', $notification->store)->where('purchase_key', $purchaseKey)->first();
                if ($row !== null && !in_array($row->status, ['pending', 'provisioned', 'canceled', 'on_hold'], true)) {
                    return $notification->eventType . '-closed';
                }
                $record = $adapter->refresh($app, $purchaseKey, (string) $notification->storeProductId);
                if ($record->isEntitled()) {
                    return $notification->eventType . '-kept';
                }
                if ($row !== null && in_array($record->state, [PurchaseRecord::STATE_EXPIRED, PurchaseRecord::STATE_REVOKED], true)) {
                    // a late hold for a purchase the store has ended: ended as such (with its refund, if
                    // revoked), never parked on hold, which the nightly expiry would pass over
                    return $notification->eventType . '-' . (new RefundService($this->repo))->endEntitlement((array) $row,
                        fn () => $record, $notification->eventType, true, 'expired');
                }
                $serviceId = $row !== null && $row->service_id !== null ? (int) $row->service_id : null;
                if ($serviceId !== null) {
                    $result = localAPI('ModuleSuspend', ['serviceid' => $serviceId, 'suspendreason' => 'Store subscription payment problem']);
                    if (($result['result'] ?? '') !== 'success') {
                        $this->repo->alert("vpnhoodiap: suspending service #$serviceId ({$notification->eventType}) failed: "
                            . json_encode($result) . ' — its code stays enabled while the store is unpaid.');
                    }
                }
                $this->updatePurchase($notification, ['status' => 'on_hold']);
                return $notification->eventType;
            });
    }

    private function serviceIdFor(StoreNotification $notification): ?int
    {
        if ($notification->purchaseKey === null) {
            return null;
        }
        $serviceId = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('store', $notification->store)
            ->where('purchase_key', $notification->purchaseKey)
            ->value('service_id');
        return $serviceId !== null ? (int) $serviceId : null;
    }

    /** @param string[] $keepStatuses statuses the update must not overwrite */
    private function updatePurchase(StoreNotification $notification, array $changes, array $keepStatuses = []): void
    {
        if ($notification->purchaseKey === null) {
            return;
        }
        $query = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('store', $notification->store)
            ->where('purchase_key', $notification->purchaseKey);
        if ($keepStatuses !== []) {
            $query->whereNotIn('status', $keepStatuses);
        }
        $query->update(array_merge($changes, ['updated_at' => date('Y-m-d H:i:s')]));
    }

    // ------------------------------------------------------------- events --

    /** @return bool false when this (store, message_id) was already recorded (duplicate) */
    private function recordEvent(StoreNotification $notification, string $status, ?string $error): bool
    {
        try {
            Capsule::table('mod_vpnhood_iap_events')->insert([
                'store'        => $notification->store,
                'message_id'   => $notification->messageId,
                'event_type'   => $notification->eventType,
                'purchase_key' => $notification->purchaseKey,
                'status'       => $status,
                'error'        => $error,
                'raw'          => json_encode($notification->raw),
                'event_time'   => $notification->eventTimeUnix !== null ? date('Y-m-d H:i:s', $notification->eventTimeUnix) : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false; // unique (store, message_id) — duplicate delivery
        }
    }

    /**
     * A delivery whose (store, message_id) is in the inbox already: finished (processed, skipped or
     * failed: a duplicate), busy (still received: another request is working on it), or claimed (still
     * received, but older than ABANDONED_AFTER: the request working on it died, and this delivery takes
     * it over). created_at is the claim's time: a claim moves it.
     *
     * @return string finished | busy | claimed
     */
    private function claimUnfinished(StoreNotification $notification): string
    {
        $event = Capsule::table('mod_vpnhood_iap_events')
            ->where('store', $notification->store)->where('message_id', $notification->messageId)
            ->first(['id', 'status']);
        if ($event === null) {
            return 'busy'; // forgotten meanwhile: the next delivery records it
        }
        if ($event->status !== 'received') {
            return 'finished';
        }
        $claimed = Capsule::table('mod_vpnhood_iap_events')
            ->where('id', $event->id)->where('status', 'received')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::ABANDONED_AFTER))
            ->update(['created_at' => date('Y-m-d H:i:s')]);
        return $claimed === 1 ? 'claimed' : 'busy';
    }

    /** A delivery only a redelivery can redo: its inbox row goes, so the redelivery is not taken for a duplicate. */
    private function forgetEvent(StoreNotification $notification): void
    {
        Capsule::table('mod_vpnhood_iap_events')
            ->where('store', $notification->store)
            ->where('message_id', $notification->messageId)
            ->delete();
    }

    private function finishEvent(StoreNotification $notification, string $status, ?string $error): void
    {
        Capsule::table('mod_vpnhood_iap_events')
            ->where('store', $notification->store)
            ->where('message_id', $notification->messageId)
            ->update(['status' => $status, 'error' => $error !== null ? substr($error, 0, 1000) : null]);
    }
}
