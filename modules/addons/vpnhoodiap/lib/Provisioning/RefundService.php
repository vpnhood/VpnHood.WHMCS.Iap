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

    /** The client the booked refund belonged to — set by bookRefund, read after the transaction. */
    private ?int $refundedClientId = null;

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
        $this->refundedClientId = null;
        $outcome = Capsule::connection()->transaction(function () use ($chargeId, $refundTransactionId, $purchase) {
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

        // Side effects stay OUTSIDE the row-lock transaction: revoking calls the
        // provisioning module (and through it the access manager), and an HTTP
        // round-trip must never run while a WHMCS payment row is locked.
        if ($outcome === 'refunded') {
            $this->revokeKey($purchase);
            if ($this->refundedClientId !== null) {
                $this->markRefundedAccount($this->refundedClientId);
            }
        }
        return $outcome;
    }

    /**
     * A refund revokes the key by default (lifecycle §8): the money and the
     * service go back together. *Refund and keep* is the deliberate merchant
     * choice — the keepOnRefund service property — never an accident of nobody
     * acting. Best-effort: the refund is already booked, so a failure here is
     * alerted rather than rethrown.
     */
    private function revokeKey(array $purchase): void
    {
        $serviceId = isset($purchase['service_id']) ? (int) $purchase['service_id'] : 0;
        if ($serviceId <= 0) {
            return;
        }
        if (IapRepository::serviceProperty($serviceId, 'keepOnRefund') === 'yes') {
            $this->repo->log(null, 'refund.keep', '', 0, ['service' => $serviceId],
                'refund-and-keep set on the service — key deliberately left running');
            return;
        }
        $status = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
        if (!in_array($status, ['Active', 'Suspended'], true)) {
            return; // already ended
        }
        $result = localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
        if (($result['result'] ?? '') === 'success') {
            Capsule::table('mod_vpnhood_iap_purchases')
                ->where('id', (int) ($purchase['id'] ?? 0))
                ->update(['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            try {
                localAPI('LogActivity', ['description' =>
                    "vpnhoodiap: refund booked but the key on service #{$serviceId} could not be revoked — revoke it by hand."]);
            } catch (\Throwable) {
                // the log row below is the durable trace
            }
            $this->repo->log(null, 'refund.revoke-failed', '', 0, ['service' => $serviceId], $result);
        }
    }

    /**
     * The disclosed 24-month one-way fingerprint of a refunded account
     * (lifecycle §8), plus the repeat-refund alert that is its whole purpose.
     */
    private function markRefundedAccount(int $clientId): void
    {
        $email = (string) Capsule::table('tblclients')->where('id', $clientId)->value('email');
        if ($email === '' || str_ends_with($email, '@anonymized.invalid')) {
            return; // deleted account — there is no person left to fingerprint
        }
        if ($this->repo->hasRefundMark($email)) {
            try {
                localAPI('LogActivity', ['description' =>
                    "vpnhoodiap: repeat refund — client #{$clientId} was refunded before (within 24 months)."]);
            } catch (\Throwable) {
                // repo log below still records it
            }
            $this->repo->log(null, 'refund.repeat', '', 0, ['client' => $clientId], 'repeat refund mark');
        }
        $this->repo->addRefundMark($email);
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
        $this->refundedClientId = (int) $payment->userid;
        return 'refunded';
    }
}
