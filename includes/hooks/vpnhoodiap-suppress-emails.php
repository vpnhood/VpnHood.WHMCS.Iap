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

/**
 * The client a template's `relid` names. The mapping is a property of the template
 * TYPE — that is what lets this file cover templates it has never heard of. 0 when the
 * type carries no client at all (an unknown type, a guest ticket, a deleted record).
 */
function vpnhoodiap_mailRecipientClient(string $templateType, int $relatedId): int
{
    if ($relatedId <= 0) {
        return 0;
    }
    return match ($templateType) {
        'general'   => $relatedId, // relid IS the client id
        'invoice'   => (int) Capsule::table('tblinvoices')->where('id', $relatedId)->value('userid'),
        'product'   => (int) Capsule::table('tblhosting')->where('id', $relatedId)->value('userid'),
        'domain'    => (int) Capsule::table('tbldomains')->where('id', $relatedId)->value('userid'),
        'support'   => (int) Capsule::table('tbltickets')->where('id', $relatedId)->value('userid'),
        'affiliate' => (int) Capsule::table('tblaffiliates')->where('id', $relatedId)->value('clientid'),
        default     => 0,
    };
}

/** Deletion's placeholder address: unroutable by construction, so mail to it can only bounce. */
function vpnhoodiap_isErasedAddress(string $email): bool
{
    return str_ends_with(strtolower($email), '@anonymized.invalid');
}

/**
 * Is this mail addressed to somebody who has been deleted? The merge fields carry the
 * recipient's own address and are checked first — they are free and they also cover a
 * template whose `relid` maps to nothing we can resolve. The client row behind `relid`
 * is the authoritative second opinion.
 */
function vpnhoodiap_mailGoesToErasedPerson(string $templateType, int $relatedId, array $mergeFields): bool
{
    foreach (['client_email', 'email'] as $field) {
        if (vpnhoodiap_isErasedAddress((string) ($mergeFields[$field] ?? ''))) {
            return true;
        }
    }

    $clientId = vpnhoodiap_mailRecipientClient($templateType, $relatedId);
    return $clientId > 0
        && vpnhoodiap_isErasedAddress((string) Capsule::table('tblclients')->where('id', $clientId)->value('email'));
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

        $erased = vpnhoodiap_mailGoesToErasedPerson($templateType, $relatedId, (array) ($vars['mergefields'] ?? []));
        $storeMail = !$erased && vpnhoodiap_mailBelongsToStorePurchase($templateType, $relatedId);
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

    return $erased || $storeMail ? ['abortsend' => true] : [];
});
