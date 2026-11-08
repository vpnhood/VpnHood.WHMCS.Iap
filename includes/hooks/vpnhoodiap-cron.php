<?php

/**
 * VpnHood! IAP — daily maintenance (DailyCronJob):
 *
 *   1. Reconciliation: re-fetch every open purchase from its store — the
 *      self-healing net under dropped/failed webhooks for a provisioned
 *      purchase. A parked one is only refreshed, never redeemed here. One the
 *      store revoked is settled as a REVOKED notification would (RefundService):
 *      the refund of the charge the store names, and the service ended.
 *   2. Voided-purchases sweep (Google): each voided order's refund is recorded
 *      and booked even when its notification never arrived, and a purchase the
 *      store no longer grants is ended.
 *   2b. Store refunds still due: a refund WHMCS refused, or whose purchase was
 *      busy, is retried; one of a charge with no payment yet is booked once the
 *      payment is recorded; an invoice a booked refund left Paid is set Refunded.
 *   3. Hygiene: clear raw payloads and the request log past their retention, and
 *      re-clamp the bookkeeping gateway's order-form visibility (an admin can
 *      re-tick the checkbox; the gateway must never be offered at checkout).
 *   4. Ops digest: parked/failed purchases, failed events, store refunds waiting
 *      for an admin and failures of the voided sweep, mailed to the configured
 *      admin address.
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

        // open purchases: refresh against the store, re-drive lifecycle drift. The least recently
        // touched first: each refresh moves a row's updated_at, so with more than 500 open the runs
        // take turns through all of them instead of re-reading the same first 500 every night.
        $open = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('app_id', $app['id'])
            ->whereIn('status', ['provisioned', 'canceled', 'on_hold', 'pending'])
            ->orderBy('updated_at')->orderBy('id')->limit(500)
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
                $graceDays = max(0, (int) $repo->setting('TerminateGraceDays'));
                if (
                    $record->state === \WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord::STATE_REVOKED
                    && in_array($purchase['status'], ['provisioned', 'canceled', 'on_hold', 'pending'], true)
                ) {
                    // revoked at the store and no notification said so (Apple has no voided list for
                    // the sweep below, and a notification can be lost): settled as REVOKED would. The
                    // refund of the charge the store names, when it names one; then the service, ended
                    // unless the store grants it again. A refund not even recorded keeps the purchase
                    // open, so tomorrow's run records it. A pending purchase ends too.
                    $refunds = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService($repo);
                    $money = 'unnamed';
                    $recorded = true;
                    if ($record->refundedOrderId !== null) {
                        try {
                            $money = $refunds->recordRefund($purchase, $record->refundedOrderId, $record->refundShare, 'refresh')['state'];
                        } catch (\Throwable $e) {
                            $money = 'failed';
                            $recorded = $e->getCode() !== \WHMCS\Module\Addon\VpnHoodIap\IapRepository::RETRY_LATER;
                            $repo->log(null, 'cron.refund', '', 0, ['purchase' => $purchase['id']],
                                \WHMCS\Module\Addon\VpnHoodIap\ApiException::logMessage($e));
                        }
                    }
                    $service = $refunds->endEntitlement($purchase,
                        fn () => $adapter->refresh($app, (string) $purchase['purchase_key'], ''), 'revoked at the store', $recorded);
                    localAPI('LogActivity', ['description' => "vpnhoodiap: purchase #{$purchase['id']} revoked at the store — "
                        . "refund $money, service $service."]);
                } elseif (
                    // expired on the store but still provisioned here → terminate (with grace), under the
                    // purchase's lifecycle lock and only while the store, read again there, still says
                    // so: a renewal recorded meanwhile keeps its service. A service the module could not
                    // end keeps its status, so this retries tomorrow. Never 'refunded' here: a revoked
                    // purchase is settled above, with its refund. A held one whose store let it lapse
                    // ends too: its hold notification is the last it will ever get
                    in_array($purchase['status'], ['provisioned', 'canceled', 'on_hold'], true)
                    && !$record->isEntitled()
                    && $record->expiryTimeUnix !== null
                    && $record->expiryTimeUnix < time() - $graceDays * 86400
                ) {
                    (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService($repo))->endEntitlement(
                        $purchase,
                        fn () => $adapter->refresh($app, (string) $purchase['purchase_key'], ''),
                        'expired at the store',
                        true,
                        'expired',
                        fn ($fresh): bool => !$fresh->isEntitled() && $fresh->expiryTimeUnix !== null
                            && $fresh->expiryTimeUnix < time() - $graceDays * 86400
                    );
                }
                Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchase['id'])->update($changes);
            } catch (\Throwable $e) {
                $repo->log(null, 'cron.reconcile', '', 0, ['purchase' => $purchase['id']],
                    \WHMCS\Module\Addon\VpnHoodIap\ApiException::logMessage($e));
                // to the back of the queue: rows that keep failing must not take every night's 500 places
                try {
                    Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchase['id'])
                        ->update(['updated_at' => date('Y-m-d H:i:s')]);
                } catch (\Throwable) {
                    // logged above
                }
            }
        }

        // voided sweep: last 20 days. Google's voidedpurchases API rejects any startTime
        // older than its 30-day history window, measured on Google's clock when the request
        // lands — asking for exactly now-30d fails by the seconds of cron drift ("Start time
        // must be within 30 days of data"). 20 days leaves a 10-day margin for drift and clock
        // skew; the sweep is a nightly safety net behind the webhooks, so consecutive runs
        // still overlap by ~20 days.
        try {
            $voidedOrders = $adapter->listVoidedPurchases($app, time() - 20 * 86400);
        } catch (\Throwable $e) {
            $repo->log(null, 'cron.voided', '', 0, ['app' => $app['id']], $e->getMessage());
            $voidedOrders = [];
        }
        foreach ($voidedOrders as $voided) {
            // one at a time: a purchase that fails must not keep the rest of the list waiting
            try {
                $row = Capsule::table('mod_vpnhood_iap_purchases')
                    ->where('store', $app['store'])->where('purchase_key', $voided['purchaseKey'])->first();
                if ($row === null) {
                    continue;
                }
                $row = (array) $row;
                $refunds = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService($repo);
                // the money, whatever the purchase's status: the order voided (each night of the
                // sweep's 20 days; a no-op once recorded, and the ledger keeps a due one after them)
                $money = 'unnamed';
                if ($voided['orderId'] !== null) {
                    try {
                        $money = $refunds->recordRefund($row, $voided['orderId'], $voided['share'], 'voided')['state'];
                    } catch (\Throwable $e) {
                        $money = 'failed';
                        $repo->log(null, 'cron.voided', '', 0, ['app' => $app['id'], 'order' => $voided['orderId']],
                            \WHMCS\Module\Addon\VpnHoodIap\ApiException::logMessage($e));
                    }
                }
                // the service: ended when the store no longer grants the purchase; a voided older
                // order of a subscription the store still grants leaves it running
                if (in_array($row['status'], ['provisioned', 'canceled', 'on_hold', 'pending'], true)) {
                    $service = $refunds->endEntitlement($row,
                        fn () => $adapter->refresh($app, (string) $voided['purchaseKey'], ''), 'voided at the store');
                    if ($service !== 'kept') {
                        localAPI('LogActivity', ['description' => "vpnhoodiap: purchase #{$row['id']} voided at the store — "
                            . "refund $money, service $service."]);
                    }
                }
            } catch (\Throwable $e) {
                $repo->log(null, 'cron.voided', '', 0, ['app' => $app['id'], 'purchase' => $voided['purchaseKey']],
                    \WHMCS\Module\Addon\VpnHoodIap\ApiException::logMessage($e));
            }
        }
    }

    // -- 2b: store refunds still due -------------------------------------------
    // The ledger keeps a refund until it is booked: one WHMCS refused, or whose purchase was busy,
    // is retried here every night past any store's window, with an alert on its first failure; one
    // of a charge with no payment yet is booked once the payment is recorded; and an invoice a
    // booked refund left Paid is set Refunded. An install activated before the ledger existed gets
    // it by hand (no migrations until a partner install runs the module): until then, every run says so.
    try {
        if (!\WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService::ledgerReady()) {
            $repo->alert('vpnhoodiap: this install has no refund ledger (table mod_vpnhood_iap_refunds), so store refunds'
                . ' wait unbooked. Create it once by running the addon\'s activation, which only adds missing tables: in the'
                . ' WHMCS folder, php -r \'require "init.php"; require "modules/addons/vpnhoodiap/vpnhoodiap.php";'
                . ' print_r(vpnhoodiap_activate());\'');
        } else {
            $refunds = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService($repo);
            $due = $refunds->retryDue();
            $paid = $refunds->recheckNoPayment();
            $repaired = $refunds->repairInvoices();
            if (array_sum($due) + $paid + $repaired > 0) {
                localAPI('LogActivity', ['description' => "vpnhoodiap: store refunds due — {$due['booked']} booked,"
                    . " {$due['failed']} still failing, {$due['other']} waiting; $paid found paid since;"
                    . " $repaired invoice(s) set Refunded."]);
            }
        }
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.refunds', '', $e->getMessage(), '');
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
    // the same for WHMCS's invoice snapshots: an admin can untick "Store Client Data
    // Snapshot", and a deleted person's invoices then read "Deleted Account"
    try {
        require_once __DIR__ . '/../../modules/addons/vpnhoodiap/lib/Provisioning/AccountDeletionService.php';
        \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService::keepInvoiceSnapshotsOn();
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.invoice-snapshots', '', $e->getMessage(), '');
    }
    try {
        $retentionDays = max(1, (int) ($repo->setting('RawPayloadRetentionDays') ?: 90));
        $cutoff = date('Y-m-d H:i:s', time() - $retentionDays * 86400);
        Capsule::table('mod_vpnhood_iap_purchases')
            ->where('updated_at', '<', $cutoff)->whereNotNull('raw_payload')
            ->update(['raw_payload' => null]);
        Capsule::table('mod_vpnhood_iap_events')
            ->where('created_at', '<', $cutoff)->whereNotNull('raw')
            ->update(['raw' => null]);
        $repo->pruneRequestLog();
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
            // store refunds waiting for an admin, fenced so the rest of the digest still goes out
            $ledger = \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService::ledgerReady();
            $refundsToCheck = 0;
            try {
                $refundsToCheck = $ledger ? (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService($repo))->checkCount() : 0;
            } catch (\Throwable $e) {
                logModuleCall('vpnhoodiap', 'cron.digest-refunds', '', $e->getMessage(), '');
            }
            // Google's voided feed is the only record naming the order a revocation refunded
            $voidedFailures = (int) Capsule::table('mod_vpnhood_iap_log')
                ->where('action', 'cron.voided')->where('created_at', '>=', $since)->count();
            if ($parked > 0 || $failedEvents > 0 || $refusedPaying > 0 || $refundsToCheck > 0 || $voidedFailures > 0 || !$ledger) {
                localAPI('SendAdminEmail', [
                    'customsubject' => "vpnhoodiap digest: $parked failed purchases, $failedEvents failed events, "
                        . "$refusedPaying paying accounts refused, $refundsToCheck store refunds to check",
                    'custommessage' => "Failed purchases: $parked\n"
                        . "Failed webhook events in the last 24h: $failedEvents\n"
                        . "Accounts with a live store subscription whose code was refused in the last 24h: $refusedPaying\n"
                        . "Store refunds waiting for an admin (Purchases tab): $refundsToCheck\n"
                        . "Failures of the Google voided-purchases check in the last 24h: $voidedFailures\n"
                        . ($ledger ? '' : "This install has no refund ledger: store refunds wait unbooked (see the activity log).\n")
                        . "\n"
                        . 'Review them in Addons → VpnHood! In-App Purchase; refusals are in the activity log ("REFUSED the code").',
                    'type'          => 'system',
                ]);
            }
        }
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'cron.digest', '', $e->getMessage(), '');
    }
});
