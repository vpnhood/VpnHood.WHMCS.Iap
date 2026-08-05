<?php

/**
 * VpnHood! IAP — invoice email suppression.
 *
 * Customers who bought through an app store pay the STORE, not WHMCS; WHMCS's own
 * invoice lifecycle mail ("Invoice Created", payment reminders, overdue notices,
 * payment confirmations) would tell them to pay again or confirm a payment they made
 * elsewhere. This hook aborts those templates for invoices that belong to the
 * vpnhoodiap bookkeeping gateway, and only those — every other invoice on the install
 * mails exactly as before.
 *
 * Verified on the dev WHMCS (2026-08-04): EmailPreSend fires for invoice templates with
 * relid = invoice id, and returning ['abortsend' => true] suppresses the send.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

add_hook('EmailPreSend', 1, function (array $vars) {
    static $suppressedTemplates = [
        'Invoice Created',
        'Invoice Modified',
        'Invoice Payment Reminder',
        'First Invoice Overdue Notice',
        'Second Invoice Overdue Notice',
        'Third Invoice Overdue Notice',
        'Invoice Payment Confirmation',
        'Invoice Refund Confirmation',
    ];

    $messageName = (string) ($vars['messagename'] ?? '');
    if (!in_array($messageName, $suppressedTemplates, true)) {
        return [];
    }

    $invoiceId = (int) ($vars['relid'] ?? 0);
    if ($invoiceId <= 0) {
        return [];
    }

    try {
        $paymentMethod = (string) Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('paymentmethod');
    } catch (\Throwable $e) {
        // never let a lookup failure take the mail pipeline down; default to sending
        return [];
    }

    if ($paymentMethod === 'vpnhoodiap') {
        return ['abortsend' => true];
    }

    return [];
});
