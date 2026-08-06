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
 * vpnhoodiap) → assert Paid → AcceptOrder(autosetup) → WHMCS runs the
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
            throw new ApiException('Order creation failed.', 502);
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
            throw $e instanceof ApiException ? $e : new ApiException('Provisioning failed.', 502);
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
                localAPI('UpdateInvoice', $updates);
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
                localAPI('UpdateInvoice', ['invoiceid' => (int) $invoiceId, 'status' => 'Cancelled']);
            }
        } catch (\Throwable $e) {
            $this->repo->log(null, 'invoice.cleanup', '', 0, ['serviceid' => $serviceId], $e->getMessage());
        }
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
