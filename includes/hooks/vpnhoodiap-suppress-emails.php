<?php

/**
 * VpnHood! IAP — WHMCS mail suppression for app-store customers.
 *
 * Customers who bought through an app store pay the STORE, not WHMCS. WHMCS's own
 * mail about those orders — invoice lifecycle ("Invoice Created", reminders, overdue
 * notices, payment confirmations) and the product welcome email — would tell them to
 * pay again, confirm a payment they made elsewhere, or hand them panel details they
 * never asked for. This hook aborts that mail for records belonging to the
 * vpnhoodiappay bookkeeping gateway, and only those; every other invoice and service
 * on the install mails exactly as before.
 *
 * The template's own TYPE decides which record `relid` points at, so this covers
 * every current and future invoice/product template without a name list to maintain
 * (the product welcome template is per-product: "Premium Code" uses "Other
 * Product/Service Welcome Email", another product may use a different one).
 *
 * Verified on the dev WHMCS: EmailPreSend fires for invoice templates with relid =
 * invoice id and for product welcome mail with relid = service id; returning
 * ['abortsend' => true] suppresses the send. AcceptOrder(sendemail: false) does NOT
 * cover the welcome mail — that one is fired by the module-create path (observed
 * live 2026-08-06: two "New Product Information" mails reached a store buyer).
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

add_hook('EmailPreSend', 1, function (array $vars) {
    $messageName = (string) ($vars['messagename'] ?? '');
    $relatedId = (int) ($vars['relid'] ?? 0);
    if ($messageName === '' || $relatedId <= 0) {
        return [];
    }

    try {
        $templateType = (string) Capsule::table('tblemailtemplates')
            ->where('name', $messageName)
            ->value('type');

        // which table `relid` refers to is a property of the template type
        $paymentMethod = match ($templateType) {
            'invoice' => (string) Capsule::table('tblinvoices')->where('id', $relatedId)->value('paymentmethod'),
            'product' => (string) Capsule::table('tblhosting')->where('id', $relatedId)->value('paymentmethod'),
            default   => '',
        };
    } catch (\Throwable $e) {
        // never let a lookup failure take the mail pipeline down; default to sending
        return [];
    }

    return $paymentMethod === 'vpnhoodiappay' ? ['abortsend' => true] : [];
});
