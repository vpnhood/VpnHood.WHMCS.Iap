<?php

/**
 * VpnHood! IAP — bookkeeping payment gateway.
 *
 * This gateway never collects money: the app store (Google Play / Apple / Microsoft)
 * is the merchant of record. It exists so invoices created by the vpnhoodiap addon can
 * carry paymentmethod=vpnhoodiap and payments can carry the store order id as the
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

function vpnhoodiap_MetaData(): array
{
    return [
        'DisplayName'                 => 'App Store Purchase (VpnHood IAP)',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

function vpnhoodiap_config(): array
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'App Store Purchase (VpnHood IAP)',
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
function vpnhoodiap_link(array $params): string
{
    return '<p>Billed through the app store.</p>';
}
