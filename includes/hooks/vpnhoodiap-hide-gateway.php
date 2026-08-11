<?php

/**
 * VpnHood! IAP — keep the bookkeeping gateway out of checkout.
 *
 * vpnhoodiappay never collects money (the app store is the merchant of record); it
 * exists so store-created invoices carry an honest payment method. Its "Show on
 * Order Form" flag is clamped off by the addon, but that is a checkbox an admin can
 * re-tick and gateway activation defaults on — this hook is the second layer: it
 * strips the gateway from the cart's payment-method list before any template
 * renders it. Effective on every theme that renders the standard $gateways
 * variable (standard_cart and the themes skinning it — verified on lagom2). A
 * checkout that bypasses both layers still cannot sell anything: the gateway has
 * no capture flow, so its invoices can never be paid and no service provisions.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('ClientAreaPageCart', 1, function (array $vars) {
    if (empty($vars['gateways']) || !is_array($vars['gateways'])) {
        return [];
    }

    $gateways = array_filter(
        $vars['gateways'],
        fn ($gateway) => (is_array($gateway) ? ($gateway['sysname'] ?? '') : '') !== 'vpnhoodiappay'
    );

    // re-index: order-form templates address the list numerically
    return ['gateways' => array_values($gateways)];
});
