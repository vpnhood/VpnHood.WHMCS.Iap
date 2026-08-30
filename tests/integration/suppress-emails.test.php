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

requireIapLib('ApiException.php', 'Provisioning/AccountService.php', 'Provisioning/ClientProvisioner.php');

use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\ClientProvisioner;

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
//    unroutable deleted-<id>@anonymized.invalid address and rewrites the login that
//    owns it the same way, so every send WHMCS still aims at either row is a
//    guaranteed bounce. Account-level mail (verification, password reset) is keyed on
//    the LOGIN, which is why both rows are exercised here.
$erasedEmail = 'suppress-erased-' . bin2hex(random_bytes(4)) . '@vpnhood.com';
$erasedClient = localAPI('AddClient', [
    'firstname'      => 'Suppress',
    'lastname'       => 'Erased',
    'email'          => $erasedEmail,
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
    $erasedUserId = (int) \WHMCS\Database\Capsule::table('tblusers')->where('email', $erasedEmail)->value('id');
    $anonymizedEmail = "deleted-$erasedClientId@anonymized.invalid";

    // exactly what AccountDeletionService::anonymizeClient writes, on both rows
    $anonymized = localAPI('UpdateClient', [
        'clientid'       => $erasedClientId,
        'email'          => $anonymizedEmail,
        'skipvalidation' => true,
    ]);
    $anonymizedLogin = $erasedUserId > 0 ? localAPI('UpdateUser', [
        'user_id'   => $erasedUserId,
        'firstname' => 'Deleted',
        'lastname'  => 'Account',
        'email'     => $anonymizedEmail,
    ]) : ['result' => 'no login'];

    if (($anonymized['result'] ?? '') !== 'success' || ($anonymizedLogin['result'] ?? '') !== 'success') {
        bad('could not anonymize the fixture: ' . json_encode([$anonymized, $anonymizedLogin]));
    } else {
        ok("erased fixture anonymized (client #$erasedClientId, login #$erasedUserId)");

        // account-level mail: type 'user', relid is the LOGIN id. This is the family the
        // live bounce came from — WHMCS re-verifies an address the moment it changes.
        if (emailPreSendAborts('Email Address Verification', $erasedUserId)) {
            ok('account-level mail is aborted for the erased login');
        } else {
            bad('account-level mail was NOT aborted for the erased login');
        }

        // client-level mail: type 'general', relid IS the client id
        $generalTemplate = (string) \WHMCS\Database\Capsule::table('tblemailtemplates')
            ->where('type', 'general')->orderBy('id')->value('name');
        if ($generalTemplate === '') {
            bad('fixture missing: no general template on this install');
        } elseif (emailPreSendAborts($generalTemplate, $erasedClientId)) {
            ok("client-addressed mail ('$generalTemplate') is aborted for the erased client");
        } else {
            bad("client-addressed mail ('$generalTemplate') was NOT aborted for the erased client");
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
            'messagename' => 'Email Address Verification',
            'relid'       => 0,
            'mergefields' => ['client_email' => $anonymizedEmail],
        ]);
        $mergeAborted = false;
        foreach ((array) $mergeOnly as $result) {
            $mergeAborted = $mergeAborted || (is_array($result) && !empty($result['abortsend']));
        }
        $mergeAborted
            ? ok('an erased address in the merge fields aborts the send on its own')
            : bad('merge-field-only erased address did not abort the send');

        // negative: the same templates for a live person are untouched
        $buyerUserId = (int) \WHMCS\Database\Capsule::table('tblusers')->where('email', BUYER_EMAIL)->value('id');
        if ($buyerUserId > 0 && !emailPreSendAborts('Email Address Verification', $buyerUserId)) {
            ok('account-level mail to a live login passes through untouched');
        } elseif ($buyerUserId <= 0) {
            bad('fixture missing: no login for ' . BUYER_EMAIL);
        } else {
            bad('account-level mail to a live login was wrongly aborted');
        }
        if ($generalTemplate !== '' && !emailPreSendAborts($generalTemplate, (int) $buyer['id'])) {
            ok('client-addressed mail to a live client passes through untouched');
        } elseif ($generalTemplate !== '') {
            bad('client-addressed mail to a live client was wrongly aborted');
        }
    }
}

// -- a client the module creates: WHMCS fires "Email Address Verification" from inside
//    AddClient (noemail does not cover it), and the identity provider has already proved
//    that mailbox. The probe hook below records whether the suppression was armed at the
//    moment the mail fired — and aborts everything, so this test never sends mail.
$probeEmail = 'suppress-idp-' . bin2hex(random_bytes(4)) . '@vpnhood.com';
$armedWhenVerificationFired = null;
add_hook('EmailPreSend', 99, function (array $vars) use (&$armedWhenVerificationFired, $probeEmail) {
    if (($vars['messagename'] ?? '') === 'Email Address Verification') {
        $armedWhenVerificationFired = ClientProvisioner::isMailboxProvenByIdp($probeEmail);
    }
    return ['abortsend' => true];
});

$probeClientId = 0;
try {
    $probeClientId = (new ClientProvisioner())->createClient($probeEmail, 'Probe Client');
    ok("module-created client #$probeClientId");
} catch (\Throwable $e) {
    bad('createClient threw: ' . $e->getMessage());
}

if ($probeClientId > 0) {
    if ($armedWhenVerificationFired === true) {
        ok('the redundant verification mail was suppressed as it fired');
    } elseif ($armedWhenVerificationFired === false) {
        bad('verification mail fired while the suppression was NOT armed');
    } else {
        ok('WHMCS sent no verification mail at all for the module-created client');
    }

    (new AccountService())->isEmailVerified($probeEmail)
        ? ok('the address is marked verified — the IdP proof carries over to WHMCS')
        : bad('the module-created address was left unverified');

    !ClientProvisioner::isMailboxProvenByIdp($probeEmail)
        ? ok('the suppression is disarmed once AddClient returns')
        : bad('the suppression stayed armed after createClient');
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
if ($probeClientId > 0) {
    $r = localAPI('DeleteClient', ['clientid' => $probeClientId, 'deleteusers' => true]);
    ($r['result'] ?? '') === 'success'
        ? ok("probe client #$probeClientId removed")
        : bad("could not remove the probe client: " . json_encode($r));
}
if ($erasedClientId > 0) {
    $r = localAPI('DeleteClient', ['clientid' => $erasedClientId, 'deleteusers' => true]);
    ($r['result'] ?? '') === 'success'
        ? ok("erased-client fixture #$erasedClientId removed")
        : bad("could not remove the erased-client fixture: " . json_encode($r));
}

finish();
