<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Places one WHMCS order for a store purchase, WHMCS-native end to end:
 * AddOrder → AddInvoicePayment (transid = store order id, gateway =
 * vpnhoodiappay) → assert Paid → AcceptOrder(autosetup) → WHMCS runs the
 * product's own provisioning module. Failure rolls the order back
 * (CancelOrder + DeleteOrder) so nothing half-provisioned survives.
 *
 * (Mirrors vpnhoodpartnerhub PartnerApiController::placeSingleOrder, with
 * the store payment replacing the credit settle.)
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
     * @return array{orderId:int, invoiceId:int, serviceId:int}
     * @throws ApiException
     */
    public function placeOrder(int $clientId, int $whmcsProductId, int $billingCycleMonths, string $transactionId): array
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

        try {
            // the store already collected the money — record it and require Paid
            if ($invoiceId > 0) {
                $this->localApi('AddInvoicePayment', [
                    'invoiceid' => $invoiceId,
                    'transid'   => $transactionId,
                    'gateway'   => self::GATEWAY,
                    'noemail'   => true,
                ]);
                $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
                if ($status !== 'Paid') {
                    throw new ApiException("Order invoice #$invoiceId is not Paid after payment (status: $status).", 502);
                }
            }

            // provision through the product's own module
            $this->localApi('AcceptOrder', [
                'orderid'   => $orderId,
                'autosetup' => true,
                'sendemail' => false,
            ]);
        } catch (\Throwable $e) {
            $this->safeDeleteOrder($orderId);
            throw $e instanceof ApiException ? $e : new ApiException('Provisioning failed.', 502, 'provisioning_failed');
        }

        return ['orderId' => $orderId, 'invoiceId' => $invoiceId, 'serviceId' => $serviceId];
    }

    /**
     * Fully remove a failed order (order + invoice + service). DeleteOrder
     * refuses unless the order is Cancelled/Fraud, so cancel first. Both calls
     * are best-effort and logged — an incomplete rollback is never silent.
     */
    public function safeDeleteOrder(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        $cancel = localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        $delete = localAPI('DeleteOrder', ['orderid' => $orderId]);
        $this->repo->log(null, 'order.rollback', '', 0, ['orderid' => $orderId], [
            'cancel' => $cancel['result'] ?? '?',
            'delete' => $delete['result'] ?? '?',
        ]);
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
        int $clientId, bool $isPrimary): void
    {
        if ($invoiceId <= 0 || $transactionId === '' || $amount === null || $currency === null) {
            return;
        }
        // bundle-secondary invoices always zero: the whole charge is stated once
        $newTotal = $isPrimary && strcasecmp($currency, self::clientCurrencyCode($clientId)) === 0
            ? $amount
            : '0.00';
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
