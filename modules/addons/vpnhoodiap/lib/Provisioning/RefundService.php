<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Books a store-issued refund back into WHMCS.
 *
 * The store is the merchant of record: it takes the money back on its own, and we
 * hear about it as a REVOKED notification or through the voided-purchases sweep.
 * Terminating the service is only half of that — without the money going back the
 * install keeps reporting revenue the customer no longer paid.
 *
 * Scope: the charge we recorded for this purchase (transid = the store order id).
 * A subscription refunded after several renewals has one transaction per cycle;
 * Google revokes the subscription and refunds the charge(s) it decided to refund,
 * so anything beyond the recorded charge stays an admin decision rather than
 * something this module guesses at.
 *
 * Idempotent: the refund transaction carries a derived "<store order id>-refund"
 * transid, and its presence is what makes a replayed notification a no-op.
 */
class RefundService
{
    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * @param array $purchase a mod_vpnhood_iap_purchases row
     * @return string what happened: refunded | skipped-already | skipped-no-payment
     */
    public function refund(array $purchase): string
    {
        $chargeId = (string) ($purchase['store_order_id'] ?? '');
        if ($chargeId === '') {
            $chargeId = (string) ($purchase['purchase_key'] ?? '');
        }
        if ($chargeId === '') {
            return 'skipped-no-payment';
        }

        $refundTransactionId = $chargeId . '-refund';

        // Serialize concurrent revocations on the charge row itself: Google delivers
        // the same revocation as SEVERAL notifications with distinct message ids in
        // the same second (observed live 2026-08-06 — the inbox dedup cannot collapse
        // them), so a plain exists-then-insert double-books the refund. The second
        // request blocks on the row lock until the first one's refund is committed,
        // then sees it.
        return Capsule::connection()->transaction(function () use ($chargeId, $refundTransactionId, $purchase) {
            $payment = Capsule::table('tblaccounts')->where('transid', $chargeId)->lockForUpdate()->first();
            if ($payment === null) {
                // nothing was ever booked for this purchase (parked or failed before ordering)
                return 'skipped-no-payment';
            }
            if (Capsule::table('tblaccounts')->where('transid', $refundTransactionId)->exists()) {
                return 'skipped-already';
            }
            return $this->bookRefund($purchase, $payment, $chargeId, $refundTransactionId);
        });
    }

    /** @return string refunded | skipped-no-payment */
    private function bookRefund(array $purchase, object $payment, string $chargeId, string $refundTransactionId): string
    {
        $invoiceId = (int) ($payment->invoiceid ?? 0);
        $result = localAPI('AddTransaction', [
            'userid'        => (int) $payment->userid,
            'invoiceid'     => $invoiceId,
            'paymentmethod' => OrderProvisioner::GATEWAY,
            'transid'       => $refundTransactionId,
            'amountout'     => $payment->amountin,
            'date'          => date('Y-m-d'),
            'description'   => 'App store refund for ' . $chargeId,
        ]);
        if (($result['result'] ?? '') !== 'success') {
            $this->repo->log(null, 'refund.transaction', '', 0,
                ['purchase' => $purchase['id'] ?? null, 'transid' => $refundTransactionId], $result);
            return 'skipped-no-payment';
        }

        if ($invoiceId > 0) {
            // the customer-facing refund mail is aborted by the suppression hook
            localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Refunded']);
        }
        return 'refunded';
    }
}
