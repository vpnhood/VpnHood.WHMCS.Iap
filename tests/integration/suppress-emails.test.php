<?php
/**
 * suppress-emails.test.php — the EmailPreSend hook aborts WHMCS mail for records
 * that belong to the vpnhoodiap gateway (invoice lifecycle AND the product welcome
 * email) and leaves every other invoice's and service's mail alone.
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

// -- product welcome mail: fired by module-create, NOT covered by AcceptOrder's
//    sendemail:false — the live regression that leaked "New Product Information"
//    to a store buyer. The template is per-product, so the hook keys on the
//    template TYPE, not a name list.
$productTemplate = (string) \WHMCS\Database\Capsule::table('tblemailtemplates')
    ->where('type', 'product')->where('name', 'Other Product/Service Welcome Email')->value('name');
if ($productTemplate === '') {
    bad('fixture missing: no product welcome template on this install');
} else {
    $iapService = \WHMCS\Database\Capsule::table('tblhosting')
        ->where('paymentmethod', 'vpnhoodiappay')->orderByDesc('id')->first(['id']);
    $otherService = \WHMCS\Database\Capsule::table('tblhosting')
        ->where('paymentmethod', '!=', 'vpnhoodiappay')->orderByDesc('id')->first(['id']);

    if ($iapService === null) {
        bad('fixture missing: no vpnhoodiappay service to test the welcome mail against');
    } elseif (emailPreSendAborts($productTemplate, (int) $iapService->id)) {
        ok("product welcome mail aborted for the store service #{$iapService->id}");
    } else {
        bad("product welcome mail NOT aborted for the store service #{$iapService->id}");
    }

    if ($otherService !== null) {
        if (!emailPreSendAborts($productTemplate, (int) $otherService->id)) {
            ok('product welcome mail passes through for a non-store service');
        } else {
            bad("product welcome mail wrongly aborted for service #{$otherService->id}");
        }
    }
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
