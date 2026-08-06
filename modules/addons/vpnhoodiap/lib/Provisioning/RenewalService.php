<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Store-driven renewals, WHMCS-native: pay the service's outstanding renewal
 * invoice with the NEW store order id as transid — WHMCS itself then advances
 * nextduedate and fires the provisioning module's _Renew (verified on this
 * build). When no renewal invoice exists yet, WHMCS's own GenInvoices creates
 * it (verified: works scoped to one client); if the dates still disagree
 * afterwards, UpdateClientProduct re-syncs.
 */
class RenewalService
{
    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * @return string what happened: renewed | resynced | skipped-<reason>
     */
    public function renew(array $app, string $purchaseKey, StoreAdapterInterface $adapter): string
    {
        $row = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('store', $adapter->storeId())
            ->where('purchase_key', $purchaseKey)
            ->first();
        if ($row === null || $row->service_id === null) {
            return 'skipped-unknown-purchase';
        }
        $serviceId = (int) $row->service_id;
        $clientId = (int) $row->client_id;

        $record = $adapter->refresh($app, $purchaseKey, (string) Capsule::table('mod_vpnhood_iap_purchases')
            ->where('id', $row->id)->value('store_order_id') ?: '');
        if (!$record->isEntitled()) {
            return 'skipped-not-entitled';
        }

        // A renewal for a service WHMCS already terminated (late or out-of-order
        // delivery after an expiry): the customer is entitled at the store but the
        // service and its token are gone. Never resurrect a terminated service —
        // provision anew through the one tested path. redeem() is idempotent and
        // re-links the ledger row to the fresh service.
        $serviceStatus = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
        if (!in_array($serviceStatus, ['Active', 'Suspended'], true)) {
            $result = (new EntitlementService($this->repo))->redeem($app, $record, null, $adapter);
            return ($result['state'] ?? '') === 'provisioned'
                ? 'reprovisioned'
                : 'reprovision-' . ($result['state'] ?? 'failed');
        }

        // dedup: if this store order id already paid an invoice, this event is a replay
        $transactionId = $record->storeOrderId ?? '';
        if ($transactionId !== '' && Capsule::table('tblaccounts')->where('transid', $transactionId)->exists()) {
            $this->updateRow((int) $row->id, $record, 'provisioned');
            return 'skipped-already-paid';
        }

        $invoiceId = $this->outstandingRenewalInvoice($serviceId);
        if ($invoiceId === 0) {
            // have WHMCS generate the due renewal invoice, scoped to this client
            localAPI('GenInvoices', ['clientid' => $clientId]);
            $invoiceId = $this->outstandingRenewalInvoice($serviceId);
        }

        if ($invoiceId > 0) {
            $paymentTransactionId = $transactionId !== '' ? $transactionId : $purchaseKey . '-' . time();
            $payment = localAPI('AddInvoicePayment', [
                'invoiceid' => $invoiceId,
                'transid'   => $paymentTransactionId,
                'gateway'   => OrderProvisioner::GATEWAY,
                'noemail'   => true,
            ]);
            if (($payment['result'] ?? '') === 'success') {
                $orders = new OrderProvisioner($this->repo);
                $orders->annotateInvoice($invoiceId, $adapter->storeId(), $record->amount, $record->currency);
                $orders->applyStoreValue($invoiceId, $paymentTransactionId,
                    $record->amount, $record->currency, $clientId, isPrimary: true);
                $this->updateRow((int) $row->id, $record, 'provisioned');
                return 'renewed';
            }
            $this->repo->log(null, 'renew', '', 0, ['serviceid' => $serviceId, 'invoiceid' => $invoiceId], $payment);
        }

        // renewed-early / no invoice yet: re-sync the due date to the store expiry
        if ($record->expiryTimeUnix !== null) {
            localAPI('UpdateClientProduct', [
                'serviceid'   => $serviceId,
                'nextduedate' => date('Y-m-d', $record->expiryTimeUnix),
            ]);
        }
        $this->recordRenewalRevenue($clientId, $serviceId, $transactionId, $purchaseKey, $record);
        $this->updateRow((int) $row->id, $record, 'provisioned');
        return 'resynced';
    }

    /**
     * Book the money for a renewal WHMCS never invoiced. Without this the store
     * collected a payment that appears nowhere in WHMCS reporting — and the
     * replay guard above keys on the store order id living in tblaccounts, so
     * the transaction is also what makes a repeated notification a no-op.
     *
     * Best-effort by design: the customer is already entitled (the store charged
     * them and the due date is synced), so a bookkeeping failure must never undo
     * that — it is logged for the admin instead.
     */
    private function recordRenewalRevenue(int $clientId, int $serviceId, string $transactionId,
        string $purchaseKey, PurchaseRecord $record): void
    {
        // Same store-value rule as the invoices: the real charge when the store
        // billed in the client's own currency, 0.00 when it billed in another
        // (never converted), and only when no real charge is known at all does
        // the WHMCS book price stand in.
        $amount = (float) Capsule::table('tblhosting')->where('id', $serviceId)->value('amount');
        if ($record->amount !== null && $record->currency !== null) {
            $amount = strcasecmp($record->currency, OrderProvisioner::clientCurrencyCode($clientId)) === 0
                ? (float) $record->amount
                : 0.00;
        }

        $result = localAPI('AddTransaction', [
            'userid'        => $clientId,
            'paymentmethod' => OrderProvisioner::GATEWAY,
            'transid'       => $transactionId !== '' ? $transactionId : $purchaseKey . '-' . time(),
            'amountin'      => $amount,
            'date'          => date('Y-m-d'),
            'description'   => "App store renewal for service #$serviceId",
        ]);
        if (($result['result'] ?? '') !== 'success') {
            $this->repo->log(null, 'renew.transaction', '', 0,
                ['serviceid' => $serviceId, 'transid' => $transactionId], $result);
        }
    }

    /** The newest Unpaid invoice carrying this service's renewal line. */
    private function outstandingRenewalInvoice(int $serviceId): int
    {
        $row = Capsule::table('tblinvoiceitems as it')
            ->join('tblinvoices as i', 'i.id', '=', 'it.invoiceid')
            ->where('it.type', 'Hosting')
            ->where('it.relid', $serviceId)
            ->where('i.status', 'Unpaid')
            ->orderByDesc('it.id')
            ->value('it.invoiceid');
        return (int) ($row ?? 0);
    }

    private function updateRow(int $rowId, PurchaseRecord $record, string $status): void
    {
        $changes = [
            'status'         => $status,
            'store_order_id' => $record->storeOrderId,
            'expiry_time'    => $record->expiryTimeUnix !== null ? date('Y-m-d H:i:s', $record->expiryTimeUnix) : null,
            'auto_renewing'  => $record->autoRenewing ? 1 : 0,
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        // informational: the real charge of the latest cycle; a miss keeps the last known
        if ($record->amount !== null) {
            $changes['store_amount'] = $record->amount;
            $changes['store_currency'] = $record->currency;
        }
        Capsule::table('mod_vpnhood_iap_purchases')->where('id', $rowId)->update($changes);
    }
}
