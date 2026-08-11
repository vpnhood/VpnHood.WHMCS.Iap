<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * "Forget me" (Apple 5.1.1(v), Play account-deletion policy, GDPR Art. 17).
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
 *  - Paid invoices are retained (tax law; GDPR Art. 17(3)(b)) — but they belong to
 *    an anonymized client: placeholder name, an unroutable placeholder address on
 *    a reserved TLD. Amounts, dates and tax figures stay untouched (owner decision
 *    2026-08-10: no PDF archiving; the stores/payment gateway retain the payer
 *    identity under their own legal duty if an audit ever needs it).
 *  - Live WEB billing blocks deletion instead of being "handled": this module
 *    never calls a payment gateway's cancel function (support is uneven per
 *    gateway, and one miss would keep charging a card behind an erased account).
 *    The customer cancels in the client area first, then deletes. App-created
 *    accounts have no web billing, so a store reviewer can never hit this.
 *
 * Ordering is the safety argument: refuse → stop future charges → anonymize the
 * WHMCS side → erase the module side → journal. A failure aborts loudly and the
 * whole action can be re-run; every step is idempotent.
 */
class AccountDeletionService
{
    /**
     * Delete the account behind a signed-in module user.
     *
     * @param array $user the mod_vpnhood_iap_users row (as SessionService::resolve returns it)
     * @throws ApiException 409 deletion_blocked while active web services exist
     */
    public function deleteUser(array $user): void
    {
        $userId = (int) $user['id'];
        $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;

        if ($clientId !== null) {
            $this->deleteClientSide($clientId);
        }
        $this->eraseModuleRows($userId);
        $this->journal($userId, $clientId, 'deleted');
    }

    /**
     * Delete a WHMCS-client account from the client area (the web deletion path
     * Play requires). Works for app buyers and pure web customers alike; when a
     * module account hangs on the client's email it dies with it.
     *
     * @throws ApiException 409 deletion_blocked while active web services exist
     */
    public function deleteClient(int $clientId, ?array $moduleUser): void
    {
        $this->deleteClientSide($clientId);
        if ($moduleUser !== null) {
            $this->eraseModuleRows((int) $moduleUser['id']);
        }
        $this->journal($moduleUser !== null ? (int) $moduleUser['id'] : null, $clientId, 'deleted');
    }

    // ------------------------------------------------------------------ steps --

    /** @throws ApiException */
    private function deleteClientSide(int $clientId): void
    {
        $this->assertNoActiveWebServices($clientId);
        $this->cancelUnpaidInvoices($clientId);
        $this->anonymizeClient($clientId);
    }

    /**
     * Active services the module did NOT provision mean a live web relationship —
     * possibly a recurring gateway agreement this module refuses to touch. Refuse
     * with an actionable message; nothing has been changed yet.
     *
     * @throws ApiException 409 deletion_blocked
     */
    private function assertNoActiveWebServices(int $clientId): void
    {
        $moduleServiceIds = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('client_id', $clientId)
            ->whereNotNull('service_id')
            ->pluck('service_id')
            ->all();

        $blocking = Capsule::table('tblhosting')
            ->where('userid', $clientId)
            ->whereIn('domainstatus', ['Active', 'Suspended'])
            ->whereNotIn('id', $moduleServiceIds ?: [0])
            ->exists();

        if ($blocking) {
            throw new ApiException(
                'This account has active web services. Cancel them in the web client area first, then delete the account.',
                409, 'deletion_blocked');
        }
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

        $result = localAPI('CloseClient', ['clientid' => $clientId]);
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
        // The purchase ledger keeps user_id as a DEAD pointer on purpose: the person it
        // named no longer exists anywhere (identities, emails and uids die above), and the
        // journal below retains the same numeric id anyway — so this discloses nothing new.
        // What it buys: Restore Purchases after "forget me" can prove a purchase's owner
        // was journalled-deleted (EntitlementService::relinkErasedOwner) and hand the row
        // to the person's new account, instead of dead-ending on the binding guard.
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->delete();
    }

    /**
     * Numeric ids only — no PII, so the journal itself never becomes a tombstone.
     * It exists to make the anonymization re-runnable after a backup restore.
     */
    private function journal(?int $userId, ?int $clientId, string $outcome): void
    {
        Capsule::table('mod_vpnhood_iap_deletions')->insert([
            'user_id'    => $userId,
            'client_id'  => $clientId,
            'outcome'    => $outcome,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
