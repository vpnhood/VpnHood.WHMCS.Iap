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
            $payment = localAPI('AddInvoicePayment', [
                'invoiceid' => $invoiceId,
                'transid'   => $transactionId !== '' ? $transactionId : $purchaseKey . '-' . time(),
                'gateway'   => OrderProvisioner::GATEWAY,
                'noemail'   => true,
            ]);
            if (($payment['result'] ?? '') === 'success') {
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
        $this->updateRow((int) $row->id, $record, 'provisioned');
        return 'resynced';
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
        Capsule::table('mod_vpnhood_iap_purchases')->where('id', $rowId)->update([
            'status'         => $status,
            'store_order_id' => $record->storeOrderId,
            'expiry_time'    => $record->expiryTimeUnix !== null ? date('Y-m-d H:i:s', $record->expiryTimeUnix) : null,
            'auto_renewing'  => $record->autoRenewing ? 1 : 0,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
