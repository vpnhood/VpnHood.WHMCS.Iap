<?php

/**
 * VpnHood! IAP — WHMCS mail suppression. Two rules, one hook point.
 *
 * 1. APP-STORE CUSTOMERS. Customers who bought through an app store pay the STORE, not
 * WHMCS. WHMCS's own mail about those orders — invoice lifecycle ("Invoice Created",
 * reminders, overdue notices, payment confirmations) and the product welcome email —
 * would tell them to pay again, confirm a payment they made elsewhere, or hand them
 * panel details they never asked for. This hook aborts that mail for records belonging
 * to the vpnhoodiappay bookkeeping gateway, and only those; every other invoice and
 * service on the install mails exactly as before.
 *
 * 2. DELETED PEOPLE. Account deletion keeps the client row as the anonymous anchor of
 * the invoices tax law makes us retain, and rewrites its address to
 * deleted-<id>@anonymized.invalid (AccountDeletionService::anonymizeClient — an RFC 2606
 * reserved TLD, so it can never route). The record therefore survives on the install and
 * WHMCS automation keeps addressing it: every such send is a guaranteed bounce back into
 * the system mailbox, carrying the affairs of a person who asked to be gone. Any mail
 * whose recipient carries that address is aborted, and the template name goes to the
 * activity log — the log line is what names the automation that is still writing to
 * erased people, since no bounce ever says which template it came from.
 *
 * 3. ADDRESSES THE IDENTITY PROVIDER ALREADY PROVED. `AddClient` fires WHMCS's
 * "Email Address Verification" synchronously, and `noemail` does not cover it, so every
 * store buyer the module creates a client for was asked to confirm an address Google or
 * Apple had already proved (sign-in refuses an unverified one) and that the module marks
 * verified a moment later. That single mail is aborted while ClientProvisioner is inside
 * its AddClient call. Verification mail asked for deliberately — the portal gate on a
 * purchase that attached to a PRE-EXISTING client, or the gate page's resend — is never
 * touched: nothing is armed then.
 *
 * The template's own TYPE decides which record `relid` points at, so both rules cover
 * every current and future template without a name list to maintain (the product welcome
 * template is per-product: "Premium Code" uses "Other Product/Service Welcome Email",
 * another product may use a different one).
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
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\ClientProvisioner;

require_once __DIR__ . '/../../modules/addons/vpnhoodiap/lib/Provisioning/ClientProvisioner.php';

/**
 * The address a template's `relid` resolves to, '' when the type names no recipient we
 * can follow (an unknown type, a guest ticket, a record that is already gone). Which
 * table `relid` points at is a property of the template TYPE — that is what lets this
 * file cover templates it has never heard of.
 */
function vpnhoodiap_mailRecipientAddress(string $templateType, int $relatedId): string
{
    if ($relatedId <= 0) {
        return '';
    }

    // Account-level mail (email verification, password reset) is keyed on the LOGIN,
    // not the client — and the login is exactly what WHMCS re-verifies when deletion
    // rewrites its address, which is how these end up aimed at an erased person.
    // Deletion anonymizes a login only when this client is all it owns, so a shared
    // login keeps its real address here and its mail keeps flowing.
    if ($templateType === 'user') {
        return (string) Capsule::table('tblusers')->where('id', $relatedId)->value('email');
    }

    $clientId = match ($templateType) {
        'general'   => $relatedId, // relid IS the client id
        'invoice'   => (int) Capsule::table('tblinvoices')->where('id', $relatedId)->value('userid'),
        'product'   => (int) Capsule::table('tblhosting')->where('id', $relatedId)->value('userid'),
        'domain'    => (int) Capsule::table('tbldomains')->where('id', $relatedId)->value('userid'),
        'support'   => (int) Capsule::table('tbltickets')->where('id', $relatedId)->value('userid'),
        'affiliate' => (int) Capsule::table('tblaffiliates')->where('id', $relatedId)->value('clientid'),
        default     => 0,
    };
    return $clientId > 0
        ? (string) Capsule::table('tblclients')->where('id', $clientId)->value('email')
        : '';
}

/** Deletion's placeholder address: unroutable by construction, so mail to it can only bounce. */
function vpnhoodiap_isErasedAddress(string $email): bool
{
    return str_ends_with(strtolower($email), '@anonymized.invalid');
}

/**
 * Is this mail addressed to somebody who has been deleted? The merge fields carry the
 * recipient's own address and are checked first — they are free and they also cover a
 * template whose `relid` maps to nothing we can resolve. The row behind `relid` is the
 * authoritative second opinion.
 */
function vpnhoodiap_mailGoesToErasedPerson(string $templateType, int $relatedId, array $mergeFields): bool
{
    foreach (['client_email', 'email'] as $field) {
        if (vpnhoodiap_isErasedAddress((string) ($mergeFields[$field] ?? ''))) {
            return true;
        }
    }

    return vpnhoodiap_isErasedAddress(vpnhoodiap_mailRecipientAddress($templateType, $relatedId));
}

/** Does this mail belong to a purchase the store, not WHMCS, was paid for? */
function vpnhoodiap_mailBelongsToStorePurchase(string $templateType, int $relatedId): bool
{
    if ($relatedId <= 0) {
        return false;
    }
    // which table `relid` refers to is a property of the template type
    $paymentMethod = match ($templateType) {
        'invoice' => (string) Capsule::table('tblinvoices')->where('id', $relatedId)->value('paymentmethod'),
        'product' => (string) Capsule::table('tblhosting')->where('id', $relatedId)->value('paymentmethod'),
        default   => '',
    };
    return $paymentMethod === 'vpnhoodiappay';
}

add_hook('EmailPreSend', 1, function (array $vars) {
    $messageName = (string) ($vars['messagename'] ?? '');
    if ($messageName === '') {
        return [];
    }
    $relatedId = (int) ($vars['relid'] ?? 0);

    try {
        $templateType = (string) Capsule::table('tblemailtemplates')
            ->where('name', $messageName)
            ->value('type');

        // staff mail: an erased client can only ever be its subject, never its reader
        if ($templateType === 'admin') {
            return [];
        }

        $recipient = vpnhoodiap_mailRecipientAddress($templateType, $relatedId);
        $erased = vpnhoodiap_mailGoesToErasedPerson($templateType, $relatedId, (array) ($vars['mergefields'] ?? []));
        $redundantVerification = !$erased && $recipient !== ''
            && ClientProvisioner::isMailboxProvenByIdp($recipient);
        $storeMail = !$erased && !$redundantVerification
            && vpnhoodiap_mailBelongsToStorePurchase($templateType, $relatedId);
    } catch (\Throwable $e) {
        // never let a lookup failure take the mail pipeline down; default to sending
        return [];
    }

    if ($erased) {
        try {
            // deletions are rare, so one line per suppressed mail is a trace, not noise —
            // and it is the only place the offending template ever gets named
            logActivity("vpnhoodiap: suppressed '$messageName' — its recipient is a deleted (anonymized) client");
        } catch (\Throwable $e) {
            // the note must never decide whether the mail is suppressed
        }
    }

    return $erased || $redundantVerification || $storeMail ? ['abortsend' => true] : [];
});
