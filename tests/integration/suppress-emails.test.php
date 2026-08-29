<?php
/**
 * suppress-emails.test.php — the EmailPreSend hook aborts WHMCS mail that must never
 * be sent: records belonging to the vpnhoodiappay gateway (invoice lifecycle AND the
 * product welcome email), and anything addressed to a client account deletion has
 * anonymized. Every other invoice, service and client mails exactly as before.
 *
 * Runs ON the dev server. Creates draft invoices for the test buyer via localAPI (one
 * on the vpnhoodiappay bookkeeping gateway, one on banktransfer) plus a throwaway
 * client carrying the deleted-<id>@anonymized.invalid address, fires the EmailPreSend
 * hook point exactly as WHMCS's mailer does, asserts abortsend, then removes both.
 * No mail is actually sent at any point (the hook is exercised directly; sendinvoice
 * is off).
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
function emailPreSendAborts(string $template, int $relatedId): bool
{
    $results = run_hook('EmailPreSend', [
        'messagename' => $template,
        'relid'       => $relatedId,
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

// -- deleted people: nothing WHMCS generates may be addressed to an erased client.
//    Deletion keeps the client row (it anchors the retained invoices) with an
//    unroutable deleted-<id>@anonymized.invalid address, so every send WHMCS
//    automation still aims at it is a guaranteed bounce.
$erasedClient = localAPI('AddClient', [
    'firstname'      => 'Suppress',
    'lastname'       => 'Erased',
    'email'          => 'suppress-erased-' . bin2hex(random_bytes(4)) . '@vpnhood.com',
    'password2'      => bin2hex(random_bytes(12)),
    'country'        => 'US',
    'skipvalidation' => true,
    'noemail'        => true,
]);
$erasedClientId = ($erasedClient['result'] ?? '') === 'success' ? (int) $erasedClient['clientid'] : 0;
$erasedInvoiceId = 0;
if ($erasedClientId <= 0) {
    bad('could not create the erased-client fixture: ' . json_encode($erasedClient));
} else {
    // exactly what AccountDeletionService::anonymizeClient writes
    $anonymized = localAPI('UpdateClient', [
        'clientid'       => $erasedClientId,
        'email'          => "deleted-$erasedClientId@anonymized.invalid",
        'skipvalidation' => true,
    ]);
    if (($anonymized['result'] ?? '') !== 'success') {
        bad('could not anonymize the fixture client: ' . json_encode($anonymized));
    } else {
        ok("erased-client fixture #$erasedClientId anonymized");

        // a general template's relid IS the client id
        if (emailPreSendAborts('Password Reset Validation', $erasedClientId)) {
            ok('client-addressed mail is aborted for the erased client');
        } else {
            bad('client-addressed mail was NOT aborted for the erased client');
        }

        // and mail about one of its records, on an ordinary gateway, is aborted too
        $erasedInvoiceId = createDraftInvoice($erasedClientId, 'banktransfer');
        if ($erasedInvoiceId <= 0) {
            bad('could not create an invoice for the erased client');
        } elseif (emailPreSendAborts('Invoice Created', $erasedInvoiceId)) {
            ok('invoice mail is aborted for the erased client, gateway notwithstanding');
        } else {
            bad("invoice mail NOT aborted for the erased client's invoice #$erasedInvoiceId");
        }

        // the merge fields alone are enough, even with a relid we cannot resolve
        $mergeOnly = run_hook('EmailPreSend', [
            'messagename' => 'Password Reset Validation',
            'relid'       => 0,
            'mergefields' => ['client_email' => "deleted-$erasedClientId@anonymized.invalid"],
        ]);
        $mergeAborted = false;
        foreach ((array) $mergeOnly as $result) {
            $mergeAborted = $mergeAborted || (is_array($result) && !empty($result['abortsend']));
        }
        $mergeAborted
            ? ok('an erased address in the merge fields aborts the send on its own')
            : bad('merge-field-only erased address did not abort the send');
    }
}

// negative: a live client's own mail is untouched
if (!emailPreSendAborts('Password Reset Validation', (int) $buyer['id'])) {
    ok('mail to a live client passes through untouched');
} else {
    bad('mail to a live client was wrongly aborted');
}

// -- cleanup ----------------------------------------------------------------
foreach (array_filter([$iapInvoiceId, $otherInvoiceId, $erasedInvoiceId]) as $invoiceId) {
    $r = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
    if (($r['result'] ?? '') === 'success') {
        ok("invoice #$invoiceId cancelled");
    } else {
        bad("could not cancel invoice #$invoiceId: " . json_encode($r));
    }
}
if ($erasedClientId > 0) {
    $r = localAPI('DeleteClient', ['clientid' => $erasedClientId, 'deleteusers' => true]);
    ($r['result'] ?? '') === 'success'
        ? ok("erased-client fixture #$erasedClientId removed")
        : bad("could not remove the erased-client fixture: " . json_encode($r));
}

finish();
