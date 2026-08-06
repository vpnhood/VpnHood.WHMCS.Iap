<?php

/**
 * VpnHood! IAP — bookkeeping payment gateway.
 *
 * This gateway never collects money: the app store (Google Play / Apple / Microsoft)
 * is the merchant of record. It exists so invoices created by the vpnhoodiap addon can
 * carry paymentmethod=vpnhoodiappay and payments can carry the store order id as the
 * transaction id — which doubles as the idempotency key, since store order ids are
 * globally unique. The addon records payments via localAPI AddInvoicePayment.
 *
 * Keep "Show on Order Form" disabled: this gateway must never appear at checkout.
 * Refunds are store-initiated only; there is deliberately no _refund function here —
 * the addon represents store refunds itself (negative transaction + invoice Refunded
 * + service termination).
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function vpnhoodiappay_MetaData(): array
{
    return [
        'DisplayName'                 => 'In-App Purchase (billed by the app store)',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

function vpnhoodiappay_config(): array
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'In-App Purchase (billed by the app store)',
        ],
        'Description' => [
            'Type'  => 'System',
            'Value' => 'Bookkeeping-only gateway used by the VpnHood IAP addon to record'
                . ' app-store purchases. Payment is collected by the store; never show this'
                . ' gateway on the order form.',
        ],
    ];
}

/**
 * No payment link: the invoice is always already paid (or about to be marked paid by
 * the addon). Returning a static note keeps the client area from rendering a dead
 * "Pay Now" flow if this gateway is ever exposed by mistake.
 */
function vpnhoodiappay_link(array $params): string
{
    return '<p>Billed through the app store.</p>';
}
