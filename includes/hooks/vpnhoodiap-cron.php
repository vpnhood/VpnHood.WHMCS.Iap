<?php

/**
 * VpnHood! IAP — daily maintenance (DailyCronJob):
 *
 *   1. Reconciliation: re-fetch every open purchase from its store — the
 *      self-healing net under dropped/failed webhooks.
 *   2. Voided-purchases sweep: store-side refunds terminate the service even
 *      when the webhook never arrived.
 *   3. Hygiene: purge stale sessions, clear raw payloads past retention, and
 *      re-clamp the bookkeeping gateway's order-form visibility (an admin can
 *      re-tick the checkbox; the gateway must never be offered at checkout).
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
    require_once $moduleDir . '/lib/Provisioning/TermSync.php';

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
            ->whereIn('status', ['provisioned', 'canceled', 'on_hold', 'pending'])
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
                // informational: the real store charge (also back-fills rows from
                // before this was captured); a fetch miss keeps the last known
                if ($record->amount !== null) {
                    $changes['store_amount'] = $record->amount;
                    $changes['store_currency'] = $record->currency;
                }
                // the term follows the store's paid time (TermSync): the nightly net under a lost
                // RENEWED or IN_GRACE, and the retry of a sync that failed. A charge the ledger has
                // never seen is a renewal that never arrived — booked like one, which also re-enables
                // a service held until the store recovered the payment.
                if (
                    $purchase['service_id'] !== null
                    && $record->isEntitled()
                    && in_array($purchase['status'], ['provisioned', 'canceled', 'on_hold'], true)
                ) {
                    if ($record->storeOrderId !== null && $record->storeOrderId !== $purchase['store_order_id']) {
                        (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RenewalService($repo))
                            ->renew($app, (string) $purchase['purchase_key'], $adapter);
                    } else {
                        $sync = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\TermSync($repo);
                        $sync->sync((int) $purchase['service_id'], $record);
                        $sync->repairCode((int) $purchase['service_id']);
                    }
                }
                // expired on the store but still provisioned here → terminate (with grace); a
                // service the module could not end keeps its status, so this retries tomorrow
                $graceDays = max(0, (int) $repo->setting('TerminateGraceDays'));
                if (
                    in_array($purchase['status'], ['provisioned', 'canceled'], true)
                    && !$record->isEntitled()
                    && $record->expiryTimeUnix !== null
                    && $record->expiryTimeUnix < time() - $graceDays * 86400
                ) {
                    $ended = $purchase['service_id'] === null
                        || (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\OrderProvisioner($repo))
                            ->terminateService((int) $purchase['service_id'], 'expired at the store');
                    if ($ended) {
                        $changes['status'] = $record->state === \WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord::STATE_REVOKED
                            ? 'refunded' : 'expired';
                    }
                }
                Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchase['id'])->update($changes);
            } catch (\Throwable $e) {
                $repo->log(null, 'cron.reconcile', '', 0, ['purchase' => $purchase['id']], $e->getMessage());
            }
        }

        // voided sweep: last 20 days. Google's voidedpurchases API rejects any startTime
        // older than its 30-day history window, measured on Google's clock when the request
        // lands — asking for exactly now-30d fails by the seconds of cron drift ("Start time
        // must be within 30 days of data"). 20 days leaves a 10-day margin for drift and clock
        // skew; the sweep is a nightly safety net behind the webhooks, so consecutive runs
        // still overlap by ~20 days.
        try {
            foreach ($adapter->listVoidedPurchaseKeys($app, time() - 20 * 86400) as $voidedKey) {
                $row = Capsule::table('mod_vpnhood_iap_purchases')
                    ->where('store', $app['store'])->where('purchase_key', $voidedKey)->first();
                if ($row === null || $row->status === 'refunded') {
                    continue;
                }
                $ended = $row->service_id === null
                    || (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\OrderProvisioner($repo))
                        ->terminateService((int) $row->service_id, 'voided at the store');
                $refund = (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService($repo))->refund((array) $row);
                // a service the module could not end keeps its status: tomorrow's sweep retries
                // the termination, and the refund booking is idempotent
                if ($ended) {
                    Capsule::table('mod_vpnhood_iap_purchases')->where('id', $row->id)
                        ->update(['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')]);
                }
                localAPI('LogActivity', ['description' => "vpnhoodiap: purchase {$voidedKey} voided at the store — service "
                    . ($ended ? 'terminated' : 'NOT terminated (retried tomorrow)') . ", refund $refund."]);
            }
        } catch (\Throwable $e) {
            $repo->log(null, 'cron.voided', '', 0, ['app' => $app['id']], $e->getMessage());
        }
    }

    // -- 3: hygiene ----------------------------------------------------------
    // self-heal the checkout guard: gateway activation defaults "Show on Order
    // Form" on and admins can re-tick it — same clamp as the addon's
    // vpnhoodiap_hideGatewayFromCheckout(), fenced on its own
    try {
        Capsule::table('tblpaymentgateways')
            ->where('gateway', 'vpnhoodiappay')->where('setting', 'visible')
            ->update(['value' => '']);
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.gateway-visibility', '', $e->getMessage(), '');
    }
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
        // The request log is the rate limiter's window (seconds) and the audit trail. HTTP
        // traffic rows (route actions, and the empty action of a refused verb) past the
        // retention go, a bounded batch a night; the module's own audit rows (alerts,
        // handovers, renewals…) stay — LegacyStoreHandover's removal condition counts them.
        Capsule::table('mod_vpnhood_iap_log')
            ->where('created_at', '<', $cutoff)
            ->where(function ($query) {
                $query->where('action', 'like', '% /v1/%')->orWhere('action', '')->orWhereNull('action');
            })
            ->limit(50000)->delete();
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.hygiene', '', $e->getMessage(), '');
    }

    // -- 4: ops digest ---------------------------------------------------------
    try {
        $alertEmail = trim($repo->setting('AdminAlertEmail'));
        if ($alertEmail !== '') {
            $parked = (int) Capsule::table('mod_vpnhood_iap_purchases')
                ->where('status', 'failed')->count();
            $since = date('Y-m-d H:i:s', time() - 86400);
            $failedEvents = (int) Capsule::table('mod_vpnhood_iap_events')
                ->where('status', 'failed')
                ->where('created_at', '>=', $since)->count();
            // accounts whose live store subscription's code the access server refused: our
            // provisioning fault by definition (AccountKeyService) — a locked-out subscriber
            // shows here before the customer writes in
            $refusedPaying = (int) Capsule::table('mod_vpnhood_iap_code_rejections as r')
                ->join('mod_vpnhood_iap_purchases as p', 'p.user_id', '=', 'r.user_id')
                ->where('r.refused_at', '>=', $since)
                ->whereIn('p.status', ['provisioned', 'canceled'])
                ->where(function ($query) {
                    $query->whereNull('p.expiry_time')->orWhere('p.expiry_time', '>', date('Y-m-d H:i:s'));
                })
                ->distinct()->count('r.user_id');
            if ($parked > 0 || $failedEvents > 0 || $refusedPaying > 0) {
                localAPI('SendAdminEmail', [
                    'customsubject' => "vpnhoodiap digest: $parked failed purchases, $failedEvents failed events, "
                        . "$refusedPaying paying accounts refused",
                    'custommessage' => "Failed purchases: $parked\n"
                        . "Failed webhook events in the last 24h: $failedEvents\n"
                        . "Accounts with a live store subscription whose code was refused in the last 24h: $refusedPaying\n\n"
                        . 'Review them in Addons → VpnHood! In-App Purchase; refusals are in the activity log ("REFUSED the code").',
                    'type'          => 'system',
                ]);
            }
        }
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.digest', '', $e->getMessage(), '');
    }
});
