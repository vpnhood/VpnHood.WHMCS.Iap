<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Store refunds, in two halves with separate owners. A store-issued refund is the store's
 * decision, and the code ends when the store says the entitlement is gone (account lifecycle §8):
 *
 * - The money follows the charge the store refunded. The store names it (Google's voided order,
 *   Apple's refunded transaction), and the ledger (mod_vpnhood_iap_refunds) keeps one row per named
 *   charge until its reversal is booked in WHMCS. Nothing is reversed by guesswork: only a full
 *   refund of an exactly named charge with one invoiced payment is booked unasked. A partial refund,
 *   a charge two payments carry, one on no invoice, an invoice already refunded by hand, or a refund
 *   grown after its booking or its dismissal waits for an admin on the Purchases tab. A revocation
 *   that names no charge (Google's SUBSCRIPTION_REVOKED) books nothing: Google's voided feed names it.
 * - The service follows the store's entitlement: endEntitlement() ends it only when the store, read
 *   fresh, no longer grants the purchase, and the purchase is still the one that was read.
 *
 * Both halves, and the booking, run under the purchase's lifecycle lock (IapRepository), which its
 * redeem and its renewals hold too: a payment being recorded is never reversed half-written, and a
 * renewal never interleaves with a revocation. Retries touch only the money, so a renewal or a new
 * service on the same purchase is never undone by them. Idempotent: the ledger is unique per charge,
 * its row is locked while its reversal is booked, and the reversal's transid is "<order id>-refund".
 */
class RefundService
{
    public const TABLE = 'mod_vpnhood_iap_refunds';
    /** A share in milliunits of the charge: the whole of it. */
    public const FULL = 100000;
    private const OPEN = ['pending', 'provisioned', 'canceled', 'on_hold'];

    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * Whether this install has the ledger. Activation creates it; an install activated before it
     * existed gets it by hand (no migrations until a partner install runs the module). Until then no
     * refund is booked: the webhook answers 503 for a revocation, so the store sends it again.
     */
    public static function ledgerReady(): bool
    {
        static $ready = false;
        return $ready = $ready || Capsule::schema()->hasTable(self::TABLE);
    }

    // ------------------------------------------------------------ the money --

    /**
     * The store refunded $chargeId, by $share milliunits (null: it did not say how much). Records it,
     * or moves its row on by this evidence, and books the reversal when it is due.
     *
     * @param array $purchase a mod_vpnhood_iap_purchases row
     * @param bool $fresh a notification never seen before (the inbox drops replays), so a refund of
     *        unknown size in it may be a further one; the nightly reads repeat what they saw
     * @return array{id: int, state: string, reason: string, new: bool}
     * @throws \RuntimeException code IapRepository::RETRY_LATER when the refund could not even be
     *         recorded (the store must send it again); any other when WHMCS refuses the booking (the
     *         row stays due, retried nightly)
     */
    public function recordRefund(array $purchase, string $chargeId, ?int $share, string $source, bool $fresh = false): array
    {
        $store = (string) $purchase['store'];
        try {
            [$state, $reason] = self::firstState($share);
            $now = date('Y-m-d H:i:s');
            $new = Capsule::table(self::TABLE)->insertOrIgnore([
                'purchase_id' => (int) $purchase['id'],
                'store'       => $store,
                'charge_id'   => $chargeId,
                'share'       => $share,
                'state'       => $state,
                'reason'      => $reason,
                'source'      => $source,
                'note'        => self::shareNote($state, $share),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]) > 0;
            if (!$new) {
                $this->observeAgain($store, $chargeId, $share, $fresh);
            }
            $row = Capsule::table(self::TABLE)->where('store', $store)->where('charge_id', $chargeId)->first();
            if ($row === null) {
                throw new \RuntimeException('the ledger row is missing after its insert');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("The refund of $chargeId could not be recorded: " . $e->getMessage(),
                IapRepository::RETRY_LATER, $e);
        }

        if ($row->state === 'due') {
            $this->book((int) $row->id);
            $row = Capsule::table(self::TABLE)->where('id', $row->id)->first();
        }
        return ['id' => (int) $row->id, 'state' => (string) $row->state, 'reason' => (string) $row->reason, 'new' => $new];
    }

    /** @return array{0: string, 1: string} the state and reason of a charge first named with $share */
    private static function firstState(?int $share): array
    {
        return match (true) {
            $share === self::FULL => ['due', ''],
            $share === 0          => ['none', 'no-refund'],
            default               => ['review', 'partial'],
        };
    }

    private static function shareNote(string $state, ?int $share): ?string
    {
        if ($state === 'none') {
            return 'Revoked without a refund (Family Sharing, or an organization\'s seat).';
        }
        if ($state !== 'review') {
            return null;
        }
        return $share === null
            ? 'The store refunded part of this charge without saying how much: book it by hand in WHMCS, then dismiss it here.'
            : 'The store refunded ' . self::percent($share) . ' of this charge.';
    }

    private static function percent(int $share): string
    {
        return rtrim(rtrim(number_format($share / 1000, 3, '.', ''), '0'), '.') . '%';
    }

    /**
     * The store named a charge it named before. The row keeps the largest share the store reported,
     * and moves on only by explicit rules:
     * - revoked without a refund, and now a refund: due when whole, review ('partial') when part;
     * - waiting for its payment: keeps waiting (rechecked nightly), with the larger share;
     * - in review: a larger share updates what the admin sees (its version moves, so a page showing
     *   the smaller one cannot act); a partial refund now whole becomes due;
     * - booked or dismissed, and the store now reports more than was booked or seen: review ('increase');
     * - a fresh notification of a refund of unknown size: a booked-in-part or dismissed row goes to
     *   review ('increase'), a row in review gets a note; it may be a further refund, and a replay is
     *   never fresh.
     * A due row stays as it is: its whole refund is being booked.
     */
    private function observeAgain(string $store, string $chargeId, ?int $share, bool $fresh): void
    {
        Capsule::connection()->transaction(function () use ($store, $chargeId, $share, $fresh) {
            $row = Capsule::table(self::TABLE)->where('store', $store)->where('charge_id', $chargeId)->lockForUpdate()->first();
            if ($row === null || $share === 0 || $row->state === 'due') {
                return;
            }
            $seen = $row->share !== null ? (int) $row->share : null;
            $larger = $share !== null && ($seen === null || $share > $seen);
            $further = $share === null && $fresh; // a refund of unknown size, maybe a further one
            $bookedShare = (int) $row->booked_share;
            $changes = null;
            switch ($row->state) {
                case 'none':
                    if (($larger || $further) && $row->reason === 'no-refund') {
                        $changes = $share === self::FULL
                            ? ['state' => 'due', 'reason' => '', 'share' => $share, 'note' => null]
                            : ['state' => 'review', 'reason' => 'partial', 'share' => $share, 'note' => self::shareNote('review', $share)];
                    } elseif ($larger) {
                        $changes = ['share' => $share];
                    } elseif ($further && ($seen === null || $seen < self::FULL)) {
                        // waiting for its payment with part of the charge known: the unknown rest is
                        // settled by hand, never left behind a booking of the known part
                        $changes = ['state' => 'review', 'reason' => 'increase', 'note' => 'The store refunded '
                            . ($seen !== null ? self::percent($seen) : 'part') . ' of this charge, then reported a further'
                            . ' refund without saying how much, and no payment is recorded for it yet: settle it by hand'
                            . ' in WHMCS, then dismiss it here.'];
                    }
                    break;
                case 'review':
                    if ($larger && $row->reason === 'partial') {
                        $changes = $share === self::FULL
                            ? ['state' => 'due', 'reason' => '', 'share' => $share, 'note' => null]
                            : ['share' => $share, 'note' => self::shareNote('review', $share)];
                    } elseif ($larger) {
                        $changes = ['share' => $share, 'note' => $row->reason === 'increase'
                            ? self::increaseNote($share, $bookedShare) : $row->note];
                    } elseif ($further && $row->reason === 'partial' && $seen !== null) {
                        // the known part is no longer the whole story: never bookable as if it were
                        $changes = ['reason' => 'increase', 'note' => 'The store refunded ' . self::percent($seen)
                            . ' of this charge, then reported a further refund without saying how much: settle it'
                            . ' by hand in WHMCS, then dismiss it here.'];
                    } elseif ($further) {
                        $changes = ['note' => 'The store reported a further refund of this charge without saying how much. '
                            . $row->note];
                    }
                    break;
                case 'booked':
                    if (($larger && $share > $bookedShare) || ($further && $bookedShare < self::FULL)) {
                        $changes = ['state' => 'review', 'reason' => 'increase', 'share' => $share ?? $seen,
                            'note' => self::increaseNote($share, $bookedShare)];
                    }
                    break;
                case 'skipped':
                    if ($larger || $further) {
                        $changes = ['state' => 'review', 'reason' => 'increase', 'share' => $share ?? $seen,
                            'note' => 'Dismissed before, and the store now reports ' . ($share !== null
                                ? self::percent($share) . ' of this charge refunded' : 'a further refund of it, without saying how much')
                                . ': settle the difference by hand in WHMCS, then dismiss it here.'];
                    }
                    break;
            }
            if ($changes !== null) {
                $this->changeRow((int) $row->id, $changes);
            }
        });
    }

    private static function increaseNote(?int $share, int $bookedShare): string
    {
        return ($share !== null
                ? 'The store now says ' . self::percent($share) . ' of this charge was refunded; '
                : 'The store reported a further refund of this charge without saying how much; ')
            . self::percent($bookedShare) . ' is booked. Book the rest by hand in WHMCS, then dismiss it here.';
    }

    /**
     * Books the reversal of a due row; with $approvedVersion, of a review row an admin approved as it
     * was at that version. Under the purchase's lifecycle lock (a payment still being recorded is
     * finished first) and the row's lock, so every booker of a charge (duplicate notifications, the
     * nightly retry, an admin) takes turns, and the second finds it booked.
     *
     * @return array{state: string, outcome: string, invoice: string}
     *   outcome: booked | already | short | no-payment | ambiguous | uninvoiced | manual | busy | changed | not-allowed
     *   invoice: refunded | failed | n/a
     * @throws \RuntimeException when WHMCS refuses the transaction: nothing is booked and the row is as
     *         it was, but for its attempt count and note
     */
    public function book(int $refundId, ?int $approvedVersion = null): array
    {
        $purchase = Capsule::table(self::TABLE . ' as r')->where('r.id', $refundId)
            ->join('mod_vpnhood_iap_purchases as p', 'p.id', '=', 'r.purchase_id')
            ->first(['p.store', 'p.purchase_key']);
        $booking = fn (): array => Capsule::connection()->transaction(fn (): array => $this->bookLocked($refundId, $approvedVersion));
        try {
            $result = $purchase === null ? $booking()
                : IapRepository::withPurchaseLock((string) $purchase->store, (string) $purchase->purchase_key, $booking);
        } catch (\Throwable $e) {
            if ($e->getCode() === IapRepository::RETRY_LATER) {
                // the purchase is busy: to the back of the nightly queue, no failure counted
                Capsule::table(self::TABLE)->where('id', $refundId)->update(['updated_at' => date('Y-m-d H:i:s')]);
                return ['state' => 'due', 'outcome' => 'busy', 'invoice' => 'n/a'];
            }
            $this->recordFailure($refundId, 'WHMCS refused to book the refund: ' . ApiException::logMessage($e));
            throw $e;
        }
        $invoice = in_array($result['outcome'], ['booked', 'already'], true) ? $this->finishInvoice($refundId) : 'n/a';
        return $result + ['invoice' => $invoice];
    }

    private function bookLocked(int $refundId, ?int $approvedVersion): array
    {
        $row = Capsule::table(self::TABLE)->where('id', $refundId)->lockForUpdate()->first();
        if ($row === null) {
            throw new \RuntimeException("Refund #$refundId does not exist.");
        }
        $result = fn (string $outcome, ?string $state = null): array => ['state' => $state ?? (string) $row->state, 'outcome' => $outcome];
        if ($approvedVersion !== null && (int) $row->version !== $approvedVersion) {
            return $result('changed');
        }
        if (!($row->state === 'due' || ($approvedVersion !== null && self::adminCanBook($row)))) {
            return $result('not-allowed');
        }
        $share = $row->share !== null ? (int) $row->share : self::FULL; // an admin books a charge-only review whole

        $payments = Capsule::table('tblaccounts')
            ->where('transid', $row->charge_id)->where('gateway', OrderProvisioner::GATEWAY)
            ->orderBy('id')->limit(2)->get(['id', 'userid', 'invoiceid', 'amountin']);
        if ($payments->isEmpty()) {
            $this->changeRow($refundId, ['state' => 'none', 'reason' => 'no-payment',
                'note' => 'No payment is recorded for this charge: nothing to reverse. Checked again every night.']);
            return $result('no-payment', 'none');
        }
        if ($payments->count() > 1) {
            $this->changeRow($refundId, ['state' => 'review', 'reason' => 'ambiguous',
                'note' => 'Two payments carry this charge id: book the refund by hand in WHMCS, then dismiss it here.']);
            return $result('ambiguous', 'review');
        }
        $payment = $payments->first();
        $settled = [
            'payment_id' => (int) $payment->id,
            'invoice_id' => (int) $payment->invoiceid ?: null,
            'settled_at' => date('Y-m-d H:i:s'),
            'attempts'   => 0,
            'note'       => null,
        ];

        $target = self::reversal((string) $payment->amountin, $share);
        $marker = Capsule::table('tblaccounts')
            ->where('transid', $row->charge_id . '-refund')->where('gateway', OrderProvisioner::GATEWAY)
            ->orderBy('id')->first(['id', 'amountout']);
        if ($marker !== null) {
            // booked before (a replay, a version before the ledger, or by hand under this transid): never
            // twice, and settled only when it reversed at least what the store refunded
            $markedShare = (float) $payment->amountin > 0
                ? (int) min(self::FULL, round((float) $marker->amountout / (float) $payment->amountin * self::FULL)) : self::FULL;
            if ((float) $marker->amountout + 0.005 < (float) $target) {
                $this->changeRow($refundId, ['state' => 'review', 'reason' => 'increase', 'booked_share' => $markedShare,
                    'payment_id' => (int) $payment->id, 'invoice_id' => (int) $payment->invoiceid ?: null,
                    'refund_transaction_id' => (int) $marker->id,
                    'note' => "A refund of {$marker->amountout} is booked for this charge, and the store refunded $target:"
                        . ' book the rest by hand in WHMCS, then dismiss it here.']);
                return $result('short', 'review');
            }
            $this->changeRow($refundId, $settled + ['state' => 'booked', 'reason' => '', 'booked_share' => max($share, $markedShare),
                'amount' => $marker->amountout, 'refund_transaction_id' => (int) $marker->id,
                'invoice_done' => self::needsInvoice($share, $payment) ? 0 : 1]);
            return $result('already', 'booked');
        }
        if ((int) $payment->invoiceid <= 0 && $approvedVersion === null) {
            // no invoice to show a refund made by hand: only a person can tell there is none
            $this->changeRow($refundId, ['state' => 'review', 'reason' => 'uninvoiced',
                'note' => 'This charge is on no invoice: check the client\'s transactions for a refund made by hand, then book or dismiss it.']);
            return $result('uninvoiced', 'review');
        }
        if ((int) $payment->invoiceid > 0 && Capsule::table('tblaccounts')
                ->where('invoiceid', (int) $payment->invoiceid)->where('amountout', '>', 0)->exists()) {
            $this->changeRow($refundId, ['state' => 'review', 'reason' => 'manual',
                'note' => 'The invoice already shows a refund made by hand: settle the rest by hand, then dismiss it here.']);
            return $result('manual', 'review');
        }

        $booking = localAPI('AddTransaction', [
            'userid'        => (int) $payment->userid,
            'invoiceid'     => (int) $payment->invoiceid,
            'paymentmethod' => OrderProvisioner::GATEWAY,
            'transid'       => $row->charge_id . '-refund',
            'amountout'     => $target,
            'date'          => date('Y-m-d'),
            'description'   => 'App store refund for ' . $row->charge_id
                . ($share === self::FULL ? '' : ' (' . self::percent($share) . ')'),
        ]);
        if (($booking['result'] ?? '') !== 'success') {
            $this->repo->log(null, 'refund.transaction', '', 0, ['refund' => $refundId, 'charge' => $row->charge_id], $booking);
            throw new \RuntimeException((string) ($booking['message'] ?? json_encode($booking)));
        }
        $markerId = Capsule::table('tblaccounts')
            ->where('transid', $row->charge_id . '-refund')->where('gateway', OrderProvisioner::GATEWAY)->value('id');
        $this->changeRow($refundId, $settled + ['state' => 'booked', 'reason' => '', 'booked_share' => $share,
            'amount' => $target, 'refund_transaction_id' => $markerId !== null ? (int) $markerId : null,
            'invoice_done' => self::needsInvoice($share, $payment) ? 0 : 1]);
        return $result('booked', 'booked');
    }

    /** What a refund of $share milliunits of a charge of $amountIn reverses, in cents. */
    private static function reversal(string $amountIn, int $share): string
    {
        return number_format(round((float) $amountIn * $share / self::FULL, 2), 2, '.', '');
    }

    /**
     * What an admin may book: a partial refund whose size the store gave, or a whole one on no invoice
     * once they have checked it was not refunded by hand. Every other review is settled by hand.
     */
    private static function adminCanBook(object $row): bool
    {
        return $row->state === 'review' && (
            ($row->reason === 'partial' && $row->share !== null && (int) $row->share > 0)
            || ($row->reason === 'uninvoiced' && (int) $row->share === self::FULL));
    }

    /** A whole charge reversed against an invoice sets it Refunded; a partial one leaves it Paid. */
    private static function needsInvoice(int $share, object $payment): bool
    {
        return $share === self::FULL && (int) $payment->invoiceid > 0;
    }

    /** The second half of a booking: the invoice set Refunded while it is Paid. */
    private function finishInvoice(int $refundId): string
    {
        $row = Capsule::table(self::TABLE)->where('id', $refundId)->first();
        if ($row === null || $row->state !== 'booked' || (int) $row->invoice_done === 1) {
            return 'n/a';
        }
        $invoiceId = (int) $row->invoice_id;
        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if ($status === 'Paid') {
            // the customer-facing refund mail is aborted by the suppression hook
            $update = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Refunded']);
            if (($update['result'] ?? '') !== 'success') {
                $this->recordFailure($refundId, "The refund is booked, but invoice #$invoiceId could not be set Refunded: "
                    . json_encode($update));
                return 'failed';
            }
        }
        $this->changeRow($refundId, ['invoice_done' => 1, 'attempts' => 0, 'note' => null]);
        return $status === 'Paid' ? 'refunded' : 'n/a';
    }

    /** A failed attempt: counted and noted, its row to the back of the nightly queue; the first one alerts an admin. */
    private function recordFailure(int $refundId, string $why): void
    {
        try {
            $row = Capsule::table(self::TABLE)->where('id', $refundId)->first();
            if ($row === null) {
                return;
            }
            $this->changeRow($refundId, ['attempts' => (int) $row->attempts + 1, 'note' => mb_substr($why, 0, 2000)]);
            if ((int) $row->attempts === 0) {
                $this->repo->alert("vpnhoodiap: the store refunded charge {$row->charge_id} of purchase #{$row->purchase_id}. $why"
                    . ' Retried every night; listed under Addons → VpnHood! In-App Purchase → Purchases.');
            }
        } catch (\Throwable $e) {
            $this->repo->log(null, 'refund.failure', '', 0, ['refund' => $refundId], ApiException::logMessage($e));
        }
    }

    /** Every change to a row moves its version (an admin's action names the version it saw) and its place in the queue. */
    private function changeRow(int $refundId, array $changes): void
    {
        Capsule::table(self::TABLE)->where('id', $refundId)->update($changes + [
            'version'    => Capsule::raw('version + 1'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ---------------------------------------------------------- the service --

    /**
     * Ends the service of a purchase the store no longer grants: revoked ($endedStatus 'refunded') or
     * expired ('expired'). Every such end goes through here, under the purchase's lifecycle lock that
     * its redeem and its renewals hold too: it re-reads the purchase and acts only while it is open and
     * still the purchase that was read (the same recorded charge and service: a renewal or a new
     * service since then leaves it to the nightly refresh), then asks the store ($readStore) and ends
     * nothing the store still grants ($mustEnd, by default: not entitled). Terminates the service, then
     * sets the status, unless $markEnded is false (a revocation whose refund could not be recorded: the
     * purchase stays open for the next attempt). A service that could not be ended keeps the purchase
     * open too (terminateService alerted), and the nightly refresh tries again. Whatever asked, a store
     * answer of revoked ends it as refunded, and the refund it names is recorded first.
     *
     * @param array $purchase a mod_vpnhood_iap_purchases row
     * @param callable():\WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord $readStore
     * @param ?callable(\WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord):bool $mustEnd
     * @return string ended | ended-open | kept | already | changed | not-ended
     * @throws \RuntimeException code IapRepository::RETRY_LATER when the purchase is busy elsewhere
     */
    public function endEntitlement(array $purchase, callable $readStore, string $why, bool $markEnded = true,
        string $endedStatus = 'refunded', ?callable $mustEnd = null): string
    {
        $id = (int) $purchase['id'];
        return IapRepository::withPurchaseLock((string) $purchase['store'], (string) $purchase['purchase_key'],
            function () use ($purchase, $id, $readStore, $why, $markEnded, $endedStatus, $mustEnd): string {
                $current = Capsule::table('mod_vpnhood_iap_purchases')->where('id', $id)->first();
                if ($current === null || !in_array($current->status, self::OPEN, true)) {
                    return 'already';
                }
                if ((string) ($current->store_order_id ?? '') !== (string) ($purchase['store_order_id'] ?? '')
                    || (int) ($current->service_id ?? 0) !== (int) ($purchase['service_id'] ?? 0)) {
                    return 'changed';
                }
                $record = $readStore();
                $revoked = $record->state === PurchaseRecord::STATE_REVOKED;
                if (!$revoked && !($mustEnd !== null ? $mustEnd($record) : !$record->isEntitled())) {
                    return 'kept';
                }
                if ($revoked) {
                    // the store took it back, whatever asked: its refund is recorded before the purchase
                    // closes, as the nightly read never sees a closed purchase again; not recorded, it
                    // stays open for the next read (a booking WHMCS refuses stays due on the ledger)
                    $endedStatus = 'refunded';
                    if ($record->refundedOrderId !== null) {
                        try {
                            $this->recordRefund((array) $current, $record->refundedOrderId, $record->refundShare, 'refresh');
                        } catch (\Throwable $e) {
                            $markEnded = $markEnded && $e->getCode() !== IapRepository::RETRY_LATER;
                        }
                    }
                }
                $serviceId = (int) ($current->service_id ?? 0);
                if ($serviceId > 0 && !(new OrderProvisioner($this->repo))->terminateService($serviceId, $why)) {
                    return 'not-ended';
                }
                if (!$markEnded) {
                    return 'ended-open';
                }
                Capsule::table('mod_vpnhood_iap_purchases')->where('id', $id)
                    ->update(['status' => $endedStatus, 'updated_at' => date('Y-m-d H:i:s')]);
                return 'ended';
            });
    }

    // ------------------------------------------------------------ the night --

    /**
     * Retries the refunds still due (WHMCS refused them, or their purchase was busy), oldest attempt
     * first, each on its own: one that keeps failing goes to the back of the queue.
     *
     * @return array{booked: int, failed: int, other: int}
     */
    public function retryDue(int $limit = 100): array
    {
        $counts = ['booked' => 0, 'failed' => 0, 'other' => 0];
        $ids = Capsule::table(self::TABLE)->where('state', 'due')
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id')->all();
        foreach ($ids as $id) {
            try {
                $this->book((int) $id)['outcome'] === 'booked' ? $counts['booked']++ : $counts['other']++;
            } catch (\Throwable) {
                $counts['failed']++; // noted on the row; the first failure alerted
            }
        }
        return $counts;
    }

    /**
     * Refunds of a charge with no payment recorded when the store named it: a payment recorded since
     * makes a whole refund due, and it is booked; a partial one goes back to review, for an admin to
     * book against the payment now there. Looked up by value in batches, oldest check first; a row
     * changed since it was read (its version moved) is left to the next run.
     */
    public function recheckNoPayment(int $limit = 500): int
    {
        $rows = Capsule::table(self::TABLE)->where('state', 'none')->where('reason', 'no-payment')
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get(['id', 'charge_id', 'share', 'version']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $paid = array_flip(Capsule::table('tblaccounts')
            ->whereIn('transid', $rows->pluck('charge_id')->all())->where('gateway', OrderProvisioner::GATEWAY)
            ->pluck('transid')->map(fn ($transid) => (string) $transid)->all());
        $found = 0;
        foreach ($rows as $row) {
            $unchanged = Capsule::table(self::TABLE)->where('id', $row->id)->where('version', $row->version)
                ->where('state', 'none')->where('reason', 'no-payment');
            if (!isset($paid[(string) $row->charge_id])) {
                $unchanged->update(['updated_at' => date('Y-m-d H:i:s')]); // checked: to the back of the queue
                continue;
            }
            $whole = $row->share !== null && (int) $row->share === self::FULL;
            $moved = $unchanged->update(($whole
                ? ['state' => 'due', 'reason' => '', 'note' => null]
                : ['state' => 'review', 'reason' => 'partial', 'note' => self::shareNote('review', $row->share !== null ? (int) $row->share : null)])
                + ['version' => Capsule::raw('version + 1'), 'updated_at' => date('Y-m-d H:i:s')]);
            if ($moved !== 1) {
                continue;
            }
            $found++;
            if ($whole) {
                try {
                    $this->book((int) $row->id);
                } catch (\Throwable) {
                    // noted on the row; retried with the due ones
                }
            }
        }
        return $found;
    }

    /** Booked refunds whose invoice update failed: set Refunded now, oldest attempt first, each on its own. */
    public function repairInvoices(int $limit = 100): int
    {
        $repaired = 0;
        $ids = Capsule::table(self::TABLE)->where('state', 'booked')->where('invoice_done', 0)
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id')->all();
        foreach ($ids as $id) {
            try {
                if ($this->finishInvoice((int) $id) !== 'failed') {
                    $repaired++;
                }
            } catch (\Throwable $e) {
                $this->recordFailure((int) $id, 'Setting the invoice Refunded failed: ' . ApiException::logMessage($e));
            }
        }
        return $repaired;
    }

    // ------------------------------------------------------------ the admin --

    /** What waits for an admin: a review, a refund WHMCS keeps refusing, an invoice update that keeps failing. */
    private static function needsAdmin(): \Illuminate\Database\Query\Builder
    {
        return Capsule::table(self::TABLE)->where(function ($q) {
            $q->where('state', 'review')
                ->orWhere(fn ($q) => $q->where('state', 'due')->where('attempts', '>', 0))
                ->orWhere(fn ($q) => $q->where('state', 'booked')->where('invoice_done', 0)->where('attempts', '>', 0));
        });
    }

    public function checkCount(): int
    {
        return self::needsAdmin()->count();
    }

    /**
     * One page of the store refunds waiting for an admin, oldest first, with what the panel shows:
     * the charge and its payment, what a booking would reverse, the invoice and its status, the
     * client, the note, and the actions allowed.
     *
     * @return list<array<string, mixed>>
     */
    public function checkList(int $page = 1, int $perPage = 50): array
    {
        $rows = self::needsAdmin()->orderBy('id')->forPage(max(1, $page), $perPage)->get()->all();
        $list = [];
        foreach ($rows as $row) {
            $payment = Capsule::table('tblaccounts')->where('transid', $row->charge_id)
                ->where('gateway', OrderProvisioner::GATEWAY)->orderBy('id')->first(['amountin', 'invoiceid', 'userid']);
            $invoiceId = (int) ($row->invoice_id ?? 0) ?: (int) ($payment->invoiceid ?? 0);
            $list[] = [
                'id'             => (int) $row->id,
                'version'        => (int) $row->version,
                'purchase_id'    => (int) $row->purchase_id,
                'store'          => (string) $row->store,
                'charge'         => (string) $row->charge_id,
                'state'          => (string) $row->state,
                'reason'         => (string) $row->reason,
                'share'          => $row->share !== null ? self::percent((int) $row->share) : null,
                'paid'           => $payment !== null ? (string) $payment->amountin : null,
                'to_book'        => $payment !== null && self::adminCanBook($row)
                    ? self::reversal((string) $payment->amountin, (int) $row->share) : null,
                'invoice_id'     => $invoiceId,
                'invoice_status' => $invoiceId > 0 ? (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status') : null,
                'client_id'      => $payment !== null ? (int) $payment->userid : null,
                'note'           => (string) ($row->note ?? ''),
                'actions'        => self::actionsFor($row),
            ];
        }
        return $list;
    }

    /** @return list<string> book | retry | retry-invoice | dismiss */
    private static function actionsFor(object $row): array
    {
        return match (true) {
            $row->state === 'booked' => ['retry-invoice'],
            $row->state === 'due'    => ['retry', 'dismiss'],
            self::adminCanBook($row) => ['book', 'dismiss'],
            default                  => ['dismiss'],
        };
    }

    /**
     * An admin's action on a row as they saw it (its version): book what the panel showed, retry a
     * refund or an invoice update now, or dismiss: nothing more is booked for what the store reported
     * so far (a later, larger refund of the charge comes back for review).
     *
     * @return array{outcome: string, state: string, invoice: string}
     */
    public function adminAction(int $refundId, int $version, string $action): array
    {
        $result = match ($action) {
            'book'          => $this->book($refundId, $version),
            'retry'         => $this->sameVersion($refundId, $version) ? $this->book($refundId) : $this->changed($refundId),
            'retry-invoice' => $this->sameVersion($refundId, $version) ? $this->retryInvoice($refundId) : $this->changed($refundId),
            'dismiss'       => $this->dismiss($refundId, $version),
            default         => throw new \RuntimeException("Unknown action: $action"),
        };
        $this->repo->log(null, 'refund.admin', '', 0, ['refund' => $refundId, 'action' => $action], $result);
        return $result + ['invoice' => 'n/a'];
    }

    private function sameVersion(int $refundId, int $version): bool
    {
        return (int) Capsule::table(self::TABLE)->where('id', $refundId)->value('version') === $version;
    }

    private function changed(int $refundId): array
    {
        return ['outcome' => 'changed', 'state' => (string) Capsule::table(self::TABLE)->where('id', $refundId)->value('state')];
    }

    private function retryInvoice(int $refundId): array
    {
        $invoice = $this->finishInvoice($refundId);
        return ['outcome' => $invoice === 'failed' ? 'invoice-failed' : 'invoice-done', 'state' => 'booked', 'invoice' => $invoice];
    }

    private function dismiss(int $refundId, int $version): array
    {
        return Capsule::connection()->transaction(function () use ($refundId, $version): array {
            $row = Capsule::table(self::TABLE)->where('id', $refundId)->lockForUpdate()->first();
            if ($row === null || (int) $row->version !== $version) {
                return ['outcome' => 'changed', 'state' => (string) ($row->state ?? '')];
            }
            if (!in_array($row->state, ['due', 'review'], true)) {
                return ['outcome' => 'not-allowed', 'state' => (string) $row->state];
            }
            $this->changeRow($refundId, ['state' => 'skipped',
                'note' => 'Dismissed by an admin: nothing is booked for what the store reported so far.']);
            return ['outcome' => 'dismissed', 'state' => 'skipped'];
        });
    }
}
