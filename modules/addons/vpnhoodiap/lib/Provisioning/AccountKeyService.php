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
 * The account's relationship to premium CODES (docs: account-lifecycle.md §2, §7, §8):
 *
 *  - a code is a bearer credential; holding it is what matters, not owning the
 *    billing behind it. An account points at codes, it never owns them;
 *  - THE ACCOUNT ALWAYS HAS EXACTLY ONE CODE, AND THE SERVER CHOOSES IT. The
 *    app is handed one code or nothing (never a list), so the choice is
 *    recomputed here on every read: a stored choice that is still usable is
 *    kept; a dead one is replaced by the next usable code the account can see
 *    (running first, soonest expiry, unstarted prepaid codes last — §8 "prefer
 *    the code that harms nobody"). No cron, no scheduled job;
 *  - IMPORT (claim) BY CODE: entering a code once proves possession and records
 *    a pointer (mod_vpnhood_iap_claims). It consumes nothing and the same code
 *    may be imported into any number of accounts. Importing is a deliberate act,
 *    so last-one-wins: the imported code becomes the account's chosen one;
 *  - THE APP OWNS NO REMOVE ACT. A code the account applied leaves the device
 *    only with the account (sign-out, deletion); a code the person typed is
 *    their own and removing it is purely local. Nothing is reported here, so
 *    there is no park: an earlier design cleared and parked the choice to
 *    re-open store buying, which existed only to escape a purchase refusal that
 *    no longer exists (§8: prevent before the money, never refuse after).
 *
 * Identity: every method takes the $user array. A module user has id > 0; the
 * client area may hand a stand-in row ['id' => 0, 'client_id' => N] for a pure
 * web customer — claims are then keyed by client_id instead.
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
     * Find the service behind an entered code, whichever install shape delivered
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

    // --------------------------------------------------- deliberate acts --

    /**
     * Import a code: record the pointer and make it the account's chosen code.
     * Importing is a deliberate act, so last-one-wins (§8 rule 7). NOTHING is
     * consumed: the code keeps working for everyone already using it, and any
     * number of accounts may import it.
     *
     * @return array{accessCode: string, expiresAt: ?string, created: bool}
     */
    public function importCode(array $user, string $accessCode): array
    {
        $serviceId = $this->findServiceIdByCode($accessCode);
        if ($serviceId === null) {
            throw new ApiException('No code matches.', 404, 'code_not_found');
        }
        $created = $this->pointAt($user, $serviceId);
        $this->makeDefault($user, $serviceId);
        $state = (new DeliveryReader())->readCodeState($serviceId);
        return ['accessCode' => trim($accessCode), 'expiresAt' => $state['expiresAt'], 'created' => $created];
    }

    /**
     * Deliberately choose the account's code among the codes it can already see
     * (the client-area picker — the only picker there is, lifecycle §8/§9).
     */
    public function setDefaultService(array $user, int $serviceId): void
    {
        if (!$this->userCanPoint($user, $serviceId)) {
            throw new ApiException('This account holds no such code.', 404, 'code_not_found');
        }
        $this->pointAt($user, $serviceId);
        $this->makeDefault($user, $serviceId);
    }

    // ------------------------------------------- the server-chosen code --

    /**
     * THE code that serves this account, recomputed now (lifecycle §8 "which
     * code the account gets"): the stored choice while it is usable; else the
     * next usable code takes over on the spot — running first, soonest expiry,
     * unstarted prepaid last, so promotion never burns a code someone was
     * saving while a running one exists. Promotion is persisted, so every
     * device of the account lands on the same code.
     */
    public function resolveDefaultServiceId(array $user): ?int
    {
        $stored = $this->storedDefaultServiceId($user);
        // stock is never a personal code, even when it was chosen BEFORE the service
        // became a bulk delivery — the mark does not shield it (lifecycle §8)
        if ($stored !== null && IapRepository::serviceProperty($stored, 'bulkDelivery') === 'yes') {
            $stored = null;
        }
        if ($stored !== null && $this->serviceIsUsable($stored)) {
            return $stored;
        }

        $candidates = [];
        foreach ($this->visibleServices($user) as $serviceId => $meta) {
            if ($meta['bulk'] || $serviceId === $stored) {
                continue; // stock is never a personal code; the dead choice cannot re-elect itself
            }
            if (!$this->serviceIsUsable($serviceId)) {
                continue;
            }
            $candidates[$serviceId] = $this->promotionSortKey($serviceId);
        }
        if ($candidates === []) {
            return null;
        }
        asort($candidates);
        $promoted = (int) array_key_first($candidates);
        $this->pointAt($user, $promoted);
        $this->makeDefault($user, $promoted);
        return $promoted;
    }

    /**
     * The one code the app is handed — or null (lifecycle §8 "the app is told
     * a code, not a list"). No list ever crosses to a device.
     *
     * @return array{accessCode: string, expiresAt: ?string}|null
     */
    public function accessCodeInfoForUser(array $user): ?array
    {
        $serviceId = $this->resolveDefaultServiceId($user);
        if ($serviceId === null) {
            return null;
        }
        $reader = new DeliveryReader();
        $code = $reader->readAccessCode($serviceId);
        if ($code === null) {
            return null; // chosen but not deliverable (partner outage) — the app just gets nothing
        }
        return ['accessCode' => $code, 'expiresAt' => $reader->readCodeState($serviceId)['expiresAt']];
    }

    // ----------------------------------------------------- internal state --

    /** The stored choice: an explicit claim first, else the client's marked service. No usability check. */
    private function storedDefaultServiceId(array $user): ?int
    {
        $claim = $this->claimsFor($user)->where('is_default', 1)->first(['service_id']);
        if ($claim !== null) {
            return (int) $claim->service_id;
        }
        $clientId = $this->clientIdOf($user);
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

    /** Record the pointer (idempotent). @return bool true when a new claim row was created. */
    private function pointAt(array $user, int $serviceId): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $clientId = $this->clientIdOf($user);
        $existing = $this->claimsFor($user)->where('service_id', $serviceId)->first();
        if ($existing !== null) {
            return false;
        }
        try {
            Capsule::table('mod_vpnhood_iap_claims')->insert([
                'user_id'    => $userId > 0 ? $userId : null,
                'client_id'  => $userId > 0 ? null : $clientId,
                'service_id' => $serviceId,
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            return false; // lost a concurrent race on the unique index — it exists now
        }
        return true;
    }

    /** Make this service the single marked choice for this identity. */
    private function makeDefault(array $user, int $serviceId): void
    {
        $this->clearDefaultMarks($user);
        $this->claimsFor($user)->where('service_id', $serviceId)->update(['is_default' => 1]);
    }

    /** Clear every default mark this identity can carry: claim flags AND the client's service-property marks. */
    private function clearDefaultMarks(array $user): void
    {
        $this->claimsFor($user)->update(['is_default' => 0]);
        $clientId = $this->clientIdOf($user);
        if ($clientId === null) {
            return;
        }
        foreach ($this->clientKeyServices($clientId) as $serviceId => $flags) {
            if ($flags['isDefault']) {
                $service = \WHMCS\Service\Service::find($serviceId);
                $service?->serviceProperties->save(['isDefaultKey' => '']);
            }
        }
    }

    /**
     * Usable = it would open premium right now. Conservative where the code's
     * clock cannot be read (partner installs): an unreadable code counts as
     * usable — the deliberate escape always works, and an accident stays caught.
     */
    private function serviceIsUsable(int $serviceId): bool
    {
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
        // one-time: the clock lives on the code itself (starts on first use)
        return (new DeliveryReader())->readCodeState($serviceId)['state'] !== 'expired';
    }

    /**
     * Promotion order (§8 rule 3, the fallback without MANAGER device counts):
     * running codes first, soonest expiry first — use a code before it is
     * wasted — then codes whose clock cannot be read, and unstarted prepaid
     * codes strictly last, because promoting one starts a clock nobody meant
     * to start (§4) and that is the one irreversible mistake available here.
     */
    private function promotionSortKey(int $serviceId): string
    {
        $state = (new DeliveryReader())->readCodeState($serviceId);
        if ($state['state'] === 'active' && $state['expiresAt'] !== null) {
            return '1-' . $state['expiresAt'];
        }
        if ($state['state'] === 'not-started') {
            return '3-' . $serviceId;
        }
        // unknown clock: prefer the service's own billing horizon where one exists
        $due = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('nextduedate');
        return ($due !== '' && $due !== '0000-00-00') ? ('2-' . $due) : ('2-9999-' . $serviceId);
    }

    /** Claims visible to this identity: its module-user rows plus its client-keyed rows. */
    private function claimsFor(array $user): \Illuminate\Database\Query\Builder
    {
        $userId = (int) ($user['id'] ?? 0);
        $clientId = $this->clientIdOf($user);
        return Capsule::table('mod_vpnhood_iap_claims')->where(function ($query) use ($userId, $clientId) {
            $query->whereRaw('1 = 0');
            if ($userId > 0) {
                $query->orWhere('user_id', $userId);
            }
            if ($clientId !== null) {
                $query->orWhere('client_id', $clientId);
            }
        });
    }

    private function clientIdOf(array $user): ?int
    {
        return isset($user['client_id']) && $user['client_id'] !== null ? (int) $user['client_id'] : null;
    }

    /** May this account point at this service? Claimed already, or owned by its linked client. */
    private function userCanPoint(array $user, int $serviceId): bool
    {
        if ($this->claimsFor($user)->where('service_id', $serviceId)->exists()) {
            return true;
        }
        $clientId = $this->clientIdOf($user);
        return $clientId !== null && (int) Capsule::table('tblhosting')
            ->where('id', $serviceId)->value('userid') === $clientId;
    }

    // ----------------------------------------------------------- listing --

    /**
     * Every website code this account can see: services its linked client owns
     * plus services it imported. Codes come from DeliveryReader — live on the
     * hub, stored on a partner install.
     *
     * THIS LIST NEVER REACHES A DEVICE (lifecycle §8: the app is told a code,
     * not a list). Its two callers are portal-side: the client-area codes page,
     * and the farewell mail at deletion (§5 step 3 — the mail delivers, the
     * screen only warns).
     *
     * BULK IS NOT A CODE AND NEVER APPEARS HERE. Reseller stock is a merchant
     * concept of the portal: it was handed over once as a file, has no single
     * code to show, and is "never offered in the app as your code" (lifecycle
     * §8) — see bulkOrderCount() for the one place the portal still needs it.
     *
     * @return array<int, array{accessCode: string, expiresAt: ?string, isDefault: bool, serviceId: int}>
     */
    public function webKeysForUser(array $user): array
    {
        $reader = new DeliveryReader();
        $items = [];
        foreach ($this->visibleServices($user) as $serviceId => $meta) {
            if ($meta['bulk']) {
                continue; // stock, not a code
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
                'serviceId'  => $serviceId,
            ];
        }
        return $items;
    }

    /**
     * How many bulk (CSV) orders this account holds. The portal side only:
     * the farewell message and the web deletion page warn that the delivered
     * file cannot be served again once the client-area login is gone, while
     * the codes inside it keep working to their own expiry.
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
        $clientId = $this->clientIdOf($user);
        if ($clientId !== null) {
            foreach ($this->clientKeyServices($clientId) as $serviceId => $flags) {
                $out[$serviceId] = ['source' => 'website'] + $flags;
            }
        }
        foreach ($this->claimsFor($user)->get() as $claim) {
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
     * The client's own code-bearing services (any VpnHood provisioning module —
     * they all store accessTokenId or accessCode), stock excluded from ever
     * being "a code of theirs" but still listed as a delivery.
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
}
