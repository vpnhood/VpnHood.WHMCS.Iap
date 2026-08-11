<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * The single idempotent redemption core. Both POST /billing/purchases (client path,
 * with a session user) and webhook PURCHASED (no session) converge here.
 *
 * Serialization: a MySQL advisory lock on (store, purchase_key) — NOT a DB
 * transaction, because the flow spans localAPI calls (AddOrder/AcceptOrder)
 * whose own commits would silently end an outer transaction. Replays return
 * the existing entitlement without a second order.
 *
 * The store adapter's finalize (acknowledge) runs ONLY after provisioning
 * succeeded — an unacknowledged purchase is auto-refunded by the store,
 * which is the customer's fail-safe against a wedged backend.
 */
class EntitlementService
{
    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * @param array $app the mod_vpnhood_iap_apps row
     * @param PurchaseRecord $record freshly fetched from the store API
     * @param ?array $sessionUser the module user on the client path; null on the webhook path
     * @return array{state:string, accessCode:?string, expiresAt:?string, planId:?string,
     *               purchasedAt:?string, autoRenewing:?bool, priceAmount:?string,
     *               priceCurrency:?string, billingPeriod:?string}
     * @throws ApiException
     */
    public function redeem(array $app, PurchaseRecord $record, ?array $sessionUser, StoreAdapterInterface $adapter): array
    {
        // ---- binding guard (client path): the purchase must carry the session
        // user's own external uid, or someone is replaying a stolen token. One
        // carve-out: a uid whose owner went through "forget me" — restore after
        // account deletion is the same person holding the same store account,
        // and must not dead-end on a 403 (see relinkErasedOwner for the rules).
        if ($sessionUser !== null && $record->obfuscatedUid !== $sessionUser['external_uid']
            && !$this->relinkErasedOwner($record, $sessionUser)
        ) {
            throw new ApiException('This purchase belongs to a different account.', 403, 'purchase_account_mismatch');
        }

        // ---- attribute the purchase to a module user
        $user = $sessionUser;
        if ($user === null && $record->obfuscatedUid !== null) {
            $user = $this->repo->getUserByExternalUid($record->obfuscatedUid);
        }

        $this->ensurePurchaseRow($app, $record, $user);

        $lockName = 'vpnhoodiap.' . $record->store . '.' . sha1($record->purchaseKey);
        $acquired = (int) (Capsule::select('SELECT GET_LOCK(?, 30) AS l', [$lockName])[0]->l ?? 0);
        if ($acquired !== 1) {
            throw new ApiException('This purchase is being processed. Try again shortly.', 503, 'purchase_in_progress');
        }
        try {
            return $this->redeemLocked($app, $record, $user, $adapter);
        } finally {
            Capsule::select('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /** The lock-holding body of redeem(). */
    private function redeemLocked(array $app, PurchaseRecord $record, ?array $user, StoreAdapterInterface $adapter): array
    {
            $row = (array) Capsule::table('mod_vpnhood_iap_purchases')
                ->where('store', $record->store)
                ->where('purchase_key', $record->purchaseKey)
                ->first();

            // webhook-driven re-provisioning has no session and old records may
            // carry no uid — the ledger row remembers who bought it
            if ($user === null && !empty($row['user_id'])) {
                $user = $this->repo->getUser((int) $row['user_id']);
            }

            // ---- already redeemed: idempotent replay — but only onto a service
            // that still exists. A terminated/deleted service while the store
            // still entitles (late renewal, out-of-order events) falls through
            // and provisions anew; a resurrected status flip is never attempted.
            if ($row['status'] === 'provisioned' && $row['service_id'] !== null) {
                $serviceStatus = (string) Capsule::table('tblhosting')
                    ->where('id', (int) $row['service_id'])->value('domainstatus');
                if (in_array($serviceStatus, ['Active', 'Suspended'], true)) {
                    return $this->entitlementFor($record, (int) $row['service_id'], $row['created_at'] ?? null);
                }
            }

            // ---- store-side state gates
            if ($record->state === PurchaseRecord::STATE_PENDING) {
                $this->updateRow($row, ['status' => 'pending', 'last_error' => null], $record);
                return [
                    'state'         => 'pending',
                    'accessCode'    => null,
                    'expiresAt'     => null,
                    'planId'        => null,
                    'purchasedAt'   => null,
                    'autoRenewing'  => null,
                    'priceAmount'   => null,
                    'priceCurrency' => null,
                    'billingPeriod' => null,
                ];
            }
            if (!$record->isEntitled()) {
                $this->updateRow($row, ['status' => 'expired', 'last_error' => 'not entitled at redeem time: ' . $record->state], $record);
                throw new ApiException('This purchase is no longer active.', 410, 'purchase_inactive');
            }

            // ---- catalog gate: unmapped SKUs park loudly, never provision, never ack
            $mappings = $this->repo->findMappings((int) $app['id'], $record->storeProductId, $record->basePlanId);
            if ($mappings === []) {
                $this->updateRow($row, [
                    'status'     => 'pending',
                    'last_error' => "no catalog mapping for {$record->storeProductId}/{$record->basePlanId}",
                ], $record);
                $this->alertAdmins("vpnhoodiap: purchase for UNMAPPED SKU {$record->storeProductId}/{$record->basePlanId} parked (app #{$app['id']}).");
                throw new ApiException('This product is not available yet. Please contact support.', 422, 'plan_not_available');
            }

            // ---- account gate
            if ($user === null) {
                $this->updateRow($row, ['status' => 'pending', 'last_error' => 'no signed-in user for this purchase uid'], $record);
                $this->alertAdmins("vpnhoodiap: purchase {$record->purchaseKey} has no attributable user; parked.");
                throw new ApiException('This purchase cannot be attributed to an account.', 409, 'purchase_unattributed');
            }
            // ---- one active store subscription per account
            //
            // The app never lets a premium user reach checkout, so a second live
            // purchase key means something anomalous. Refuse it BEFORE provisioning:
            // the adapter's finalize never runs, the purchase stays unacknowledged,
            // and the store auto-refunds it — the buyer is made whole without us
            // holding money for a subscription they cannot use.
            //
            // Renewals never arrive here (same key → the idempotent replay above
            // returns first), and an upgrade/resubscribe carries the key it
            // replaces, so a replacement is allowed through rather than blocked.
            $liveKeys = Capsule::table('mod_vpnhood_iap_purchases')
                ->where('user_id', (int) $user['id'])
                ->where('status', 'provisioned')
                ->where('purchase_key', '!=', $record->purchaseKey)
                ->where(function ($query) {
                    $query->whereNull('expiry_time')->orWhere('expiry_time', '>', date('Y-m-d H:i:s'));
                })
                ->pluck('purchase_key')->all();
            $supersedes = $record->linkedPurchaseKey !== null
                && in_array($record->linkedPurchaseKey, $liveKeys, true);
            if ($liveKeys !== [] && !$supersedes) {
                $this->updateRow($row, [
                    'status'     => 'failed',
                    'last_error' => 'account already holds an active store subscription',
                ], $record);
                $this->alertAdmins("vpnhoodiap: purchase {$record->purchaseKey} refused — user #{$user['id']}"
                    . ' already holds an active store subscription; left unacknowledged for store refund.');
                throw new ApiException('This account already has an active subscription.', 409,
                    'subscription_already_active');
            }

            $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;
            $clients = new ClientProvisioner();
            if ($clientId === null) {
                $resolution = (new AccountService())->resolveClientForEmail((string) $user['email']);
                $clientId = $resolution['clientId']
                    ?? $clients->createClient((string) $user['email'], $user['display_name'] ?? null);
                $this->repo->linkUserClient((int) $user['id'], $clientId);

                // A client that already existed was not born from a proven mailbox the
                // way createClient's is, so the purchase attaches to it but the client
                // area stays shut for that account until WHMCS confirms the address.
                // The purchase itself is never held up — the access code ships regardless.
                if ($resolution['clientId'] !== null) {
                    $accounts = new AccountService();
                    if (!$accounts->isEmailVerified((string) $user['email'])) {
                        $this->repo->requireEmailVerification((int) $user['id']);
                        $accounts->sendVerificationEmail((string) $user['email']);
                    }
                }
            }
            // keep the client in step with the account's latest known name
            $clients->syncClient($clientId, $user['display_name'] ?? null);

            // ---- order + provision (one order per mapping row; bundles = several)
            $orders = new OrderProvisioner($this->repo);
            $placed = [];
            try {
                foreach (array_values($mappings) as $index => $mapping) {
                    $transactionId = ($record->storeOrderId ?? $record->purchaseKey)
                        . ($index > 0 ? '-' . ($index + 1) : '');
                    $order = $orders->placeOrder(
                        $clientId,
                        (int) $mapping['whmcs_product_id'],
                        (int) $mapping['billing_cycle_months'],
                        $transactionId
                    );
                    $order['transactionId'] = $transactionId;
                    $placed[] = $order;
                }
            } catch (\Throwable $e) {
                foreach ($placed as $order) {
                    $orders->safeDeleteOrder($order['orderId']);
                }
                $this->updateRow($row, ['status' => 'failed', 'last_error' => substr($e->getMessage(), 0, 500)], $record);
                throw $e instanceof ApiException ? $e : new ApiException('Provisioning failed.', 502, 'provisioning_failed');
            }

            // the real charge belongs to the purchase, not to each invoice: the
            // primary invoice carries it; bundle-secondary invoices get the
            // generic wording so amounts are never double-stated
            foreach ($placed as $index => $order) {
                $orders->annotateInvoice(
                    (int) $order['invoiceId'],
                    $record->store,
                    $index === 0 ? $record->amount : null,
                    $index === 0 ? $record->currency : null
                );
                $orders->applyStoreValue(
                    (int) $order['invoiceId'],
                    (string) $order['transactionId'],
                    $record->amount,
                    $record->currency,
                    $clientId,
                    isPrimary: $index === 0
                );
                $orders->tagServiceStore((int) $order['serviceId'], $record->store);
            }

            $primary = $placed[0];
            $this->updateRow($row, [
                'status'         => 'provisioned',
                'user_id'        => (int) $user['id'],
                'client_id'      => $clientId,
                'service_id'     => $primary['serviceId'],
                'whmcs_order_id' => $primary['orderId'],
                'last_error'     => null,
            ], $record);

            // ---- only now is the store told the purchase was delivered
            $adapter->finalize($app, $record);

            return $this->entitlementFor($record, $primary['serviceId'], $row['created_at'] ?? null);
    }

    /**
     * Portal-neutral entitlement payload (no WHMCS ids on the wire).
     *
     * Everything past `accessCode` exists so the app can describe the subscription
     * without asking the store a second time: what was paid, whether it renews, and
     * since when. `billingPeriod` is an ISO-8601 duration rather than a WHMCS cycle
     * name — the wire vocabulary stays portal-neutral.
     *
     * @param ?string $purchasedAt the ledger row's created_at (when we first saw the purchase)
     */
    private function entitlementFor(PurchaseRecord $record, int $serviceId, ?string $purchasedAt = null): array
    {
        return [
            'state'         => 'provisioned',
            'accessCode'    => (new DeliveryReader())->readAccessCode($serviceId),
            'expiresAt'     => $record->expiryTimeUnix !== null ? gmdate('c', $record->expiryTimeUnix) : null,
            'planId'        => $record->basePlanId !== ''
                ? $record->storeProductId . '/' . $record->basePlanId
                : $record->storeProductId,
            'purchasedAt'   => $purchasedAt !== null ? gmdate('c', strtotime($purchasedAt)) : null,
            'autoRenewing'  => $record->autoRenewing,
            'priceAmount'   => $record->amount,
            'priceCurrency' => $record->currency,
            'billingPeriod' => IapRepository::billingPeriodForService($serviceId),
        ];
    }

    /** Insert the ledger row if it does not exist yet (unique (store, purchase_key)). */
    /**
     * Restore after "forget me": may THIS session take over a purchase whose uid
     * does not match? Only when every one of these holds:
     *
     *   1. the record's uid resolves to NO live account — a live owner is the
     *      stolen-token case, and it keeps its 403;
     *   2. the purchase is already on the ledger, its remembered owner is gone,
     *      AND that owner is journalled as deleted — erasure is the only way a
     *      user row disappears legitimately; anything else stays fail-closed;
     *   3. the session account holds no other live subscription — the take-over
     *      may only re-deliver, never stack a second entitlement.
     *
     * Passing hands the ledger row to the session user and lets the normal flow
     * continue: the idempotent replay then returns the purchase's EXISTING
     * service and access code — never a new order, never a new code. That is
     * what makes forget-me useless as a code mint: the store proof always leads
     * back to the one service the purchase already paid for, and a person who
     * shared their old code before deleting gains nothing they could not have
     * gained by sharing it without deleting.
     *
     * GDPR: nothing erased is resurrected. The person proves the purchase with
     * the store's own receipt (possession of the store account that paid); the
     * old identity, client and invoices stay anonymized.
     */
    private function relinkErasedOwner(PurchaseRecord $record, array $sessionUser): bool
    {
        // rule 1 — the uid must be orphaned
        if ($record->obfuscatedUid === null
            || $this->repo->getUserByExternalUid($record->obfuscatedUid) !== null) {
            return false;
        }

        $row = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('store', $record->store)
            ->where('purchase_key', $record->purchaseKey)
            ->first(['id', 'user_id']);
        if ($row === null || $row->user_id === null) {
            return false;
        }
        if ((int) $row->user_id === (int) $sessionUser['id']) {
            return true; // this account already took it over — repeat restore
        }

        // rule 2 — the remembered owner must be gone AND journalled as deleted
        if ($this->repo->getUser((int) $row->user_id) !== null) {
            return false;
        }
        $ownerWasErased = Capsule::table('mod_vpnhood_iap_deletions')
            ->where('user_id', (int) $row->user_id)
            ->exists();
        if (!$ownerWasErased) {
            return false;
        }

        // rule 3 — the session account must not already hold a live subscription
        $hasLiveSubscription = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('user_id', (int) $sessionUser['id'])
            ->where('status', 'provisioned')
            ->where('purchase_key', '!=', $record->purchaseKey)
            ->where(function ($query) {
                $query->whereNull('expiry_time')->orWhere('expiry_time', '>', date('Y-m-d H:i:s'));
            })
            ->exists();
        if ($hasLiveSubscription) {
            return false;
        }

        Capsule::table('mod_vpnhood_iap_purchases')
            ->where('id', (int) $row->id)
            ->update(['user_id' => (int) $sessionUser['id'], 'updated_at' => date('Y-m-d H:i:s')]);
        $this->repo->log(null, 'purchase.relinked', '', 0,
            ['store' => $record->store, 'purchase' => (int) $row->id,
                'erased_user' => (int) $row->user_id, 'new_user' => (int) $sessionUser['id']],
            'restore after account deletion: the ledger row was handed to the new account');
        return true;
    }

    private function ensurePurchaseRow(array $app, PurchaseRecord $record, ?array $user): void
    {
        $exists = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('store', $record->store)
            ->where('purchase_key', $record->purchaseKey)
            ->exists();
        if ($exists) {
            return;
        }
        try {
            Capsule::table('mod_vpnhood_iap_purchases')->insert([
                'app_id'       => (int) $app['id'],
                'store'        => $record->store,
                'purchase_key' => $record->purchaseKey,
                'user_id'      => $user['id'] ?? null,
                'status'       => 'pending',
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // concurrent insert lost the unique-key race — the row exists, which is all we need
        }
    }

    /** Update the ledger row with state + the record's rolling facts. */
    private function updateRow(array $row, array $changes, PurchaseRecord $record): void
    {
        $rolling = [
            'store_order_id' => $record->storeOrderId,
            'expiry_time'    => $record->expiryTimeUnix !== null ? date('Y-m-d H:i:s', $record->expiryTimeUnix) : null,
            'auto_renewing'  => $record->autoRenewing ? 1 : 0,
            'is_test'        => $record->isTest ? 1 : 0,
            'linked_purchase_key' => $record->linkedPurchaseKey,
            'raw_payload'    => json_encode($record->raw),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        // informational: the store's real charge; a fetch miss keeps the last known value
        if ($record->amount !== null) {
            $rolling['store_amount'] = $record->amount;
            $rolling['store_currency'] = $record->currency;
        }
        Capsule::table('mod_vpnhood_iap_purchases')->where('id', $row['id'])->update(array_merge($rolling, $changes));
    }

    /** Loud ops: system activity log + module log (daily digest reads these). */
    private function alertAdmins(string $message): void
    {
        try {
            localAPI('LogActivity', ['description' => $message]);
        } catch (\Throwable $e) {
            // the alert must never take the pipeline down
        }
        $this->repo->log(null, 'alert', '', 0, null, $message);
    }
}
