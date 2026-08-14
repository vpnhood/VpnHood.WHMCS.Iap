<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

require_once __DIR__ . '/DeliveryReader.php'; // codes + code state come from the one provisioning-aware reader

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * The account's relationship to premium KEYS (docs: account-lifecycle.md §2, §7, §8):
 *
 *  - a key is a bearer credential; holding it is what matters, not owning the
 *    billing behind it. An account points at keys, it never owns them;
 *  - CLAIM BY CODE: pasting a code once proves possession and records a pointer
 *    (mod_vpnhood_iap_claims). Nothing moves — no billing control, no customer
 *    record, no invoices. This is the route back for every buyer whose sign-in
 *    address is not their buying address (relay addresses make that structural);
 *  - THE DEFAULT KEY is the one key that counts as "serving this person": it is
 *    what the app auto-applies, and what the store-purchase gate refuses on.
 *    First key bought becomes the default at purchase time (provisioning side);
 *    claims become the default only when the account has none. Changing or
 *    clearing it is always a deliberate act — last-one-wins applies to those only.
 *
 * Lookup strategies (decided after probing MANAGER — it cannot search by code):
 *    hub      services store accessCodeHash (sha256) at provisioning; the code
 *             itself is never persisted, same stance as before;
 *    partner  services store the relayed accessCode verbatim (existing contract).
 */
class AccountKeyService
{
    public function __construct(private readonly IapRepository $repo)
    {
    }

    // ------------------------------------------------------------- lookup --

    /**
     * Find the service behind a pasted code, whichever install shape delivered
     * it. Exact possession only — no prefixes, no fuzz. Null when nothing holds
     * this code (the caller answers 404, and rate limiting brakes guessing).
     */
    public function findServiceIdByCode(string $code): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $byHash = $this->serviceIdByProperty('accessCodeHash', IapRepository::codeHash($code));
        if ($byHash !== null) {
            return $byHash;
        }
        return $this->serviceIdByProperty('accessCode', $code);
    }

    /** Newest service whose named service-property equals the value. */
    private function serviceIdByProperty(string $property, string $value): ?int
    {
        $row = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('f.type', 'product')
            ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = ?", [strtolower($property)])
            ->where('v.value', $value)
            ->orderByDesc('v.relid')
            ->first(['v.relid']);
        return $row === null ? null : (int) $row->relid;
    }

    // ------------------------------------------------------------- claims --

    /**
     * Record that this account holds this key. Idempotent; the first key an
     * account ever points at becomes its default (a later claim never steals
     * that — last-one-wins is for deliberate acts, and this one is implicit).
     *
     * @return array{isDefault: bool, created: bool}
     */
    public function claim(int $userId, int $serviceId): array
    {
        $existing = Capsule::table('mod_vpnhood_iap_claims')
            ->where('user_id', $userId)->where('service_id', $serviceId)->first();
        if ($existing !== null) {
            return ['isDefault' => (bool) $existing->is_default, 'created' => false];
        }
        $makeDefault = !$this->userHasDefault($userId);
        try {
            Capsule::table('mod_vpnhood_iap_claims')->insert([
                'user_id'    => $userId,
                'service_id' => $serviceId,
                'is_default' => $makeDefault ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // lost a concurrent race on unique (user_id, service_id) — it exists now
            $row = Capsule::table('mod_vpnhood_iap_claims')
                ->where('user_id', $userId)->where('service_id', $serviceId)->first();
            return ['isDefault' => (bool) ($row->is_default ?? false), 'created' => false];
        }
        return ['isDefault' => $makeDefault, 'created' => true];
    }

    /**
     * Deliberately choose (or clear, with null) the default among the keys the
     * account already points at or owns. Choosing a client-owned key records a
     * claim for it — the default is an account-level fact either way.
     */
    public function setDefault(array $user, ?string $accessCode): void
    {
        $userId = (int) $user['id'];
        if ($accessCode === null) {
            Capsule::table('mod_vpnhood_iap_claims')->where('user_id', $userId)->update(['is_default' => 0]);
            return;
        }
        $serviceId = $this->findServiceIdByCode($accessCode);
        if ($serviceId === null || !$this->userCanPoint($user, $serviceId)) {
            throw new ApiException('This account holds no such key.', 404, 'code_not_found');
        }
        Capsule::table('mod_vpnhood_iap_claims')->where('user_id', $userId)->update(['is_default' => 0]);
        $this->claim($userId, $serviceId);
        Capsule::table('mod_vpnhood_iap_claims')
            ->where('user_id', $userId)->where('service_id', $serviceId)->update(['is_default' => 1]);
    }

    /** May this account point at this service? Claimed already, or owned by its linked client. */
    private function userCanPoint(array $user, int $serviceId): bool
    {
        $claimed = Capsule::table('mod_vpnhood_iap_claims')
            ->where('user_id', (int) $user['id'])->where('service_id', $serviceId)->exists();
        if ($claimed) {
            return true;
        }
        $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;
        return $clientId !== null && (int) Capsule::table('tblhosting')
            ->where('id', $serviceId)->value('userid') === $clientId;
    }

    private function userHasDefault(int $userId): bool
    {
        return Capsule::table('mod_vpnhood_iap_claims')
            ->where('user_id', $userId)->where('is_default', 1)->exists();
    }

    // ----------------------------------------------------------- web keys --

    /**
     * Every website key this account can see: services its linked client owns
     * plus services it claimed. Codes come from DeliveryReader — live on the
     * hub, stored on a partner install.
     *
     * BULK IS NOT A KEY AND NEVER APPEARS HERE. Reseller stock is a merchant
     * concept of the portal: it was handed over once as a file, has no single
     * code to show, and is "never offered in the app as your key" (lifecycle
     * §8). Keeping it out of this list is what stops that concept from ever
     * reaching a consumer app — see bulkOrderCount() for the one place the
     * portal side still needs to know.
     *
     * @return array<int, array{accessCode: string, expiresAt: ?string, isDefault: bool}>
     */
    public function webKeysForUser(array $user): array
    {
        $reader = new DeliveryReader();
        $items = [];
        foreach ($this->visibleServices($user) as $serviceId => $meta) {
            if ($meta['bulk']) {
                continue; // stock, not a key
            }
            $code = $reader->readAccessCode($serviceId);
            if ($code === null) {
                continue; // not provisioned (yet) — nothing to show
            }
            $state = $reader->readCodeState($serviceId);
            $items[] = [
                'accessCode' => $code,
                'expiresAt'  => $state['expiresAt'],
                'isDefault'  => $meta['isDefault'],
            ];
        }
        return $items;
    }

    /**
     * How many bulk (CSV) orders this account holds. The portal side only:
     * the farewell message and the web deletion page warn that the delivered
     * file cannot be served again once the client-area login is gone, while
     * the keys inside it keep working to their own expiry.
     */
    public function bulkOrderCount(array $user): int
    {
        $count = 0;
        foreach ($this->visibleServices($user) as $meta) {
            if ($meta['bulk']) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The service ids this account can see, with per-service flags.
     * @return array<int, array{source: string, isDefault: bool, bulk: bool}>
     */
    private function visibleServices(array $user): array
    {
        $out = [];
        $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;
        if ($clientId !== null) {
            foreach ($this->clientKeyServices($clientId) as $serviceId => $flags) {
                $out[$serviceId] = ['source' => 'website'] + $flags;
            }
        }
        $claims = Capsule::table('mod_vpnhood_iap_claims')
            ->where('user_id', (int) $user['id'])->get();
        foreach ($claims as $claim) {
            $serviceId = (int) $claim->service_id;
            if (!isset($out[$serviceId])) {
                $active = Capsule::table('tblhosting')->where('id', $serviceId)
                    ->whereIn('domainstatus', ['Active', 'Suspended'])->exists();
                if (!$active) {
                    continue;
                }
                $out[$serviceId] = ['source' => 'claimed', 'isDefault' => false,
                    'bulk' => IapRepository::serviceProperty($serviceId, 'bulkDelivery') === 'yes'];
            }
            if ((bool) $claim->is_default) {
                $out[$serviceId]['isDefault'] = true;
            }
        }
        return $out;
    }

    /**
     * The client's own key-bearing services (any VpnHood provisioning module —
     * they all store accessTokenId or accessCode), stock excluded from ever
     * being "a key of theirs" but still listed as a delivery.
     *
     * @return array<int, array{isDefault: bool, bulk: bool}>
     */
    private function clientKeyServices(int $clientId): array
    {
        $rows = Capsule::table('tblhosting as h')
            ->join('tblcustomfieldsvalues as v', 'v.relid', '=', 'h.id')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('h.userid', $clientId)
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->where('f.type', 'product')
            ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) IN ('accesstokenid', 'accesscode')")
            ->where('v.value', '<>', '')
            ->distinct()
            ->get(['h.id'])
            ->pluck('id')->all();

        $out = [];
        foreach ($rows as $serviceId) {
            $serviceId = (int) $serviceId;
            $out[$serviceId] = [
                'isDefault' => IapRepository::serviceProperty($serviceId, 'isDefaultKey') === 'yes',
                'bulk'      => IapRepository::serviceProperty($serviceId, 'bulkDelivery') === 'yes',
            ];
        }
        return $out;
    }

    // ------------------------------------------------- the purchase gate --

    /**
     * Is this account certainly already served by a key of its own? (lifecycle
     * §8: refuse a store purchase on an active store subscription — the ledger,
     * checked by the caller — or an active DEFAULT key from either channel.)
     * Other owned keys and claims never refuse; warnings carry those.
     *
     * Conservative where the key's clock cannot be read: an unreadable one-time
     * key counts as serving (the deliberate escape — clearing the default —
     * always works, and an accident stays caught).
     */
    public function defaultKeyIsActive(array $user): bool
    {
        $serviceId = $this->defaultServiceId($user);
        if ($serviceId === null) {
            return false;
        }
        $service = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.id', $serviceId)
            ->first(['h.domainstatus', 'h.nextduedate', 'p.paytype']);
        if ($service === null || !in_array((string) $service->domainstatus, ['Active', 'Suspended'], true)) {
            return false;
        }
        if ((string) $service->paytype === 'recurring') {
            $due = (string) $service->nextduedate;
            return $due === '' || $due === '0000-00-00' || strtotime($due) >= strtotime(date('Y-m-d'));
        }
        // one-time: the clock lives on the key itself (starts on first use)
        $state = (new DeliveryReader())->readCodeState($serviceId);
        return $state['state'] !== 'expired';
    }

    /** The account's default: an explicit claim first, else its client's marked service. */
    private function defaultServiceId(array $user): ?int
    {
        $claim = Capsule::table('mod_vpnhood_iap_claims')
            ->where('user_id', (int) $user['id'])->where('is_default', 1)->first(['service_id']);
        if ($claim !== null) {
            return (int) $claim->service_id;
        }
        $clientId = $user['client_id'] !== null ? (int) $user['client_id'] : null;
        if ($clientId === null) {
            return null;
        }
        foreach ($this->clientKeyServices($clientId) as $serviceId => $flags) {
            if ($flags['isDefault'] && !$flags['bulk']) {
                return $serviceId;
            }
        }
        return null;
    }
}
