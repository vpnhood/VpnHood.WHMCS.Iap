<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

require_once __DIR__ . '/DeliveryReader.php'; // a failed create's code is seen by the one provisioning-aware reader

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Places one WHMCS order for a store purchase, WHMCS-native end to end:
 * AddOrder → AcceptOrder(autosetup), where WHMCS runs the product's own
 * provisioning module → AddInvoicePayment (transid = store order id, gateway =
 * vpnhoodiappay) → assert Paid. The store collected the money already, so its
 * payment is recorded last, once the code exists: a provisioning failure removes
 * the order while it is still unpaid (no API removes a recorded payment, and
 * DeleteOrder leaves one behind), and a delivered code is never rolled back.
 */
class OrderProvisioner
{
    // the bookkeeping gateway (modules/gateways/vpnhoodiappay.php) — named
    // differently from the addon because WHMCS loads addon AND gateway config
    // functions in one admin request; a shared "vpnhoodiap" prefix would fatal
    // with "cannot redeclare vpnhoodiap_config()"
    public const GATEWAY = 'vpnhoodiappay';

    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * @param string $transactionId store order id — doubles as the payment idempotency key
     * @param ?string $dueDate the term the store's paid time calls for (TermSync::dueDateFor),
     *                         set before provisioning so the module creates the code with it;
     *                         null keeps WHMCS's own order date + cycle
     * @param ?int $purchaseId the ledger row the order delivers, which learns the order as soon as
     *                         the code exists; null outside a purchase
     * @return array{orderId:int, invoiceId:int, serviceId:int}
     * @throws ApiException
     */
    public function placeOrder(int $clientId, int $whmcsProductId, int $billingCycleMonths, string $transactionId,
        ?string $dueDate, ?int $purchaseId = null): array
    {
        $add = $this->localApi('AddOrder', [
            'clientid'       => $clientId,
            'pid'            => $whmcsProductId,
            'billingcycle'   => self::billingCycle($billingCycleMonths),
            'paymentmethod'  => self::GATEWAY,
            'noemail'        => true,
            'noinvoiceemail' => true,
        ]);
        $orderId = (int) ($add['orderid'] ?? 0);
        $invoiceId = (int) ($add['invoiceid'] ?? 0);
        $serviceId = (int) (explode(',', (string) ($add['productids'] ?? ''))[0] ?? 0);
        if ($orderId <= 0 || $serviceId <= 0) {
            throw new ApiException('Order creation failed.', 502, 'provisioning_failed');
        }

        // AddOrder SUBSTITUTES the default visible gateway when the requested one is hidden from
        // checkout — and ours must be hidden (it collects nothing). Stamp the rows back, or store
        // purchases read as paid by whatever gateway the website happens to run, and everything
        // that filters on this module's gateway (renewal-invoice cleanup, monitors) goes blind.
        Capsule::table('tblorders')->where('id', $orderId)->update(['paymentmethod' => self::GATEWAY]);
        Capsule::table('tblhosting')->where('id', $serviceId)->update(['paymentmethod' => self::GATEWAY]);
        if ($invoiceId > 0) {
            Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['paymentmethod' => self::GATEWAY]);
        }

        try {
            // the term is the STORE's, not the order date's: a migrated or restored subscription
            // is weeks into its cycle, and the module creates the code from the service's due date
            if ($dueDate !== null) {
                $this->localApi('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $dueDate]);
            }

            // provision through the product's own module; the payment is not recorded yet
            $this->localApi('AcceptOrder', [
                'orderid'   => $orderId,
                'autosetup' => true,
                'sendemail' => false,
            ]);
        } catch (\Throwable $e) {
            $this->removeUnpaidOrder($orderId);
            throw $e instanceof ApiException ? $e : new ApiException('Provisioning failed.', 502, 'provisioning_failed');
        }

        // the code exists: the ledger learns its order before anything else can fail, so a retry
        // after a crash retires this code once it delivers another (EntitlementService)
        if ($purchaseId !== null) {
            $this->repo->recordPurchaseDelivery($purchaseId, $orderId, $serviceId);
        }

        $paid = $this->recordStorePayment($orderId, $serviceId, $invoiceId, $transactionId, $dueDate, $billingCycleMonths);

        // Paying the invoice of an active service moves its due date a cycle on (WHMCS books it as
        // a renewal) and leaves the code as it is: the store's term is written back. Without a store
        // term the code was created from the order date, and is renewed to the first term the
        // payment just set. Loud on failure, never fatal: the order is delivered.
        if ($dueDate !== null) {
            $moved = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $dueDate]);
            if (($moved['result'] ?? '') !== 'success') {
                $this->repo->alert("vpnhoodiap: service #$serviceId could not be set back to $dueDate (the store's paid time)"
                    . ' after its payment: ' . json_encode($moved) . ' — set its next due date by hand, or the nightly code'
                    . ' repair extends its code a cycle past what the store sold.');
            }
        } elseif ($billingCycleMonths > 0 && $paid) {
            (new TermSync($this->repo))->renewCode($serviceId);
        }

        return ['orderId' => $orderId, 'invoiceId' => $invoiceId, 'serviceId' => $serviceId];
    }

    /**
     * Record the store's payment on the delivered order's invoice and require Paid. A failure is
     * loud and never fatal: the store holds the money and the code is delivered, so only the
     * bookkeeping waits for a person, told what the payment would have set.
     *
     * @return bool whether the invoice is Paid (true for a free order, which bills nothing)
     */
    private function recordStorePayment(int $orderId, int $serviceId, int $invoiceId, string $transactionId,
        ?string $dueDate, int $billingCycleMonths): bool
    {
        if ($invoiceId <= 0) {
            return true;
        }
        $error = null;
        try {
            $this->localApi('AddInvoicePayment', [
                'invoiceid' => $invoiceId,
                'transid'   => $transactionId,
                'gateway'   => self::GATEWAY,
                'noemail'   => true,
            ]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if ($status !== 'Paid') {
            $this->repo->alert("vpnhoodiap: order #$orderId is delivered, but the store's payment could not be recorded on"
                . " invoice #$invoiceId (status $status" . ($error !== null ? ": $error" : '') . '). Record it by hand: set'
                . " the invoice back to Unpaid if WHMCS cancelled it, add the payment (transaction id $transactionId, gateway "
                . self::GATEWAY . ')' . match (true) {
                    $dueDate !== null => ", then set service #$serviceId's next due date back to $dueDate, as the payment moves it a cycle on.",
                    $billingCycleMonths > 0 => ", then run Renew on service #$serviceId: its code was created from the order"
                        . ' date and follows the first term only once renewed.',
                    default => '.',
                });
            return false;
        }
        if ($error !== null) {
            // the payment is recorded; what failed ran after it (a hook)
            $this->repo->log(null, 'order.payment', '', 0, ['orderid' => $orderId, 'invoiceid' => $invoiceId], $error);
        }
        return true;
    }

    /**
     * Remove an order whose provisioning failed before its payment was recorded. A code the module
     * made before failing (recorded on a service WHMCS still calls Pending) is ended first; CancelOrder
     * then cancels the unpaid invoice and ends an active service's code (WHMCS runs Module Terminate),
     * and DeleteOrder removes order, service and invoice. A service whose code could not be ended
     * keeps its records, as deleting them would leave the code live with nothing pointing at it, and
     * whatever survives is said out loud. A code whose creation never reached the service (an answer
     * lost on the way back) cannot be seen from here.
     */
    private function removeUnpaidOrder(int $orderId): void
    {
        $reader = new DeliveryReader();
        $unended = [];
        foreach (Capsule::table('tblhosting')->where('orderid', $orderId)->where('domainstatus', 'Pending')->pluck('id') as $serviceId) {
            if ($reader->holdsCode((int) $serviceId)) {
                $ended = localAPI('ModuleTerminate', ['serviceid' => (int) $serviceId]);
                if (($ended['result'] ?? '') !== 'success') {
                    $unended[(int) $serviceId] = (string) ($ended['message'] ?? $ended['result'] ?? '?');
                }
            }
        }
        $cancel = localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        foreach (Capsule::table('tblhosting')->where('orderid', $orderId)->whereIn('domainstatus', ['Active', 'Suspended'])
            ->pluck('id') as $serviceId) {
            $unended[(int) $serviceId] ??= 'still ' . Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
        }
        $delete = $unended === [] ? localAPI('DeleteOrder', ['orderid' => $orderId]) : null;
        $this->repo->log(null, 'order.rollback', '', 0, ['orderid' => $orderId], [
            'cancel'  => $cancel['result'] ?? '?',
            'delete'  => $delete['result'] ?? 'skipped',
            'unended' => $unended,
        ]);
        if ($unended !== [] || Capsule::table('tblorders')->where('id', $orderId)->exists()) {
            $codes = implode('; ', array_map(fn ($id, $why) => "service #$id ($why)", array_keys($unended), $unended));
            $this->repo->alert("vpnhoodiap: the failed order #$orderId could not be removed (cancel: "
                . ($cancel['message'] ?? $cancel['result'] ?? '?') . ', delete: '
                . ($delete === null ? 'skipped' : ($delete['message'] ?? $delete['result'] ?? '?')) . ')'
                . ($unended !== [] ? " — a code is still live: $codes. Terminate it, then delete the order." : '.'));
        }
    }

    /**
     * Make the invoice tell the truth to anyone who ever opens it: the money
     * moved at the store, the WHMCS amount is internal bookkeeping. Appends the
     * real charge when the store reported one. Best-effort — a cosmetic line
     * must never fail provisioning.
     */
    public function annotateInvoice(int $invoiceId, string $store, ?string $amount, ?string $currency): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $note = 'Billed via ' . self::storeLabel($store) . ' — nothing is due here; this record is for bookkeeping.';
        $note .= $amount !== null && $currency !== null
            ? " The store charged $amount $currency (see your store receipt)."
            : ' See your store receipt for the exact charge.';
        try {
            // UpdateInvoice treats the line arrays as one unit: every provided
            // line needs description + amount + taxed together
            $updates = ['invoiceid' => $invoiceId];
            foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get() as $item) {
                if (str_contains((string) $item->description, 'Billed via')) {
                    continue; // already annotated (renewal replay)
                }
                $updates['itemdescription'][$item->id] = $item->description . "\n" . $note;
                $updates['itemamount'][$item->id] = (string) $item->amount;
                $updates['itemtaxed'][$item->id] = (int) $item->taxed;
            }
            if (count($updates) > 1) {
                $this->localApi('UpdateInvoice', $updates);
            }
        } catch (\Throwable $e) {
            $this->repo->log(null, 'invoice.annotate', '', 0, ['invoiceid' => $invoiceId], $e->getMessage());
        }
    }

    /**
     * A terminated store service must not leave its future renewal invoice
     * behind: the subscription is gone at the store, so that Unpaid invoice
     * would sit forever (mail is suppressed, nobody can pay it) as admin
     * clutter. Only this module's own gateway is ever touched.
     */
    public function cancelUnpaidRenewalInvoices(int $serviceId): void
    {
        if ($serviceId <= 0) {
            return;
        }
        try {
            $invoiceIds = Capsule::table('tblinvoiceitems as it')
                ->join('tblinvoices as i', 'i.id', '=', 'it.invoiceid')
                ->where('it.type', 'Hosting')
                ->where('it.relid', $serviceId)
                ->where('i.status', 'Unpaid')
                ->where('i.paymentmethod', self::GATEWAY)
                ->distinct()->pluck('it.invoiceid')->all();
            foreach ($invoiceIds as $invoiceId) {
                $this->localApi('UpdateInvoice', ['invoiceid' => (int) $invoiceId, 'status' => 'Cancelled']);
            }
        } catch (\Throwable $e) {
            $this->repo->log(null, 'invoice.cleanup', '', 0, ['serviceid' => $serviceId], $e->getMessage());
        }
    }

    /**
     * End a service for good, with the renewal invoice it would otherwise leave behind. False,
     * loudly, when the module could not end a service that is still live: the code keeps
     * working, and the caller must not record the service as gone.
     */
    public function terminateService(int $serviceId, string $why): bool
    {
        $result = localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
        if (($result['result'] ?? '') !== 'success') {
            $status = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
            if (in_array($status, ['Active', 'Suspended'], true)) {
                $this->repo->alert("vpnhoodiap: terminating service #$serviceId ($why) failed: "
                    . json_encode($result) . ' — its code is still live.');
                return false;
            }
            // already ended — nothing left to terminate
        }
        $this->cancelUnpaidRenewalInvoices($serviceId);
        return true;
    }

    /**
     * Re-enable a Suspended service the store pays for again (a recovery, a restore while
     * held). Loud on failure: the store charged, and the code would stay disabled.
     */
    public function unsuspendService(int $serviceId): bool
    {
        $result = localAPI('ModuleUnsuspend', ['serviceid' => $serviceId]);
        if (($result['result'] ?? '') === 'success') {
            return true;
        }
        $this->repo->alert("vpnhoodiap: unsuspending service #$serviceId failed: " . json_encode($result)
            . ' — the store grants the purchase, the code stays disabled.');
        return false;
    }

    /**
     * Make the PAID invoice carry the store's value instead of the book price:
     * the machinery (payment event, _Renew, dedup) has already fired, so this
     * is bookkeeping-only. Exact when the store charged in the client's own
     * currency; 0.00 when it charged in another one — the explicit "the money
     * lives at the store" flag (the annotation text carries the real foreign
     * amount). Never converts. No real charge known → the book price stays.
     *
     * Order of operations is deliberate and dev-verified: transaction first,
     * then the invoice lines — the invoice stays Paid and no client credit is
     * ever created. Only this module's own records are ever touched (the
     * transaction is looked up by OUR transid, the invoice was placed by us).
     */
    public function applyStoreValue(int $invoiceId, string $transactionId, ?string $amount, ?string $currency,
        int $clientId): void
    {
        if ($invoiceId <= 0 || $transactionId === '' || $amount === null || $currency === null) {
            return;
        }
        $newTotal = strcasecmp($currency, self::clientCurrencyCode($clientId)) === 0 ? $amount : '0.00';
        try {
            // Every localAPI call here goes through the checked wrapper: these commands
            // answer a FAILED update with result=error instead of throwing, so calling
            // them raw made a rejected rewrite indistinguishable from a successful one —
            // the invoice would move and its payment would silently stay behind.
            // Scoped to THIS invoice, not to the transid alone: a store order id is not
            // unique across payments — re-provisioning a terminated service pays the new
            // invoice with the same store order id — so matching on transid alone picked
            // the OLDEST payment carrying it and rewrote a previous invoice's transaction
            // while the current one silently kept the WHMCS book price.
            $transaction = Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->where('transid', $transactionId)
                ->first(['id', 'amountin']);
            if ($transaction === null) {
                // the payment is the anchor of this rewrite; without it the invoice
                // would disagree with its own transaction, so never skip in silence
                $this->repo->log(null, 'invoice.storevalue', '', 0,
                    ['invoiceid' => $invoiceId, 'transid' => $transactionId],
                    'no transaction carries this transid — the payment amount was left at the book price');
            } elseif ((float) $transaction->amountin !== (float) $newTotal) {
                $this->localApi('UpdateTransaction', ['transactionid' => (int) $transaction->id, 'amountin' => $newTotal]);
            }
            $updates = ['invoiceid' => $invoiceId];
            $first = true;
            foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get() as $item) {
                $updates['itemdescription'][$item->id] = $item->description;
                $updates['itemamount'][$item->id] = $first ? $newTotal : '0.00';
                $updates['itemtaxed'][$item->id] = 0; // the store handled tax; never re-tax
                $first = false;
            }
            if (count($updates) > 1) {
                $this->localApi('UpdateInvoice', $updates);
            }
        } catch (\Throwable $e) {
            $this->repo->log(null, 'invoice.storevalue', '', 0, ['invoiceid' => $invoiceId], $e->getMessage());
        }
    }

    /**
     * Record which store sold this service, as a service property on the service
     * itself. The provisioning module (vpnhoodstore on the hub, vpnhoodpartner on a
     * partner install) reads it back to badge the client area and the admin service
     * page, so a customer and an admin can both see that the money moved at Google
     * Play rather than here — and that cancellations belong there too.
     *
     * Provisioning-agnostic on purpose: this writes a WHMCS service property through
     * the service model, never a vpnhoodstore/vpnhoodpartner function, so the same
     * call serves every provisioning module and the rule about not reaching into
     * them still holds. The property name is the contract between the two sides.
     *
     * Best-effort: a badge is presentation, and must never fail a provisioned order.
     */
    public function tagServiceStore(int $serviceId, string $store): void
    {
        if ($serviceId <= 0) {
            return;
        }
        try {
            $service = \WHMCS\Service\Service::find($serviceId);
            $service?->serviceProperties->save(['purchasedVia' => $store]);
        } catch (\Throwable $e) {
            $this->repo->log(null, 'service.storetag', '', 0, ['serviceid' => $serviceId, 'store' => $store],
                $e->getMessage());
        }
    }

    /** Client id → its WHMCS currency code ('' when unresolvable — never matches). */
    public static function clientCurrencyCode(int $clientId): string
    {
        $currencyId = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
        return (string) (Capsule::table('tblcurrencies')->where('id', $currencyId)->value('code') ?? '');
    }

    /** Store id → the name a customer knows it by. */
    public static function storeLabel(string $store): string
    {
        return match ($store) {
            'googleplay' => 'Google Play',
            'appstore'   => 'the Apple App Store',
            'microsoft'  => 'the Microsoft Store',
            default      => 'the app store',
        };
    }

    /** Catalog cycle months → WHMCS billing cycle name. */
    public static function billingCycle(int $months): string
    {
        return match ($months) {
            0       => 'onetime',
            1       => 'monthly',
            3       => 'quarterly',
            6       => 'semiannually',
            12      => 'annually',
            24      => 'biennially',
            36      => 'triennially',
            default => throw new ApiException("Unsupported billing cycle: $months months.", 422),
        };
    }

    /** @throws ApiException when the localAPI result is not success */
    private function localApi(string $command, array $params): array
    {
        $result = localAPI($command, $params);
        if (($result['result'] ?? '') !== 'success') {
            $message = (string) ($result['message'] ?? 'unknown error');
            throw new ApiException("$command failed: $message", 502);
        }
        return $result;
    }
}
