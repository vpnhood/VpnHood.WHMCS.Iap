<?php
/**
 * suppress-emails.test.php — the EmailPreSend hook aborts invoice mail for
 * vpnhoodiap-paid invoices and leaves every other invoice's mail alone.
 *
 * Runs ON the dev server. Creates two draft invoices for the test buyer via
 * localAPI (one on the vpnhoodiap bookkeeping gateway, one on banktransfer),
 * fires the EmailPreSend hook point exactly as WHMCS's mailer does, asserts
 * abortsend, then cancels both invoices. No mail is actually sent at any
 * point (the hook is exercised directly; sendinvoice is off).
 */

require __DIR__ . '/lib/common.php';

$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixture missing: ' . BUYER_EMAIL . ' — run the hub repo bootstrap first');
    finish();
}

/** Create a minimal draft invoice; returns id or 0. */
function createDraftInvoice(int $clientId, string $gateway): int
{
    $r = localAPI('CreateInvoice', [
        'userid'           => $clientId,
        'status'           => 'Unpaid',
        'sendinvoice'      => '0',
        'paymentmethod'    => $gateway,
        'itemdescription1' => 'vpnhoodiap suppress-emails test item',
        'itemamount1'      => '1.00',
        'itemtaxed1'       => '0',
    ]);
    return ($r['result'] ?? '') === 'success' ? (int) $r['invoiceid'] : 0;
}

$iapInvoiceId = createDraftInvoice((int) $buyer['id'], 'vpnhoodiappay');
$otherInvoiceId = createDraftInvoice((int) $buyer['id'], 'banktransfer');
if ($iapInvoiceId > 0 && $otherInvoiceId > 0) {
    ok("draft invoices created (vpnhoodiappay #$iapInvoiceId, banktransfer #$otherInvoiceId)");
} else {
    bad("could not create draft invoices (iap=$iapInvoiceId, other=$otherInvoiceId)");
    finish();
}

/** Merge the hook-point results the way WHMCS's mailer consumes them. */
function emailPreSendAborts(string $template, int $invoiceId): bool
{
    $results = run_hook('EmailPreSend', [
        'messagename' => $template,
        'relid'       => $invoiceId,
    ]);
    foreach ((array) $results as $result) {
        if (is_array($result) && !empty($result['abortsend'])) {
            return true;
        }
    }
    return false;
}

$templates = [
    'Invoice Created',
    'Invoice Payment Reminder',
    'First Invoice Overdue Notice',
    'Invoice Payment Confirmation',
];
foreach ($templates as $template) {
    if (emailPreSendAborts($template, $iapInvoiceId)) {
        ok("'$template' aborted for the vpnhoodiappay invoice");
    } else {
        bad("'$template' NOT aborted for the vpnhoodiappay invoice");
    }
}

// negative cases: other-gateway invoice and a non-invoice template must pass through
if (!emailPreSendAborts('Invoice Created', $otherInvoiceId)) {
    ok('banktransfer invoice mail passes through untouched');
} else {
    bad('banktransfer invoice mail was wrongly aborted');
}
if (!emailPreSendAborts('Password Reset Validation', $iapInvoiceId)) {
    ok('non-invoice template passes through untouched');
} else {
    bad('non-invoice template was wrongly aborted');
}

// -- cleanup ----------------------------------------------------------------
foreach ([$iapInvoiceId, $otherInvoiceId] as $invoiceId) {
    $r = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
    if (($r['result'] ?? '') === 'success') {
        ok("invoice #$invoiceId cancelled");
    } else {
        bad("could not cancel invoice #$invoiceId: " . json_encode($r));
    }
}

finish();
