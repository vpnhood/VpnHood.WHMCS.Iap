<?php

/**
 * VpnHood! IAP — daily maintenance (DailyCronJob):
 *
 *   1. Reconciliation: re-fetch every open purchase from its store — the
 *      self-healing net under dropped/failed webhooks.
 *   2. Voided-purchases sweep: store-side refunds terminate the service even
 *      when the webhook never arrived.
 *   3. Hygiene: purge stale sessions, clear raw payloads past retention.
 *   4. Ops digest: parked/failed purchases and failed events mailed to the
 *      configured admin address.
 *
 * Every step is fenced: a store/API failure logs and moves on — the cron
 * must never take WHMCS's daily run down with it.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('DailyCronJob', 1, function () {
    $moduleDir = ROOTDIR . '/modules/addons/vpnhoodiap';
    if (!file_exists($moduleDir . '/lib/IapRepository.php')) {
        return;
    }
    require_once $moduleDir . '/lib/ApiException.php';
    require_once $moduleDir . '/lib/Http.php';
    require_once $moduleDir . '/lib/Jwt.php';
    require_once $moduleDir . '/lib/IapRepository.php';
    require_once $moduleDir . '/lib/Auth/IdentityProviderInterface.php';
    require_once $moduleDir . '/lib/Auth/GoogleIdentityProvider.php';
    require_once $moduleDir . '/lib/Auth/SessionService.php';
    require_once $moduleDir . '/lib/Stores/Dto/PurchaseRecord.php';
    require_once $moduleDir . '/lib/Stores/Dto/StoreNotification.php';
    require_once $moduleDir . '/lib/Stores/StoreAdapterInterface.php';
    require_once $moduleDir . '/lib/Stores/StoreAdapterRegistry.php';
    require_once $moduleDir . '/lib/Stores/GooglePlay/GooglePlayApiClient.php';
    require_once $moduleDir . '/lib/Stores/GooglePlay/GooglePlayAdapter.php';
    require_once $moduleDir . '/lib/Jwk.php';
    require_once $moduleDir . '/lib/Stores/AppStore/AppleJws.php';
    require_once $moduleDir . '/lib/Stores/AppStore/AppStoreApiClient.php';
    require_once $moduleDir . '/lib/Stores/AppStore/AppStoreAdapter.php';
    require_once $moduleDir . '/lib/Provisioning/AccountService.php';
    require_once $moduleDir . '/lib/Provisioning/ClientProvisioner.php';
    require_once $moduleDir . '/lib/Provisioning/OrderProvisioner.php';
    require_once $moduleDir . '/lib/Provisioning/DeliveryReader.php';
    require_once $moduleDir . '/lib/Provisioning/EntitlementService.php';
    require_once $moduleDir . '/lib/Provisioning/RefundService.php';
    require_once $moduleDir . '/lib/Provisioning/RenewalService.php';

    if (!\WHMCS\Module\Addon\VpnHoodIap\IapRepository::isModuleActive()) {
        return;
    }
    $repo = new \WHMCS\Module\Addon\VpnHoodIap\IapRepository();

    // -- 1+2: per-app reconciliation + voided sweep --------------------------
    foreach ($repo->allApps() as $app) {
        if ($app['status'] !== 'active' || empty($app['credentials'])) {
            continue;
        }
        try {
            $adapter = \WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterRegistry::get((string) $app['store']);
        } catch (\Throwable $e) {
            continue; // store not implemented yet
        }

        // open purchases: refresh against the store, re-drive lifecycle drift
        $open = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('app_id', $app['id'])
            ->whereIn('status', ['provisioned', 'canceled', 'on_hold', 'awaiting_email_verification', 'pending'])
            ->orderBy('id')->limit(500)
            ->get()->map(fn ($row) => (array) $row)->all();
        foreach ($open as $purchase) {
            try {
                $record = $adapter->refresh($app, (string) $purchase['purchase_key'], '');
                $changes = [
                    'auto_renewing' => $record->autoRenewing ? 1 : 0,
                    'expiry_time'   => $record->expiryTimeUnix !== null ? date('Y-m-d H:i:s', $record->expiryTimeUnix) : null,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ];
                // expired on the store but still provisioned here → terminate (with grace)
                $graceDays = max(0, (int) $repo->setting('TerminateGraceDays'));
                if (
                    $purchase['status'] === 'provisioned'
                    && !$record->isEntitled()
                    && $record->expiryTimeUnix !== null
                    && $record->expiryTimeUnix < time() - $graceDays * 86400
                ) {
                    if ($purchase['service_id'] !== null) {
                        localAPI('ModuleTerminate', ['serviceid' => (int) $purchase['service_id']]);
                    }
                    $changes['status'] = $record->state === \WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord::STATE_REVOKED
                        ? 'refunded' : 'expired';
                }
                Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchase['id'])->update($changes);
            } catch (\Throwable $e) {
                $repo->log(null, 'cron.reconcile', '', 0, ['purchase' => $purchase['id']], $e->getMessage());
            }
        }

        // voided sweep: last 30 days
        try {
            foreach ($adapter->listVoidedPurchaseKeys($app, time() - 30 * 86400) as $voidedKey) {
                $row = Capsule::table('mod_vpnhood_iap_purchases')
                    ->where('store', $app['store'])->where('purchase_key', $voidedKey)->first();
                if ($row === null || $row->status === 'refunded') {
                    continue;
                }
                if ($row->service_id !== null) {
                    localAPI('ModuleTerminate', ['serviceid' => (int) $row->service_id]);
                }
                $refund = (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService($repo))->refund((array) $row);
                Capsule::table('mod_vpnhood_iap_purchases')->where('id', $row->id)
                    ->update(['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')]);
                localAPI('LogActivity', ['description' => "vpnhoodiap: purchase {$voidedKey} voided at the store — service terminated, refund $refund."]);
            }
        } catch (\Throwable $e) {
            $repo->log(null, 'cron.voided', '', 0, ['app' => $app['id']], $e->getMessage());
        }
    }

    // -- 3: hygiene ----------------------------------------------------------
    try {
        (new \WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService())->purgeStale();
        $retentionDays = max(1, (int) ($repo->setting('RawPayloadRetentionDays') ?: 90));
        $cutoff = date('Y-m-d H:i:s', time() - $retentionDays * 86400);
        Capsule::table('mod_vpnhood_iap_purchases')
            ->where('updated_at', '<', $cutoff)->whereNotNull('raw_payload')
            ->update(['raw_payload' => null]);
        Capsule::table('mod_vpnhood_iap_events')
            ->where('created_at', '<', $cutoff)->whereNotNull('raw')
            ->update(['raw' => null]);
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.hygiene', '', $e->getMessage(), '');
    }

    // -- 4: ops digest ---------------------------------------------------------
    try {
        $alertEmail = trim($repo->setting('AdminAlertEmail'));
        if ($alertEmail !== '') {
            $parked = (int) Capsule::table('mod_vpnhood_iap_purchases')
                ->whereIn('status', ['awaiting_email_verification', 'failed'])->count();
            $failedEvents = (int) Capsule::table('mod_vpnhood_iap_events')
                ->where('status', 'failed')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count();
            if ($parked > 0 || $failedEvents > 0) {
                localAPI('SendAdminEmail', [
                    'customsubject' => "vpnhoodiap digest: $parked parked purchases, $failedEvents failed events",
                    'custommessage' => "Parked purchases (awaiting verification / failed): $parked\n"
                        . "Failed webhook events in the last 24h: $failedEvents\n\n"
                        . 'Review them in Addons → VpnHood! In-App Purchase.',
                    'type'          => 'system',
                ]);
            }
        }
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.digest', '', $e->getMessage(), '');
    }
});
