<?php

/**
 * VpnHood! IAP — refund fingerprint (lifecycle §8).
 *
 * Whenever an invoice is refunded — website refunds an admin performs in the
 * WHMCS UI included, not only the store webhooks RefundService handles — the
 * refunded account gets its disclosed 24-month one-way fingerprint, and a
 * repeat refund is alerted. The mark is a hash: it cannot be turned back into
 * a person, and it survives account deletion, which is its entire point.
 *
 * Best-effort by design: a marking failure must never break WHMCS's refund.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('InvoiceRefunded', 1, function (array $vars) {
    try {
        $moduleDir = ROOTDIR . '/modules/addons/vpnhoodiap';
        if (!file_exists($moduleDir . '/lib/IapRepository.php')) {
            return;
        }
        require_once $moduleDir . '/lib/IapRepository.php';
        if (!\WHMCS\Module\Addon\VpnHoodIap\IapRepository::isModuleActive()) {
            return;
        }
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }
        $clientId = (int) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid');
        if ($clientId <= 0) {
            return;
        }
        $email = (string) Capsule::table('tblclients')->where('id', $clientId)->value('email');
        if ($email === '' || str_ends_with($email, '@anonymized.invalid')) {
            return; // deleted account — no person left to fingerprint
        }
        $repo = new \WHMCS\Module\Addon\VpnHoodIap\IapRepository();
        if ($repo->hasRefundMark($email)) {
            localAPI('LogActivity', ['description' =>
                "vpnhoodiap: repeat refund — client #{$clientId} was refunded before (within 24 months)."]);
        }
        $repo->addRefundMark($email);
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodiap', 'hook.refund-mark', $vars, $e->getMessage(), '');
    }
});
