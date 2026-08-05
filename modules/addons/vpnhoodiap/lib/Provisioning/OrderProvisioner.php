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
