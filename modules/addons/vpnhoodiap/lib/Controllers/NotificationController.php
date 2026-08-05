<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Controllers;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\RenewalService;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Webhook processing: authenticate (adapter), dedup (event inbox), dispatch
 * by normalized type. Google/Apple retry on 5xx, so processing failures are
 * recorded and answered 200 — the reconciliation cron is the retry, never
 * the store's redelivery loop.
 */
class NotificationController
{
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

        // inbox dedup: unique (store, message_id) — a duplicate delivery stops here
        if (!$this->recordEvent($notification, 'received', null)) {
            return ['status' => 200, 'body' => ['success' => true, 'data' => ['handled' => 'duplicate']]];
        }

        try {
            $handled = $this->dispatch($app, $adapter, $notification);
            $this->finishEvent($notification, 'processed', null);
            return ['status' => 200, 'body' => ['success' => true, 'data' => ['handled' => $handled]]];
        } catch (\Throwable $e) {
            // recorded as failed; cron replays. NEVER 5xx — the store would loop.
            $this->finishEvent($notification, 'failed', $e->getMessage());
            logModuleCall('vpnhoodiap', 'webhook', $notification->eventType, $e->getMessage(), $e->getTraceAsString());
            return ['status' => 200, 'body' => ['success' => true, 'data' => ['handled' => 'failed-recorded']]];
        }
    }

    private function dispatch(array $app, StoreAdapterInterface $adapter, StoreNotification $notification): string
    {
        $purchaseKey = $notification->purchaseKey;

        switch ($notification->eventType) {
            case StoreNotification::TEST:
                return 'test';

            case StoreNotification::PURCHASED:
            case StoreNotification::RECOVERED:
            case StoreNotification::RESTARTED:
                if ($purchaseKey === null) {
                    return 'skipped-no-key';
                }
                if ($notification->eventType !== StoreNotification::PURCHASED) {
                    $this->unsuspendService($purchaseKey, $notification->store);
                }
                $record = $adapter->refresh($app, $purchaseKey, (string) $notification->storeProductId);
                (new EntitlementService($this->repo))->redeem($app, $record, null, $adapter);
                return $notification->eventType;

            case StoreNotification::RENEWED:
                if ($purchaseKey === null) {
                    return 'skipped-no-key';
                }
                return (new RenewalService($this->repo))->renew($app, $purchaseKey, $adapter);

            case StoreNotification::CANCELED:
                // auto-renew turned off — entitled until expiry, nothing revoked
                $this->updatePurchase($notification, ['auto_renewing' => 0, 'status' => 'canceled']);
                return 'canceled';

            case StoreNotification::IN_GRACE:
                // payment problem but still entitled — keep everything running
                return 'in-grace-noted';

            case StoreNotification::ON_HOLD:
            case StoreNotification::PAUSED:
                $this->suspendService($notification, $notification->eventType === StoreNotification::ON_HOLD ? 'on_hold' : 'on_hold');
                return $notification->eventType;

            case StoreNotification::EXPIRED:
                $this->terminateService($notification, 'expired');
                return 'expired';

            case StoreNotification::REVOKED:
                $this->terminateService($notification, 'refunded');
                return 'revoked';

            default:
                return 'unknown-recorded';
        }
    }

    // ------------------------------------------------------------ actions --

    private function suspendService(StoreNotification $notification, string $status): void
    {
        $serviceId = $this->serviceIdFor($notification);
        if ($serviceId !== null) {
            localAPI('ModuleSuspend', ['serviceid' => $serviceId, 'suspendreason' => 'Store subscription payment problem']);
        }
        $this->updatePurchase($notification, ['status' => $status]);
    }

    private function unsuspendService(string $purchaseKey, string $store): void
    {
        $serviceId = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('store', $store)->where('purchase_key', $purchaseKey)->value('service_id');
        if ($serviceId !== null) {
            localAPI('ModuleUnsuspend', ['serviceid' => (int) $serviceId]);
        }
    }

    private function terminateService(StoreNotification $notification, string $status): void
    {
        $serviceId = $this->serviceIdFor($notification);
        if ($serviceId !== null) {
            localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
        }
        $this->updatePurchase($notification, ['status' => $status]);
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

    private function updatePurchase(StoreNotification $notification, array $changes): void
    {
        if ($notification->purchaseKey === null) {
            return;
        }
        Capsule::table('mod_vpnhood_iap_purchases')
            ->where('store', $notification->store)
            ->where('purchase_key', $notification->purchaseKey)
            ->update(array_merge($changes, ['updated_at' => date('Y-m-d H:i:s')]));
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

    private function finishEvent(StoreNotification $notification, string $status, ?string $error): void
    {
        Capsule::table('mod_vpnhood_iap_events')
            ->where('store', $notification->store)
            ->where('message_id', $notification->messageId)
            ->update(['status' => $status, 'error' => $error !== null ? substr($error, 0, 1000) : null]);
    }
}
