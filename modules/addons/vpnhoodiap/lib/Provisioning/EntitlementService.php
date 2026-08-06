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
 * The single idempotent redemption core. Both purchase.verify (client path,
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
     * @return array{state:string, accessCode:?string, expiresAt:?string, planId:?string}
     * @throws ApiException
     */
    public function redeem(array $app, PurchaseRecord $record, ?array $sessionUser, StoreAdapterInterface $adapter): array
    {
        // ---- binding guard (client path): the purchase must carry the session
        // user's own external uid, or someone is replaying a stolen token.
        if ($sessionUser !== null && $record->obfuscatedUid !== $sessionUser['external_uid']) {
            throw new ApiException('This purchase belongs to a different account.', 403);
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
            throw new ApiException('This purchase is being processed. Try again shortly.', 503);
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
                    return $this->entitlementFor($record, (int) $row['service_id']);
                }
            }

            // ---- store-side state gates
            if ($record->state === PurchaseRecord::STATE_PENDING) {
                $this->updateRow($row, ['status' => 'pending', 'last_error' => null], $record);
                return ['state' => 'pending', 'accessCode' => null, 'expiresAt' => null, 'planId' => null];
            }
            if (!$record->isEntitled()) {
                $this->updateRow($row, ['status' => 'expired', 'last_error' => 'not entitled at redeem time: ' . $record->state], $record);
                throw new ApiException('This purchase is no longer active.', 410);
            }

            // ---- catalog gate: unmapped SKUs park loudly, never provision, never ack
            $mappings = $this->repo->findMappings((int) $app['id'], $record->storeProductId, $record->basePlanId);
            if ($mappings === []) {
                $this->updateRow($row, [
                    'status'     => 'pending',
                    'last_error' => "no catalog mapping for {$record->storeProductId}/{$record->basePlanId}",
                ], $record);
                $this->alertAdmins("vpnhoodiap: purchase for UNMAPPED SKU {$record->storeProductId}/{$record->basePlanId} parked (app #{$app['id']}).");
                throw new ApiException('This product is not available yet. Please contact support.', 422);
            }

            // ---- account gate
            if ($user === null) {
                $this->updateRow($row, ['status' => 'pending', 'last_error' => 'no signed-in user for this purchase uid'], $record);
                $this->alertAdmins("vpnhoodiap: purchase {$record->purchaseKey} has no attributable user; parked.");
                throw new ApiException('This purchase cannot be attributed to an account.', 409);
            }
            $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;
            $clients = new ClientProvisioner();
            if ($clientId === null) {
                $accounts = new AccountService();
                $resolution = $accounts->resolveClientForEmail((string) $user['email']);
                if ($resolution['state'] === AccountService::STATE_EMAIL_UNVERIFIED) {
                    $this->updateRow($row, ['status' => 'awaiting_email_verification', 'last_error' => null], $record);
                    $accounts->sendVerificationEmail((string) $user['email']);
                    return ['state' => 'awaiting_email_verification', 'accessCode' => null, 'expiresAt' => null, 'planId' => null];
                }
                $clientId = $resolution['clientId']
                    ?? $clients->createClient((string) $user['email'], $user['display_name'] ?? null);
                $this->repo->linkUserClient((int) $user['id'], $clientId);
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
                    $placed[] = $orders->placeOrder(
                        $clientId,
                        (int) $mapping['whmcs_product_id'],
                        (int) $mapping['billing_cycle_months'],
                        $transactionId
                    );
                }
            } catch (\Throwable $e) {
                foreach ($placed as $order) {
                    $orders->safeDeleteOrder($order['orderId']);
                }
                $this->updateRow($row, ['status' => 'failed', 'last_error' => substr($e->getMessage(), 0, 500)], $record);
                throw $e instanceof ApiException ? $e : new ApiException('Provisioning failed.', 502);
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

            return $this->entitlementFor($record, $primary['serviceId']);
    }

    /** Portal-neutral entitlement payload (no WHMCS ids on the wire). */
    private function entitlementFor(PurchaseRecord $record, int $serviceId): array
    {
        return [
            'state'      => 'provisioned',
            'accessCode' => (new DeliveryReader())->readAccessCode($serviceId),
            'expiresAt'  => $record->expiryTimeUnix !== null ? gmdate('c', $record->expiryTimeUnix) : null,
            'planId'     => $record->basePlanId !== ''
                ? $record->storeProductId . '/' . $record->basePlanId
                : $record->storeProductId,
        ];
    }

    /** Insert the ledger row if it does not exist yet (unique (store, purchase_key)). */
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
