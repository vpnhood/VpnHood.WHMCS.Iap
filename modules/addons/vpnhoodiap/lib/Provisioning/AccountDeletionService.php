<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Permanent account deletion (Apple 5.1.1(v), Play account-deletion policy, GDPR Art. 17).
 *
 * Erases the PERSON, never the service and never the money trail:
 *
 *  - The portal identity dies everywhere at once: sessions, sign-in identities,
 *    the account row. A later sign-in with the same email is a brand-new empty
 *    account — no tombstone, no restore.
 *  - Running services are left alone. A store-billed subscription is only an open
 *    gate with a random token behind it; the store's own lifecycle (or the
 *    customer cancelling there) is what ends it. Deletion must not take away time
 *    the person already paid for.
 *  - Paid invoices are retained (tax law; GDPR Art. 17(3)(b)) and keep the buyer
 *    AS ISSUED, name and address included (lifecycle §5): WHMCS renders an invoice
 *    from the live client row unless it has WHMCS's own client data snapshot, so
 *    every invoice has one BEFORE the client row is anonymized, and the snapshot
 *    then loses what no invoice shows. The live client row still becomes
 *    placeholders (name, unroutable RFC 2606 address), so the person disappears
 *    from the operating system while the financial documents keep the identity
 *    the law requires them to carry.
 *  - WHMCS's own traces of the person go too: its mail log, its activity log, and
 *    the addresses of the last sign-ins and of the orders, which it keeps forever,
 *    with the person's support tickets and the links to their sign-in providers.
 *  - Live WEB billing never blocks deletion (lifecycle §8): a refusal would send
 *    people to the website to finish deleting, the exact pattern the store rules
 *    exist to prevent. It is CANCELLED AT THE END OF
 *    ITS PAID PERIOD instead: no renewal invoice is ever generated, the key
 *    still runs out the time that was bought, and the journal keeps the gateway
 *    agreement reference so a stray charge can always be traced to an agreement
 *    someone can cancel.
 *
 * Ordering is the safety argument: stop future charges → snapshot the invoices →
 * anonymize the WHMCS side, close it and erase its traces → erase the module side →
 * journal. A failure before erasure aborts loudly and the whole action can be
 * re-run; every step is idempotent.
 */
class AccountDeletionService
{
    /**
     * Set while the deletion rewrites the person's records. WHMCS answers a rewritten
     * address with a verification mail, which the suppression hook aborts as addressed
     * to an erased person, and the hook writes no note of it then (isRewritingRecords()):
     * the note would keep the caller's IP, filed under a login the erase cannot always
     * find (one without a link to the client).
     */
    private static bool $rewritingRecords = false;

    /** Is a deletion rewriting the records a suppressed mail is aimed at? */
    public static function isRewritingRecords(): bool
    {
        return self::$rewritingRecords;
    }

    /**
     * Delete the account behind a signed-in module user. A store subscription
     * is deliberately left exactly as it is (lifecycle §8): signing in again
     * brings it back by itself, so cancelling it here would destroy the very
     * asset a return depends on — the person cancels in their own store.
     *
     * @param array $user the mod_vpnhood_iap_users row (as SessionService::resolve returns it)
     */
    public function deleteUser(array $user): void
    {
        $userId = (int) $user['id'];
        $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;
        $details = [];

        if ($clientId !== null) {
            $details += $this->deleteClientSide($clientId);
        }
        $this->eraseModuleRows($userId);
        $this->journal($userId, $clientId, 'deleted', $details);
    }

    /**
     * Delete a WHMCS-client account from the client area (the web deletion path
     * Play requires). Works for app buyers and pure web customers alike; when a
     * module account hangs on the client's email it dies with it.
     */
    public function deleteClient(int $clientId, ?array $moduleUser): void
    {
        $details = $this->deleteClientSide($clientId);
        if ($moduleUser !== null) {
            $this->eraseModuleRows((int) $moduleUser['id']);
        }
        $this->journal($moduleUser !== null ? (int) $moduleUser['id'] : null, $clientId, 'deleted', $details);
    }

    // ------------------------------------------------------------------ steps --

    /**
     * @return array journal details (agreement references etc.)
     * @throws ApiException
     */
    private function deleteClientSide(int $clientId): array
    {
        $details = $this->cancelWebBillingAtPeriodEnd($clientId);
        $this->cancelUnpaidInvoices($clientId);
        $details['payMethodsDropped'] = $this->dropStoredPayMethods($clientId);
        $this->snapshotInvoices($clientId);
        // both are found by the person's addresses, which anonymizing rewrites
        $loginIds = $this->ownedLoginIds($clientId);
        $addresses = $this->addressesToErase($clientId, $loginIds);
        self::$rewritingRecords = true;
        try {
            $this->anonymizeClient($clientId, $loginIds);
            $this->closeClient($clientId);
        } finally {
            self::$rewritingRecords = false;
        }
        $ticketIds = $this->deleteTickets($clientId, $addresses);
        $this->eraseClientTraces($clientId, $loginIds, $addresses, $ticketIds);
        return $details;
    }

    /**
     * Every invoice keeps the buyer as issued (lifecycle §5). WHMCS renders an invoice from
     * the live client row, which anonymizeClient() rewrites, unless the invoice has WHMCS's
     * own client data snapshot, taken when it is created while "Store Client Data Snapshot"
     * is on. An invoice made before that has none, so one is taken here, from the row as it
     * still is; WHMCS never retakes one. An invoice left without a snapshot ABORTS the
     * deletion: anonymizing then would strip the name off a tax record for good.
     *
     * @throws ApiException 502 deletion_failed when an invoice cannot be snapshotted
     */
    private function snapshotInvoices(int $clientId): void
    {
        self::keepInvoiceSnapshotsOn();
        $invoiceIds = array_map('intval', Capsule::table('tblinvoices')->where('userid', $clientId)->pluck('id')->all());
        $snapshotted = array_map('intval', Capsule::table('mod_invoicedata')->whereIn('invoiceid', $invoiceIds ?: [0])
            ->pluck('invoiceid')->all());
        foreach (array_diff($invoiceIds, $snapshotted) as $invoiceId) {
            \WHMCS\Invoice::saveClientSnapshotData($invoiceId);
            if (!Capsule::table('mod_invoicedata')->where('invoiceid', $invoiceId)->exists()) {
                throw new ApiException("Could not snapshot invoice $invoiceId", 502, 'deletion_failed');
            }
        }
    }

    /**
     * WHMCS shows an invoice from its client data snapshot only while "Store Client Data
     * Snapshot" is on: turned off, a deleted person's invoices read "Deleted Account" again.
     * So it is kept on: by the deletion, at activation, and by the daily cron.
     */
    public static function keepInvoiceSnapshotsOn(): void
    {
        if (\WHMCS\Config\Setting::getValue('StoreClientDataSnapshotOnInvoiceCreation') !== 'on') {
            \WHMCS\Config\Setting::setValue('StoreClientDataSnapshotOnInvoiceCreation', 'on');
        }
    }

    /**
     * Every active web-billed service is set to cancel at the END of its paid
     * period (lifecycle §5 step 1): no renewal invoice is ever generated, and
     * the key keeps working for the time already bought. Store-billed services
     * (the module's own) are left to the store lifecycle. The gateway agreement
     * handle is collected for the journal — deletion must never destroy the one
     * thing that can stop a billing agreement. In practice it is empty: a gateway
     * that charges a stored card keeps no subscription object for WHMCS to record,
     * and dropping that card (dropStoredPayMethods) is what stops it.
     *
     * @return array{cancelledAtPeriodEnd: array<int,array{service:int, subscriptionId:string}>}
     * @throws ApiException 502 deletion_failed when a cancellation cannot be recorded
     */
    private function cancelWebBillingAtPeriodEnd(int $clientId): array
    {
        $moduleServiceIds = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('client_id', $clientId)
            ->whereNotNull('service_id')
            ->pluck('service_id')
            ->all();

        $services = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', $clientId)
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->whereNotIn('h.id', $moduleServiceIds ?: [0])
            ->get(['h.id', 'h.subscriptionid', 'p.paytype']);

        $cancelled = [];
        foreach ($services as $service) {
            if ((string) $service->paytype !== 'recurring') {
                continue; // a one-time key has no future billing; it runs out on its own
            }
            $alreadyRequested = Capsule::table('tblcancelrequests')
                ->where('relid', (int) $service->id)->exists();
            if (!$alreadyRequested) {
                $result = localAPI('AddCancelRequest', [
                    'serviceid' => (int) $service->id,
                    'type'      => 'End of Billing Period',
                    'reason'    => 'Account deletion',
                ]);
                if (($result['result'] ?? '') !== 'success') {
                    throw new ApiException(
                        'Could not stop the billing on a web service: ' . ($result['message'] ?? 'unknown error'),
                        502, 'deletion_failed');
                }
            }
            $cancelled[] = [
                'service'        => (int) $service->id,
                'subscriptionId' => (string) $service->subscriptionid,
            ];
        }
        return ['cancelledAtPeriodEnd' => $cancelled];
    }

    /**
     * Even with nothing left to schedule a charge, a stored card token attached
     * to an erased customer must not survive (lifecycle §8 rule 3).
     */
    private function dropStoredPayMethods(int $clientId): int
    {
        $list = localAPI('GetPayMethods', ['clientid' => $clientId]);
        $methods = (array) ($list['paymethods'] ?? []);
        $dropped = 0;
        foreach ($methods as $method) {
            $id = (int) ($method['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result = localAPI('DeletePayMethod', ['paymethodid' => $id, 'clientid' => $clientId]);
            if (($result['result'] ?? '') !== 'success') {
                throw new ApiException(
                    'Could not remove a stored payment method: ' . ($result['message'] ?? 'unknown error'),
                    502, 'deletion_failed');
            }
            $dropped++;
        }
        return $dropped;
    }

    /** Nothing may ever bill a deleted person again: open invoices are cancelled, paid ones retained. */
    private function cancelUnpaidInvoices(int $clientId): void
    {
        $invoiceIds = Capsule::table('tblinvoices')
            ->where('userid', $clientId)
            ->whereIn('status', ['Unpaid', 'Draft'])
            ->pluck('id')
            ->all();

        foreach ($invoiceIds as $invoiceId) {
            $result = localAPI('UpdateInvoice', ['invoiceid' => (int) $invoiceId, 'status' => 'Cancelled']);
            if (($result['result'] ?? '') !== 'success') {
                throw new ApiException('Could not cancel an open invoice: ' . ($result['message'] ?? 'unknown error'),
                    502, 'deletion_failed');
            }
        }
    }

    /**
     * The client record survives as the anonymous anchor of its paid invoices.
     * Values are placeholders, never derivatives of the real data (a hash of the
     * email would be the tombstone the policy explicitly rejects), and the address
     * uses an RFC 2606 reserved TLD so it can never route.
     *
     * @param int[] $loginIds ownedLoginIds(), read before anything changed
     * @throws ApiException
     */
    private function anonymizeClient(int $clientId, array $loginIds): void
    {
        $originalEmail = (string) Capsule::table('tblclients')->where('id', $clientId)->value('email');
        $anonymousEmail = self::clientPlaceholder($clientId);
        if ($originalEmail === '' || strcasecmp($originalEmail, $anonymousEmail) === 0) {
            return; // already anonymized — the whole action is re-runnable
        }

        // The client-area LOGINS (tblusers) carry the person's address too. A login
        // that fails here fails the deletion before the client row changes, so a
        // re-run tries it again rather than reporting an erasure that did not happen.
        foreach ($loginIds as $loginId) {
            $result = localAPI('UpdateUser', [
                'user_id'   => $loginId,
                'firstname' => 'Deleted',
                'lastname'  => 'Account',
                'email'     => self::loginPlaceholder($loginId),
            ]);
            if (($result['result'] ?? '') !== 'success') {
                throw new ApiException('Could not anonymize a sign-in login: ' . ($result['message'] ?? 'unknown error'),
                    502, 'deletion_failed');
            }
        }

        // company, address line 2, tax id, notes and the custom fields are blanked by
        // eraseClientTraces, which every run reaches: UpdateClient may keep a field it is given empty
        $result = localAPI('UpdateClient', [
            'clientid'       => $clientId,
            'firstname'      => 'Deleted',
            'lastname'       => 'Account',
            'email'          => $anonymousEmail,
            'address1'       => '-',
            'city'           => '-',
            'state'          => '-',
            'postcode'       => '-',
            'phonenumber'    => '-',
            'skipvalidation' => true,
        ]);
        if (($result['result'] ?? '') !== 'success') {
            throw new ApiException('Could not anonymize the customer record: ' . ($result['message'] ?? 'unknown error'),
                502, 'deletion_failed');
        }
    }

    /**
     * Closing a WHMCS client TERMINATES its products — and a deleted person's
     * paid-for keys keep running (lifecycle §8: we take back what the account
     * lent, never what the person bought). A client with running services is
     * marked Inactive instead: the person is equally gone either way (the logins
     * are anonymized); the difference is their keys survive. Runs on every run,
     * so a re-run finishes a close that failed after the record was anonymized.
     *
     * @throws ApiException
     */
    private function closeClient(int $clientId): void
    {
        $status = (string) Capsule::table('tblclients')->where('id', $clientId)->value('status');
        if (in_array($status, ['', 'Closed', 'Inactive'], true)) {
            return; // no record left, or already closed
        }

        $hasRunningServices = Capsule::table('tblhosting')
            ->where('userid', $clientId)
            ->whereIn('domainstatus', ['Active', 'Suspended'])
            ->exists();
        $result = $hasRunningServices
            ? localAPI('UpdateClient', ['clientid' => $clientId, 'status' => 'Inactive', 'skipvalidation' => true])
            : localAPI('CloseClient', ['clientid' => $clientId]);
        if (($result['result'] ?? '') !== 'success') {
            throw new ApiException('Could not close the customer record: ' . ($result['message'] ?? 'unknown error'),
                502, 'deletion_failed');
        }
    }

    /**
     * The client-area logins that hold nothing but this client: the ones linked to
     * it, and the one carrying its address should the link be missing. A login
     * that also holds other, independent clients is kept — those relationships
     * are not ours to destroy.
     *
     * @return int[]
     */
    private function ownedLoginIds(int $clientId): array
    {
        $email = (string) Capsule::table('tblclients')->where('id', $clientId)->value('email');
        $loginIds = array_unique(array_map('intval', array_merge(
            Capsule::table('tblusers_clients')->where('client_id', $clientId)->pluck('auth_user_id')->all(),
            $email === '' ? [] : Capsule::table('tblusers')->where('email', $email)->pluck('id')->all())));
        return array_values(array_filter($loginIds, fn (int $loginId) => Capsule::table('tblusers_clients')
            ->where('auth_user_id', $loginId)->where('client_id', '!=', $clientId)->doesntExist()));
    }

    /**
     * The addresses the activity log may quote for this person: the real ones, read
     * before anonymizing rewrites them, and the placeholders they become, which a
     * re-run still knows once the real ones are gone.
     *
     * @param int[] $loginIds
     * @return string[]
     */
    private function addressesToErase(int $clientId, array $loginIds): array
    {
        $addresses = array_merge(
            [(string) Capsule::table('tblclients')->where('id', $clientId)->value('email'), self::clientPlaceholder($clientId)],
            $loginIds === [] ? [] : Capsule::table('tblusers')->whereIn('id', $loginIds)->pluck('email')->all(),
            array_map(fn (int $loginId) => self::loginPlaceholder($loginId), $loginIds));
        return array_values(array_unique(array_filter(array_map('strval', $addresses), fn (string $address) => $address !== '')));
    }

    private static function clientPlaceholder(int $clientId): string
    {
        return "deleted-$clientId@anonymized.invalid";
    }

    private static function loginPlaceholder(int $loginId): string
    {
        return "deleted-user-$loginId@anonymized.invalid";
    }

    /**
     * The person's support tickets, replies included: the ones under the client, and the
     * ones sent from the person's addresses without signing in.
     *
     * @param string[] $addresses addressesToErase()
     * @return int[] the deleted tickets
     * @throws ApiException 502 deletion_failed when a ticket cannot be deleted
     */
    private function deleteTickets(int $clientId, array $addresses): array
    {
        $ticketIds = array_map('intval', Capsule::table('tbltickets')->where('userid', $clientId)
            ->orWhereIn('email', $addresses)->pluck('id')->all());
        foreach ($ticketIds as $ticketId) {
            $result = localAPI('DeleteTicket', ['ticketid' => $ticketId]);
            if (($result['result'] ?? '') !== 'success') {
                throw new ApiException('Could not delete a support ticket: ' . ($result['message'] ?? 'unknown error'),
                    502, 'deletion_failed');
            }
        }
        return $ticketIds;
    }

    /**
     * WHMCS's own traces of the person, which no localAPI command removes: the mail
     * log (the address and the text of every message sent, order mail with its codes
     * included), the activity log, with the notes WHMCS files of the person under nobody,
     * the addresses of the last sign-ins and of the orders, the links to the person's
     * sign-in providers, and in the invoice snapshots what no invoice shows. Raw writes on
     * WHMCS core tables,
     * then: the policy promises the address is erased, and WHMCS keeps all of it forever.
     * The client fields UpdateClient may keep when given them empty are blanked here too,
     * custom fields included. Runs after the client row is closed, as WHMCS logs that
     * too, and on every re-run.
     *
     * @param int[] $loginIds ownedLoginIds(), read before anonymizing
     * @param string[] $addresses addressesToErase()
     * @param int[] $ticketIds deleteTickets()
     * @throws ApiException 502 deletion_failed when a trace cannot be erased
     */
    private function eraseClientTraces(int $clientId, array $loginIds, array $addresses, array $ticketIds): void
    {
        try {
            Capsule::table('tblemails')->where('userid', $clientId)->delete();
            Capsule::table('tblactivitylog')->where('userid', $clientId)->delete();
            // WHMCS quotes an address it changes, on a row that may name neither the client nor
            // the login; a row quoting a longer address (joann@ for ann@) is someone else's
            $quoting = Capsule::table('tblactivitylog')->where(function ($query) use ($addresses) {
                foreach ($addresses as $address) {
                    $query->orWhereRaw('INSTR(description, ?) > 0', [$address]);
                }
            })->get(['id', 'description']);
            $quotingIds = $quoting->filter(fn ($row) => self::quotesAnyOf((string) $row->description, $addresses))
                ->pluck('id')->all();
            if ($quotingIds !== []) {
                Capsule::table('tblactivitylog')->whereIn('id', $quotingIds)->delete();
            }
            Capsule::table('tblclients')->where('id', $clientId)->update([
                'companyname' => '', 'address2' => '', 'tax_id' => '', 'notes' => '', 'ip' => '', 'host' => '',
            ]);
            Capsule::table('tblcustomfieldsvalues')->where('relid', $clientId)
                ->whereIn('fieldid', Capsule::table('tblcustomfields')->where('type', 'client')->pluck('id')->all() ?: [0])
                ->update(['value' => '']);
            $this->eraseFromInvoiceSnapshots($clientId);
            Capsule::table('tblorders')->where('userid', $clientId)->update(['ipaddress' => '']);
            // a link left behind would still sign the person's provider account in to the anonymized login
            Capsule::table('tblauthn_account_links')->where('client_id', $clientId)
                ->orWhereIn('user_id', $loginIds ?: [0])->delete();
            if ($loginIds !== []) {
                Capsule::table('tblactivitylog')->whereIn('user_id', $loginIds)->delete();
                Capsule::table('tblusers')->whereIn('id', $loginIds)->update(['last_ip' => '', 'last_hostname' => '']);
            }
            $this->eraseUnfiledNotes($loginIds, $ticketIds);
        } catch (\Throwable $e) {
            throw new ApiException('Could not erase the customer\'s traces: ' . $e->getMessage(), 502, 'deletion_failed');
        }
    }

    /**
     * The client details in WHMCS's invoice snapshots that no invoice shows. The rest
     * (name, company, address, tax id, country, currency) is the invoice as issued.
     */
    private const SNAPSHOT_DETAILS_TO_ERASE = ['email', 'phonenumber', 'phonenumberformatted', 'telephoneNumber',
        'notes', 'lastlogin', 'cctype', 'cclastfour', 'gatewayid', 'billingcid'];

    /**
     * The client's invoice snapshots keep the buyer as issued and lose the rest: the
     * details above, and the client's custom fields (those an invoice shows are kept apart
     * by WHMCS, in the snapshot's own custom fields, and stay).
     */
    private function eraseFromInvoiceSnapshots(int $clientId): void
    {
        $invoiceIds = Capsule::table('tblinvoices')->where('userid', $clientId)->pluck('id')->all();
        $snapshots = Capsule::table('mod_invoicedata')->whereIn('invoiceid', $invoiceIds ?: [0])->get(['id', 'clientsdetails']);
        foreach ($snapshots as $snapshot) {
            $details = json_decode((string) $snapshot->clientsdetails, true);
            if (!is_array($details)) {
                throw new \RuntimeException("invoice snapshot #{$snapshot->id} is not the JSON WHMCS writes");
            }
            foreach ($details as $key => $value) {
                if (in_array($key, self::SNAPSHOT_DETAILS_TO_ERASE, true) || str_starts_with((string) $key, 'customfields')) {
                    $details[$key] = is_array($value) ? [] : '';
                }
            }
            Capsule::table('mod_invoicedata')->where('id', (int) $snapshot->id)->update(['clientsdetails' => json_encode($details)]);
        }
    }

    /**
     * Does the text quote one of these addresses whole, not as the end or the start of a
     * longer one? A dot after it ends a sentence; a dot and a letter go on with a domain.
     *
     * @param string[] $addresses
     */
    private static function quotesAnyOf(string $text, array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (preg_match('/(?<![\w.%+-])' . preg_quote($address, '/') . '(?![\w%+-]|\.\w)/iu', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * WHMCS files a few notes of a login or a ticket under nobody, with the IP of the
     * request that wrote them: "Email Verification Message Sending Aborted by Hook -
     * UserID: <login>", with the "Email Sending Aborted by Hook" line the same request
     * wrote just before it (at sign-up and at a deletion, when the hook suppresses that
     * mail), and "Deleted Ticket - Ticket ID: <ticket>".
     *
     * @param int[] $loginIds
     * @param int[] $ticketIds
     */
    private function eraseUnfiledNotes(array $loginIds, array $ticketIds): void
    {
        $unfiled = fn () => Capsule::table('tblactivitylog')->where('userid', 0)->where('user_id', 0);
        foreach ($loginIds as $loginId) {
            $notes = $unfiled()->where('description', 'like', "%Aborted by Hook - UserID: $loginId")->get(['id', 'date', 'ipaddr']);
            foreach ($notes as $note) {
                $pairedId = (int) Capsule::table('tblactivitylog')->where('id', '<', (int) $note->id)->max('id');
                $unfiled()->where('id', $pairedId)->where('description', 'like', 'Email Sending Aborted by Hook%')
                    ->where('date', $note->date)->where('ipaddr', $note->ipaddr)->delete();
                Capsule::table('tblactivitylog')->where('id', (int) $note->id)->delete();
            }
        }
        foreach ($ticketIds as $ticketId) {
            $unfiled()->where('description', "Deleted Ticket - Ticket ID: $ticketId")->delete();
        }
    }

    /**
     * The module side: sessions die on every device at once, identities and the
     * account row go entirely, and purchases keep only their numeric ledger ids —
     * the entitlement (an open gate, no personal data) keeps working until the
     * store's own lifecycle ends it.
     */
    private function eraseModuleRows(int $userId): void
    {
        Capsule::table('mod_vpnhood_iap_sessions')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_identities')->where('user_id', $userId)->delete();
        // the device-held restore keys must die with the account: any one of them
        // could silently mint a session for a person who asked to be gone
        Capsule::table('mod_vpnhood_iap_restore_credentials')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_restore_challenges')->where('user_id', $userId)->delete();
        // a fingerprint of somebody's credential, keyed by the id of a person who no longer exists
        Capsule::table('mod_vpnhood_iap_code_rejections')->where('user_id', $userId)->delete();
        // the old store's hand-over matched on the address; a row this account claimed needs it no more
        if (Capsule::schema()->hasTable('mod_vpnhood_iap_legacy_subs')) {
            Capsule::table('mod_vpnhood_iap_legacy_subs')->where('claimed_user_id', $userId)->update(['email' => '']);
        }
        // The purchase ledger keeps user_id as a DEAD pointer on purpose: the person it
        // named no longer exists anywhere (identities, emails and uids die above), and the
        // journal below retains the same numeric id anyway — so this discloses nothing new.
        // What it buys: Restore Purchases after deletion can prove a purchase's owner
        // was journalled-deleted (EntitlementService::relinkErasedOwner) and hand the row
        // to the person's new account, instead of dead-ending on the binding guard.
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->delete();
    }

    /**
     * Numeric ids and contract references only — no PII, so the journal itself
     * never becomes a tombstone. It exists to make the anonymization re-runnable
     * after a backup restore, and (details) to keep the gateway agreement handles
     * that let an administrator stop a stray charge after the person is gone.
     */
    private function journal(?int $userId, ?int $clientId, string $outcome, array $details = []): void
    {
        Capsule::table('mod_vpnhood_iap_deletions')->insert([
            'user_id'    => $userId,
            'client_id'  => $clientId,
            'outcome'    => $outcome,
            'details'    => $details === [] ? null : json_encode($details),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
