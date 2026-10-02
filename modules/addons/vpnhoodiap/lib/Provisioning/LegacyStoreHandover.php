<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterRegistry;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Hands a customer of a previous store their entitlement the moment they sign in to the
 * app, without them doing anything.
 *
 * WHY THIS EXISTS. A previous store's Play subscriptions that are still auto-renewing are
 * imported into `mod_vpnhood_iap_legacy_subs` (the table this class reads), and each one
 * is handed to the customer who signs in with the verified email it was bought with.
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
 * TODO REMOVE the whole legacy-store carve-out, in a versioned release, once drained: no
 * `legacy.handover` or `purchase.legacy-adopted` logged for one release cycle, and no imported
 * row still live at the store. Goes together: this class and its call in api.php's sign-in,
 * EntitlementService::adoptLegacyPurchase and its call in redeem(), the table and
 * vpnhoodiap_migrateToLegacyStoreHandover() (drop it in _upgrade), the one-shot import, and
 * their tests. A row's 'pending' status is not the measure: a restore adopts without claiming it.
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

            // The store is the authority on whether this is still owed, not the imported copy.
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
