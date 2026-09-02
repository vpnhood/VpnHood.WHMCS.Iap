<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterRegistry;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Hands a customer of the retired .NET store (store.vpnhood.com / VhStoreDb) their
 * entitlement the moment they sign into the new app, without them doing anything.
 *
 * WHY THIS EXISTS. The old store is being switched off. It sold Play subscriptions that
 * are still auto-renewing, and its database is the only place that records who owns
 * them. The owner's position (2026-09-01) is that we neither chase these customers nor
 * cancel their subscriptions — cancelling is theirs to do in Google — but that anyone
 * who signs in with the same Google account must get their code. This class is that
 * promise, and `mod_vpnhood_iap_legacy_subs` is the copy of the old database it reads.
 *
 * MATCHING IS BY VERIFIED EMAIL, and it has to be: the old store never stored a Google
 * OIDC subject, so there is no stronger key to join on. The caller has already rejected
 * any sign-in the provider did not mark email_verified, which is what keeps this from
 * being a way to claim someone else's subscription by naming their address. A customer
 * whose Google address changed since will simply not match and looks like a new user.
 *
 * IT DOES NOT PROVISION ANYTHING ITSELF. Every row is pushed through the ordinary
 * redeem path — the same one the app's own restore uses — so the store is re-queried,
 * a cancelled or refunded subscription is refused on the spot, the catalog mapping
 * decides what they get, and the ledger's idempotency still holds. In particular the
 * purchase carries the OLD store's obfuscated uid, so EntitlementService resolves it
 * through adoptLegacyPurchase exactly as it would for a manual restore. Nothing here
 * bypasses a guard; it only saves the customer the trip.
 *
 * DELETE THIS with the rest of the legacy-store carve-out once the table is drained —
 * see adoptLegacyPurchase, and .user/docs/legacy-store-shutdown.md for the runbook.
 */
final class LegacyStoreHandover
{
    private IapRepository $repo;

    public function __construct(IapRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Claim whatever this address carried over from the old store.
     *
     * NEVER THROWS. A sign-in that works today must keep working if the old store's
     * data is wrong, a subscription has lapsed, or Google is briefly unreachable —
     * the customer would be locked out of the app over a migration they never asked
     * for. Failures are recorded on the row and retried at the next sign-in.
     */
    public function claimFor(array $user, string $verifiedEmail): void
    {
        try {
            $email = strtolower(trim($verifiedEmail));
            if ($email === '' || !Capsule::schema()->hasTable('mod_vpnhood_iap_legacy_subs')) {
                return;
            }
            $rows = Capsule::table('mod_vpnhood_iap_legacy_subs')
                ->where('email', $email)
                ->where('status', 'pending')
                ->get()->map(fn ($row) => (array) $row)->all();
            foreach ($rows as $row) {
                $this->claimRow($user, $row);
            }
        } catch (\Throwable $e) {
            $this->repo->log((int) $user['id'], 'legacy.handover', '', 0, '',
                'legacy handover failed for this sign-in: ' . $e->getMessage());
        }
    }

    /**
     * One carried-over subscription. Outcomes are recorded so "drained" stays a
     * measured fact rather than an assumption:
     *   claimed  — the customer now holds it in WHMCS, nothing more to do
     *   inactive — the store says it is over (cancelled, refunded, lapsed); never retried
     *   pending  — a transient failure; the next sign-in tries again
     */
    private function claimRow(array $user, array $row): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            $app = $this->repo->findAppByPackageName((string) $row['store'], (string) $row['package_name']);
            if ($app === null) {
                throw new \RuntimeException("no active app for {$row['store']}/{$row['package_name']}");
            }
            $adapter = StoreAdapterRegistry::get((string) $row['store']);
            $record = $adapter->verifyPurchase($app, [
                'purchaseToken' => (string) $row['purchase_key'],
                'productId'     => (string) $row['store_product_id'],
            ]);

            // The store is the authority on whether this is still owed, not our copy of
            // a database we are about to delete.
            if (!$record->isEntitled()) {
                $this->finish($row, 'inactive', $user, $now, 'store reports: ' . $record->state);
                return;
            }

            (new EntitlementService($this->repo))->redeem($app, $record, $user, $adapter);
            $this->finish($row, 'claimed', $user, $now, null);
            $this->repo->log((int) $user['id'], 'legacy.handover', '', 201,
                ['store' => $row['store'], 'order' => $row['provider_order_id'], 'plan' => $row['store_base_plan_id']],
                'legacy-store subscription handed over at sign-in');
        } catch (\Throwable $e) {
            // 410 means the store closed it out; anything else may pass next time.
            $gone = $e instanceof \WHMCS\Module\Addon\VpnHoodIap\ApiException && $e->getCode() === 410;
            $this->finish($row, $gone ? 'inactive' : 'pending', $user, $now, $e->getMessage());
        }
    }

    private function finish(array $row, string $status, array $user, string $now, ?string $error): void
    {
        Capsule::table('mod_vpnhood_iap_legacy_subs')->where('id', (int) $row['id'])->update([
            'status'          => $status,
            'claimed_user_id' => $status === 'claimed' ? (int) $user['id'] : null,
            'claimed_at'      => $status === 'claimed' ? $now : null,
            'attempts'        => (int) $row['attempts'] + 1,
            'last_attempt_at' => $now,
            'last_error'      => $error === null ? null : substr($error, 0, 500),
        ]);
    }
}
