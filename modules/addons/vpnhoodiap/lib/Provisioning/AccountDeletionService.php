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
 *  - Paid invoices are retained (tax law; GDPR Art. 17(3)(b)) and FROZEN AS
 *    ISSUED, buyer's name included (lifecycle §5, decided 2026-08-13 — reversing
 *    the earlier anonymise-the-invoices choice): WHMCS renders invoices from the
 *    live client row, so each invoice is archived into
 *    mod_vpnhood_iap_frozen_invoices BEFORE the client row is anonymized. The
 *    frozen artifacts are restricted by design — nothing in the module reads
 *    them back; they exist for an auditor, not for support or search. The live
 *    client row still becomes placeholders (name, unroutable RFC 2606 address),
 *    so the person disappears from the operating system while the financial
 *    documents keep the identity the law requires them to carry.
 *  - Live WEB billing never blocks deletion (lifecycle §8, decided 2026-08-13 —
 *    the old refusal sent people to the website to finish deleting, the exact
 *    pattern the store rules exist to prevent). It is CANCELLED AT THE END OF
 *    ITS PAID PERIOD instead: no renewal invoice is ever generated, the key
 *    still runs out the time that was bought, and the journal keeps the gateway
 *    agreement reference so a stray charge can always be traced to an agreement
 *    someone can cancel.
 *
 * Ordering is the safety argument: stop future charges → say goodbye while the
 * address still exists → anonymize the WHMCS side → erase the module side →
 * journal. A failure before erasure aborts loudly and the whole action can be
 * re-run; every step is idempotent.
 */
class AccountDeletionService
{
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
        $details += $this->freezeInvoices($clientId);
        $this->anonymizeClient($clientId);
        return $details;
    }

    /**
     * Archive every invoice exactly as issued — buyer identity included — before
     * anonymizeClient() rewrites the client row those invoices render from
     * (lifecycle §5 step 6). One artifact per invoice, written once and never
     * updated: on a re-run after a partial failure the client row may already be
     * placeholders, and overwriting would destroy the only true snapshot. A
     * failure here ABORTS the deletion (fail-loud): anonymizing without the
     * snapshot would strip names off tax records irrecoverably.
     *
     * @return array{frozenInvoices: array<int,array{id:int, sha256:string}>}
     * @throws ApiException 502 deletion_failed when an artifact cannot be written
     */
    private function freezeInvoices(int $clientId): array
    {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if ($client === null) {
            return ['frozenInvoices' => []];
        }
        $clientBlock = [
            'firstName'   => (string) $client->firstname,
            'lastName'    => (string) $client->lastname,
            'companyName' => (string) $client->companyname,
            'address1'    => (string) $client->address1,
            'address2'    => (string) $client->address2,
            'city'        => (string) $client->city,
            'state'       => (string) $client->state,
            'postcode'    => (string) $client->postcode,
            'country'     => (string) $client->country,
            'taxId'       => (string) ($client->tax_id ?? ''),
            'email'       => (string) $client->email,
        ];

        $refs = [];
        $invoices = Capsule::table('tblinvoices')->where('userid', $clientId)->orderBy('id')->get();
        foreach ($invoices as $invoice) {
            $invoiceId = (int) $invoice->id;
            $existing = Capsule::table('mod_vpnhood_iap_frozen_invoices')
                ->where('invoice_id', $invoiceId)->first(['id', 'sha256']);
            if ($existing !== null) {
                $refs[] = ['id' => $invoiceId, 'sha256' => (string) $existing->sha256];
                continue; // written once — never overwrite a true snapshot with placeholder data
            }

            $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')
                ->get(['id', 'type', 'relid', 'description', 'amount', 'taxed'])
                ->map(fn ($row) => (array) $row)->all();
            $transactions = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->orderBy('id')
                ->get(['id', 'gateway', 'transid', 'date', 'amountin', 'amountout', 'fees'])
                ->map(fn ($row) => (array) $row)->all();

            $artifact = json_encode([
                'schema'       => 'vpnhoodiap.frozen-invoice.v1',
                'frozenAt'     => date('c'),
                'client'       => $clientBlock,
                'invoice'      => (array) $invoice,
                'items'        => $items,
                'transactions' => $transactions,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($artifact === false) {
                throw new ApiException("Could not render the frozen artifact for invoice {$invoiceId}",
                    502, 'deletion_failed');
            }

            try {
                Capsule::table('mod_vpnhood_iap_frozen_invoices')->insert([
                    'invoice_id' => $invoiceId,
                    'client_id'  => $clientId,
                    'artifact'   => $artifact,
                    'sha256'     => hash('sha256', $artifact),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                throw new ApiException("Could not freeze invoice {$invoiceId}: " . $e->getMessage(),
                    502, 'deletion_failed');
            }
            $refs[] = ['id' => $invoiceId, 'sha256' => hash('sha256', $artifact)];
        }
        return ['frozenInvoices' => $refs];
    }

    /**
     * Every active web-billed service is set to cancel at the END of its paid
     * period (lifecycle §5 step 1): no renewal invoice is ever generated, and
     * the key keeps working for the time already bought. Store-billed services
     * (the module's own) are left to the store lifecycle. The gateway agreement
     * handle is collected for the journal — deletion must never destroy the one
     * thing that can stop a billing agreement.
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
     * @throws ApiException
     */
    private function anonymizeClient(int $clientId): void
    {
        $originalEmail = (string) Capsule::table('tblclients')->where('id', $clientId)->value('email');
        $anonymousEmail = "deleted-$clientId@anonymized.invalid";
        if ($originalEmail === '' || strcasecmp($originalEmail, $anonymousEmail) === 0) {
            return; // already anonymized — the whole action is re-runnable
        }

        // The client-area LOGIN (tblusers) carries the same address. Anonymize it
        // only when this client is all that login owns — a person with other,
        // independent clients keeps their login (those relationships are not ours
        // to destroy). If ownership cannot be read, keep the login and leave a
        // trail rather than lock a shared identity out.
        try {
            $whmcsUser = \WHMCS\User\User::where('email', $originalEmail)->first();
            if ($whmcsUser !== null) {
                $ownedClients = (int) Capsule::table('tblusers_clients')
                    ->where('auth_user_id', (int) $whmcsUser->id)->count();
                if ($ownedClients <= 1) {
                    $result = localAPI('UpdateUser', [
                        'user_id'   => (int) $whmcsUser->id,
                        'firstname' => 'Deleted',
                        'lastname'  => 'Account',
                        'email'     => $anonymousEmail,
                    ]);
                    if (($result['result'] ?? '') !== 'success') {
                        throw new \RuntimeException('UpdateUser: ' . ($result['message'] ?? 'unknown error'));
                    }
                }
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            logModuleCall('vpnhoodiap', 'deletion.userAnonymize', (string) $clientId, $e->getMessage(), '');
        }

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

        // Closing a WHMCS client TERMINATES its products — and a deleted person's
        // paid-for keys keep running (lifecycle §8: we take back what the account
        // lent, never what the person bought). A client with running services is
        // marked Inactive instead: the person is equally gone either way (login
        // anonymized above); the difference is their keys survive.
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
     * The module side: sessions die on every device at once, identities and the
     * account row go entirely, and purchases keep only their numeric ledger ids —
     * the entitlement (an open gate, no personal data) keeps working until the
     * store's own lifecycle ends it.
     */
    private function eraseModuleRows(int $userId): void
    {
        Capsule::table('mod_vpnhood_iap_sessions')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_identities')->where('user_id', $userId)->delete();
        // a fingerprint of somebody's credential, keyed by the id of a person who no longer exists
        Capsule::table('mod_vpnhood_iap_code_rejections')->where('user_id', $userId)->delete();
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
