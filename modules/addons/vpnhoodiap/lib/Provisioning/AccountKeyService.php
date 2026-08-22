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
 * The account's relationship to premium CODES (access-code-keyring-plan.md; lifecycle §2, §7, §8).
 *
 * AN ACCOUNT HOLDS CODES, AND ALL OF THEM ARE TREATED THE SAME WAY. Three channels put one on an
 * account — a store subscription, a portal-store purchase, and the ONE code the person typed and
 * uploaded — and nothing about the third is special once it has arrived.
 *
 *  - THE UPLOAD SLOT IS A STORED STRING (users.uploaded_access_code), not a pointer at a service.
 *    The account accepts any string of valid access-code shape without proving that it exists:
 *    validity is settled at USE time by the access server, never at save time by the portal (§5).
 *    A save therefore either succeeds or fails on a network error — there is no "not found" answer
 *    and no result status to inspect. Uploading a different code replaces what is there; that is
 *    the accepted price of a single slot;
 *  - THERE IS ONE RANKING AND IT DECIDES EVERYTHING, recomputed on every read (§2) and
 *    DETERMINISTIC — no dates, no clock arithmetic, nothing device-reported steering consumption
 *    order. Nothing is stored as "the" selection, so nothing has to be repaired when a code dies:
 *       0. the STORE SUBSCRIPTION, this device's own store first — and only while the store is
 *          still charging for it: a subscription we ended stops being one of this person's codes
 *          at the moment it ends, because we are the ones who ended it;
 *       1. a portal code with live recurring billing;
 *       2. THE IMPORTED CODE — typing a code is saying "use this one", so it wins over anything
 *          nobody is being billed for, and never over anything that is;
 *       3. the other portal codes;
 *       4. nothing.
 *    Within a group: a code whose clock has already started before one that has not (an unstarted
 *    one-time code is worth more unused), then oldest purchase first. The store subscription is
 *    IN here rather than chosen by the caller, because two selectors meant a refused subscription
 *    code was compared against one answer and served from another, and so could never be retired;
 *  - ELIGIBILITY IS THE ONLY CONCEPT (§4), and only an access-server refusal sets it. This install's
 *    view of a clock never retires a code: it could as easily start an UNUSED one early, and a
 *    prepaid code begins its life on first use. Expiry is display, never a verdict. There are no
 *    learned expiry dates, no observation timestamps and no successful-connection reports;
 *  - A REJECTION NEVER SKIPS WHAT IS BEING PAID FOR RIGHT NOW. Downgrading a paying person to a
 *    lesser code would hide our own provisioning fault behind a worse credential — and this is also
 *    what makes RENEWAL work with no machinery: a renewed service is paid-now again, so its code
 *    is offered again without anybody clearing anything;
 *  - A REJECTION DEMOTES A CODE; IT NEVER TAKES IT AWAY. A refused code ranks below every eligible
 *    one, and when every code an account holds has been refused they TAKE TURNS, least recently
 *    refused first (the refusal's row id is the order — no clock). The account never answers "you
 *    hold nothing" while it holds something: the person does hold them, their device keeps its code
 *    either way (§8), and a second device must not be told a different story than the first. It is
 *    also how a code that gains time returns with no machinery — topped up or extended, tried again
 *    on its next turn, accepted this time. Nothing we ENDED comes back this way: an ended
 *    subscription, a code switched off in the panel and a dead service are not candidates at all;
 *  - A REJECTION IS KEYED BY THE CODE, PER ACCOUNT (§4). Identical access codes ARE the same
 *    credential, so a rejection applies to every inventory entry holding that string — the upload
 *    slot and any service delivering it. Per account, because a bearer code may be serving somebody
 *    else perfectly well. Only a fingerprint is stored: the credential must not gain a second home;
 *  - A REPORT IS ACCEPTED ONLY WHILE IT IS STILL ABOUT THE CURRENT CODE. The reported string is
 *    compared, inside the identity lock, against what the ranking would hand out right now; a
 *    delayed refusal that has been overtaken by a different code is dropped. One case survives
 *    deliberately: remove-then-re-add of the SAME string, where a late report lands on the restored
 *    code. Distinguishing those two incarnations would cost a whole code-identity system, and the
 *    recovery is one more explicit Retry;
 *  - NOTHING IS DELETED BY THE SYSTEM (§3). The marks that steer the ranking are reversible and
 *    remove nothing: is_auto_selectable (the person, in the panel, default TRUE) and rejected (the
 *    system, from a device). They are kept apart on purpose — a system rejection must not erase a
 *    deliberate "keep this for later", and a retry must not re-arm a code somebody parked. Typing a
 *    code again clears its rejection, which is the whole of "Retry" and needs no second endpoint.
 *
 * Identity: every method takes the $user array. A module user has id > 0; the client area may hand
 * a stand-in row ['id' => 0, 'client_id' => N] for a pure web customer — claims are then keyed by
 * client_id instead. The upload slot is module-user state, so a stand-in row has none, which is
 * correct: a pure web customer uploads nothing from an app.
 *
 * Lookup strategies (decided after probing MANAGER — it cannot search by code):
 *    hub      services store accessCodeHash (sha256) at provisioning; the code
 *             itself is never persisted, same stance as before;
 *    partner  services store the relayed accessCode verbatim (existing contract).
 * The lookup is now used for CLASSIFICATION ONLY — to notice that an uploaded code is one the
 * account already owns — never to reject one.
 */
class AccountKeyService
{
    /**
     * Service states whose credential may still be serving. CANCELLED IS NOT ONE: in WHMCS a
     * cancellation requested for the end of the paid period leaves the service Active until that
     * date — the key runs out the time already bought — and the status only becomes Cancelled once
     * provisioning has terminated it. By then there is no credential to protect. What DOES stay in
     * the ranking is a service whose clock this install reads as expired: expiry is display, never
     * a verdict, because a portal clock that can retire a code can also start an unused one early
     * (§4).
     */
    private const LiveServiceStatuses = ['Active', 'Suspended'];

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
     * Fill, replace or empty the account's ONE upload slot. Nothing is consumed and nothing is
     * proved: the slot holds whatever string was typed, and the access server settles at use time
     * whether it works (§5). There is deliberately no "not found" answer — the old 404 here made
     * the portal an authority on validity that it is not, and turned a blocked reseller code into
     * a save failure the person could do nothing about.
     *
     * Uploading a code the account ALREADY OWNS does not consume the slot: the lookup runs for
     * classification only, and an owned code is turned back on for the ranking instead — because
     * typing a code is saying *use this* (§5).
     *
     * A null value idempotently empties the slot.
     *
     * WRITING A CODE CLEARS ITS REJECTION (§4). Typing a code is saying *use this*, so it is also
     * the whole of "Retry" — there is no second endpoint and nothing to explain in the app.
     */
    public function setAccessCode(array $user, ?string $accessCode): void
    {
        $accessCode = $accessCode === null ? null : trim($accessCode);
        $userId = (int) ($user['id'] ?? 0);

        $this->withIdentityLock($user, function () use ($user, $userId, $accessCode): void {
            if ($accessCode !== null) {
                $this->clearRejection($user, $accessCode);
            }

            // an owned code goes back into the ranking rather than into the slot
            $ownServiceId = $accessCode === null ? null : $this->findServiceIdByCode($accessCode);
            if ($ownServiceId !== null && $this->serviceBelongsToUser($user, $ownServiceId)) {
                $this->setAutoSelectable($user, $ownServiceId, true);
                return;
            }

            if ($userId > 0) {
                Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)
                    ->update(['uploaded_access_code' => $accessCode]);
            }
        });
    }

    /**
     * A DEVICE reports that the access server REFUSED a code (§4). One bit, no reason, no expiry, no
     * timestamp: the ranking only ever asked "skip this one?", and a refusal answers it without the
     * portal having to predict anything.
     *
     * Accepted only while the report is still ABOUT THE CURRENT CODE — compared inside the identity
     * lock against accessCodeInfoForUser(), the same one function the account read answers with, so
     * a delayed refusal that has been overtaken by a different code changes nothing. (Two selectors
     * is how a refused subscription code used to survive for ever: the comparison never matched.) The one case that survives is
     * remove-then-re-add of the same string: identical codes are indistinguishable by design here,
     * and the recovery is one more explicit Retry rather than a code-identity system.
     *
     * Rejects EVERY inventory entry holding that string, because identical access codes are the same
     * credential: the fingerprint is what is stored and what the ranking checks, so the upload slot
     * and any service delivering the same code go together.
     *
     * A report that does not match is silently ignored — the caller gets 204 either way, because the
     * device can do nothing useful with the difference and an error would only invite a retry.
     */
    public function reportRejected(array $user, string $accessCode): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return; // a client-area stand-in has no device to report from
        }
        $accessCode = trim($accessCode);
        if ($accessCode === '') {
            return;
        }

        $this->withIdentityLock($user, function () use ($user, $userId, $accessCode): void {
            $current = $this->accessCodeInfoForUser($user);
            if ($current === null || !hash_equals((string) $current['accessCode'], $accessCode)) {
                return; // overtaken: the account has moved on to a different code
            }

            // Re-inserted rather than updated, so the row's OWN ID orders the refusals: when
            // every code an account holds has been refused they take turns, least recently refused
            // first, and an id that only grows says which is which without a clock (§4).
            $hash = IapRepository::codeHash($accessCode);
            Capsule::table('mod_vpnhood_iap_code_rejections')
                ->where('user_id', $userId)->where('code_hash', $hash)->delete();
            Capsule::table('mod_vpnhood_iap_code_rejections')->insert(
                ['user_id' => $userId, 'code_hash' => $hash, 'refused_at' => date('Y-m-d H:i:s')]);

            // A refusal on something being PAID FOR RIGHT NOW is our fault, not theirs (§4): the
            // account keeps serving it, we never swap in another code, and support fixes the
            // provisioning at the source. Until now the only thing that raised a hand was the
            // customer writing in — so somebody could pay for months and get nothing. Say it where
            // an admin sees it and WHMCS can notify on it. The code itself is never written down.
            if ($this->isPaidNowCode($user, $accessCode)) {
                $clientId = (int) ($user['client_id'] ?? 0);
                logActivity(sprintf(
                    'vpnhoodiap: the access server REFUSED the code of a subscription being paid for '
                    . '(iap user #%d%s). The account keeps serving it; provisioning needs fixing.',
                    $userId, $clientId > 0 ? ", client #$clientId" : ''));
            }
        });
    }

    /**
     * Is this the code of something the person is PAYING FOR RIGHT NOW — a store subscription, or a
     * portal service on live recurring billing? Asked only when a refusal has just been recorded, so
     * the cost (one code read per live service, usually one or none) is paid on the rare path.
     */
    private function isPaidNowCode(array $user, string $accessCode): bool
    {
        $reader = new DeliveryReader();
        $store = $this->storeSubscriptionRow($user);
        if ($store !== null && $reader->readAccessCode((int) $store['service_id']) === $accessCode) {
            return true;
        }
        foreach ($this->visibleServices($user) as $serviceId => $meta) {
            if ($meta['bulk'] || !$this->serviceBilling($serviceId)['paidNow']) {
                continue;
            }
            if ($reader->readAccessCode($serviceId) === $accessCode) {
                return true;
            }
        }
        return false;
    }

    /**
     * Put a refused code back in the ranking (§4) — the client area's Allow again, and the same
     * thing typing the code again does. Reversible in both directions, and it deletes nothing.
     */
    public function clearRejection(array $user, string $accessCode): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        Capsule::table('mod_vpnhood_iap_code_rejections')
            ->where('user_id', $userId)
            ->where('code_hash', IapRepository::codeHash(trim($accessCode)))
            ->delete();
    }

    /**
     * The code fingerprints this account has had refused, each with the ORDER of its refusal — the
     * row id, which only grows. Empty for almost every account, which is what keeps the ranking
     * from having to read a code string per service on the common path.
     *
     * @return array<string, int>
     */
    private function rejectedCodeHashes(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }
        $out = [];
        foreach (Capsule::table('mod_vpnhood_iap_code_rejections')->where('user_id', $userId)
                     ->get(['id', 'code_hash']) as $row) {
            $out[(string) $row->code_hash] = (int) $row->id;
        }
        return $out;
    }

    /**
     * The panel's one inventory control (§3): turn a code off, and the ranking stops offering it on
     * the very next read. Reversible, and it deletes nothing — a device already holding the code
     * keeps it until the code is refused or another is ranked, because the marks steer what is
     * handed out, not what is already held.
     */
    public function setAutoSelectable(array $user, int $serviceId, bool $isAutoSelectable): void
    {
        if (!$this->userCanPoint($user, $serviceId)) {
            throw new ApiException('This account holds no such code.', 404, 'code_not_found');
        }
        $this->pointAt($user, $serviceId);
        $this->claimsFor($user)->where('service_id', $serviceId)
            ->update(['is_auto_selectable' => $isAutoSelectable ? 1 : 0]);
    }

    // ------------------------------------------------------- the ranking --

    /**
     * THE one code the app is handed — or null (§2, lifecycle §8 "the app is told a code, not a
     * list"). No list ever crosses to a device, and nothing here is stored: the winner is computed
     * afresh on every read, so a code that dies leaves no stored selection to repair.
     *
     *   0. the STORE SUBSCRIPTION, this device's own store first.
     *   1. a portal code with live recurring billing. Someone who is paying never does code
     *      management.
     *   2. the other portal codes.
     *   3. the imported code.
     *   4. nothing.
     *
     * Deterministic all the way down: within a group, a started clock outranks one that has not
     * begun — an unused one-time code is worth more unspent — and then oldest purchase first. No
     * expiry sorting, because ordering consumption was never worth the machinery it cost (§2).
     *
     * Skipped, never deleted (§3): is_auto_selectable turned off in the panel, and a code a device
     * reported refused — the latter only when it is not what is being paid for right now. An
     * expired clock skips nothing: expiry is display, and only the access server retires a code.
     *
     * @return array{accessCode: string, expiresAt: ?string}|null
     */
    public function accessCodeInfoForUser(array $user): ?array
    {
        $candidates = $this->rankCandidates($user);
        if ($candidates === []) {
            return null;
        }
        $best = $candidates[0];
        if ($best['serviceId'] === null) {
            return ['accessCode' => $best['accessCode'], 'expiresAt' => null];
        }

        $code = $best['accessCode'] ?? (new DeliveryReader())->readAccessCode($best['serviceId']);
        if ($code === null) {
            // A temporary provisioning/partner outage must not look like an intentional account-wide
            // removal. Fail the refresh so devices retain their last good snapshot and code.
            throw new ApiException('The selected access code is temporarily unavailable.', 503, 'unavailable');
        }
        return ['accessCode' => $code, 'expiresAt' => $best['expiresAt']];
    }

    /**
     * Every eligible code this account holds, best first — INCLUDING the store subscription's, so
     * there is exactly one function that decides what an account serves. Two selectors is how a
     * refused subscription code used to be handed out for ever: the rejection report compared
     * against a different answer than the account read returned, and never matched.
     *
     * @return list<array{serviceId: ?int, accessCode: ?string, expiresAt: ?string, rank: int,
     *                    unstarted: bool, purchasedAt: int, paidNow: bool, refusedOrder: ?int}>
     */
    private function rankCandidates(array $user): array
    {
        $reader = new DeliveryReader();
        // Empty for almost every account. When it IS empty nothing below reads a code string, which
        // matters on a hub install: reading one is a live call to the access manager per service.
        $rejected = $this->rejectedCodeHashes($user);
        $candidates = [];

        // 0. the store subscription, this device's own store first (lifecycle §8) — and ONLY while
        // the store is still charging for it. A subscription we ended is one we control: we know it
        // ended, so it stops being one of this person's codes at that moment. Anything else means
        // handing out a credential that was taken away, and (when its service is gone with it)
        // failing the whole account read over a purchase nobody is paying for.
        $store = $this->storeSubscriptionRow($user);
        if ($store !== null) {
            $expiry = $store['expiry_time'] !== null ? strtotime((string) $store['expiry_time']) : null;
            $candidates[] = [
                'serviceId'   => (int) $store['service_id'],
                'accessCode'  => null,
                'expiresAt'   => $expiry !== null ? gmdate('c', $expiry) : null,
                'rank'        => 0,
                'unstarted'   => false,
                'purchasedAt' => 0,
                'paidNow'     => true, // by construction: an ended subscription is not here at all
            ];
        }
        $storeServiceId = $store === null ? null : (int) $store['service_id'];

        foreach ($this->visibleServices($user) as $serviceId => $meta) {
            if ($meta['bulk'] || !$meta['autoSelectable'] || $serviceId === $storeServiceId) {
                continue; // stock is never a personal code; a code turned off is skipped, not removed
            }
            // The key's own clock is read for ORDER only — an unstarted one-time code is worth more
            // unspent, so it goes last. It is never a verdict: an expired code keeps being offered
            // until the access server refuses it, because a portal clock that could retire a code
            // could also start an unused one early, and only the access server actually knows (§4).
            $state = $reader->readCodeState($serviceId);
            $billing = $this->serviceBilling($serviceId);
            $candidates[] = [
                'serviceId'   => $serviceId,
                'accessCode'  => null,
                'expiresAt'   => $state['expiresAt'],
                'rank'        => $billing['paidNow'] ? 1 : 3,
                'unstarted'   => $state['state'] === 'not-started',
                'purchasedAt' => $billing['purchasedAt'],
                'paidNow'     => $billing['paidNow'],
            ];
        }

        // The imported code: a bearer string with no service behind it, so this install knows
        // nothing about it beyond whether a device has said the access server refused it.
        //
        // It ranks ABOVE every code nobody is being billed for, because somebody typed it in and
        // typing a code is saying "use this one". Preferring what we sold them over what they just
        // asked for would leave them staring at an app that took their code and carried on as
        // before. It stays BELOW anything being paid for right now: a fresh code must never be
        // spent on top of a subscription somebody is already paying for. Whoever wants that anyway
        // signs out — a signed-out device is served by the code typed on it and nothing else.
        $uploaded = $this->uploadedAccessCode($user);
        if ($uploaded !== null) {
            $candidates[] = [
                'serviceId'   => null,
                'accessCode'  => $uploaded,
                'expiresAt'   => null,
                'rank'        => 2,
                'unstarted'   => false,
                'purchasedAt' => PHP_INT_MAX, // never competes on age: it is alone in its group
                'paidNow'     => false,
            ];
        }

        // A REJECTION NEVER SKIPS SOMETHING BEING PAID FOR RIGHT NOW. Downgrading a paying person to
        // a lesser code would hide our own provisioning fault behind a worse credential — and it is
        // what makes renewal work with no extra machinery: a renewed service is paid-now again, so
        // its code is offered again without anybody clearing anything. The rejection is still
        // recorded, and still shows in the client area, so the fault is visible.
        $eligible = [];
        foreach ($candidates as $i => $c) {
            // identical codes are the same credential, so a refusal skips the service delivering it
            // too — not only the upload slot (§4)
            $code = $c['paidNow'] || $rejected === []
                ? null
                : ($c['accessCode'] ?? $reader->readAccessCode((int) $c['serviceId']));
            $candidates[$i]['refusedOrder'] = $code === null
                ? null
                : ($rejected[IapRepository::codeHash($code)] ?? null);
            if ($candidates[$i]['refusedOrder'] === null) {
                $eligible[] = $candidates[$i];
            }
        }

        $byRank = fn (array $c): array => [$c['rank'], $c['unstarted'], $c['purchasedAt'], (int) $c['serviceId']];

        // The ordinary case: the refused ones stand aside and the best working code is served.
        if ($eligible !== []) {
            usort($eligible, fn (array $a, array $b): int => $byRank($a) <=> $byRank($b));
            return $eligible;
        }

        // EVERYTHING THIS ACCOUNT HOLDS HAS BEEN REFUSED — and a refusal DEMOTES a code, it never
        // takes it away (§4). Answering "you hold nothing" would be untrue: the person does hold
        // them, the device that met the refusal keeps its code either way (keyring plan §8), and a
        // second device must not be told a different story than the first.
        //
        // So they take turns, LEAST RECENTLY REFUSED FIRST. Whichever code comes back to life —
        // topped up, extended by support, renewed somewhere we cannot see — is tried again on its
        // own within one turn of the keyring, with nothing to press and nothing to remember. The
        // order is the refusal's own row id, which only grows: no clock, and no two refusals a
        // second apart that a timestamp could not tell apart.
        //
        // Nothing we ENDED comes back this way. A subscription past its paid time, a code switched
        // off in the panel and a dead service never became candidates at all, so there is nothing
        // here to rotate; only a refusal, which is somebody else's verdict about a code the person
        // still holds, is softened. A subscription still being paid for is never demoted to begin
        // with, so it never reaches this at all.
        usort($candidates, fn (array $a, array $b): int
            => [$a['refusedOrder'], $byRank($a)] <=> [$b['refusedOrder'], $byRank($b)]);
        return $candidates;
    }

    /**
     * The store subscription SERVING this account, or null — this device's own store first
     * (lifecycle §8). One account can hold a subscription in more than one store, and only the
     * store that sold one can manage, renew or cancel it: handing an Android device an Apple
     * subscription would hide its Google one and leave both unmanageable.
     *
     * A SUBSCRIPTION THAT HAS ENDED IS NOT HERE. We are the ones who ended it, so there is nothing
     * to work out and nothing to wait for: the moment the paid time is over, its code stops being
     * one of this person's codes. Their devices then fall through to whatever they actually hold —
     * a code they bought on the website, a code a friend gave them — or are told plainly that they
     * have nothing. It is worked out fresh on every read, never stamped on the code, because the
     * same code may come back from the store the day they subscribe again.
     *
     * @return array<string, mixed>|null the purchase row
     */
    public function storeSubscriptionRow(array $user): ?array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        // The statuses that can still be charging are asked of the DATABASE, not of a page of recent
        // rows. Reading the newest few and discarding the ended ones afterwards meant a long buying
        // history — finished and refunded purchases piled on top — could push somebody's LIVE
        // subscription out of sight, and a paying subscriber would quietly stop being premium while
        // the store kept billing them. The remaining cap is a sanity bound, not a filter: nobody
        // holds this many live subscriptions, and the expensive call (reading a code) happens once,
        // for the row that wins.
        $rows = Capsule::table('mod_vpnhood_iap_purchases')
            ->where('user_id', $userId)
            ->whereNotNull('service_id')
            ->whereIn('status', ['provisioned', 'canceled'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()->map(fn ($row) => (array) $row)->all();

        // the expiry stays a PHP decision: the status is the store's word about its own billing, and
        // whether that word has run out is a clock comparison this install owns
        $rows = array_values(array_filter($rows, fn (array $row) => self::isStoreStillCharging($row)));
        $homeStore = $user['session_store'] ?? null;
        if ($homeStore !== null) {
            usort($rows, fn ($a, $b) => ((string) $b['store'] === $homeStore ? 1 : 0)
                <=> ((string) $a['store'] === $homeStore ? 1 : 0));
        }
        return $rows[0] ?? null;
    }

    /**
     * Is the STORE still charging for this purchase? Its own vocabulary, not ours: `provisioned` is
     * live, and `canceled` means auto-renew was turned off while the period ALREADY PAID FOR runs
     * on — that time belongs to the person and is never taken back early. `on_hold` (payment
     * problem), `expired`, `refunded`, `failed` and `pending` are not being paid for.
     *
     * The expiry the store gave us is honoured, which is also what saves us when a store
     * notification never arrives: a row still marked live but long past its date fails here anyway.
     *
     * @param array<string, mixed> $row
     */
    private static function isStoreStillCharging(array $row): bool
    {
        if (!in_array((string) ($row['status'] ?? ''), ['provisioned', 'canceled'], true)) {
            return false;
        }
        $expiry = $row['expiry_time'] ?? null;
        return $expiry === null || strtotime((string) $expiry) > time();
    }

    /**
     * What this install knows about a service's billing: whether it is being PAID FOR right now — a
     * live recurring service, active, on a repeating cycle, with a next due date still ahead — and
     * when it was bought, which is the stable tie-break inside a group. A one-time purchase is not
     * being paid for: it was paid for once and is now just a code.
     *
     * @return array{paidNow: bool, purchasedAt: int}
     */
    private function serviceBilling(int $serviceId): array
    {
        $row = Capsule::table('tblhosting')->where('id', $serviceId)
            ->first(['domainstatus', 'billingcycle', 'nextduedate', 'regdate']);
        $purchasedAt = $row === null ? PHP_INT_MAX : (strtotime((string) $row->regdate) ?: PHP_INT_MAX);
        if ($row === null || (string) $row->domainstatus !== 'Active') {
            return ['paidNow' => false, 'purchasedAt' => $purchasedAt];
        }
        $cycle = strtolower(trim((string) $row->billingcycle));
        if ($cycle === '' || $cycle === 'one time' || $cycle === 'onetime' || $cycle === 'free account') {
            return ['paidNow' => false, 'purchasedAt' => $purchasedAt];
        }
        $nextDue = (string) $row->nextduedate;
        $paidNow = $nextDue !== '' && $nextDue !== '0000-00-00' && strtotime($nextDue) >= strtotime('today');
        return ['paidNow' => $paidNow, 'purchasedAt' => $purchasedAt];
    }

    /** The account's one upload slot, as stored. */
    public function uploadedAccessCode(array $user): ?string
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        $value = Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->value('uploaded_access_code');
        $value = $value === null ? null : trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** Has a device reported the access server refusing this code for THIS account? */
    public function isRejected(array $user, string $accessCode): bool
    {
        return isset($this->rejectedCodeHashes($user)[IapRepository::codeHash(trim($accessCode))]);
    }

    // ----------------------------------------------------- internal state --

    /**
     * The claim row is now only where is_auto_selectable lives — the ranking needs no pointer to
     * find a code, so a row exists for a service the panel has an opinion about, and its absence
     * simply means the default: auto-selectable, like every ordinary code (§3).
     *
     * @return bool true when a new claim row was created.
     */
    private function pointAt(array $user, int $serviceId): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $clientId = $this->clientIdOf($user);
        $existing = $this->claimsFor($user)->where('service_id', $serviceId)->first();
        if ($existing !== null) {
            return false;
        }
        Capsule::table('mod_vpnhood_iap_claims')->insert([
            'user_id'            => $userId > 0 ? $userId : null,
            'client_id'          => $userId > 0 ? null : $clientId,
            'service_id'         => $serviceId,
            'is_default'         => 0,
            'is_auto_selectable' => 1,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    private function serviceBelongsToUser(array $user, int $serviceId): bool
    {
        $clientId = $this->clientIdOf($user);
        return $clientId !== null
            && (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid') === $clientId;
    }

    /**
     * Serialize every slot mutation for this identity. Both app sessions and
     * the client-area stand-in converge on the linked WHMCS client row, then the
     * module-user row is locked as a second stable anchor. All callers take the
     * locks in this order.
     */
    private function withIdentityLock(array $user, callable $callback): mixed
    {
        return Capsule::connection()->transaction(function () use ($user, $callback) {
            $clientId = $this->clientIdOf($user);
            if ($clientId !== null) {
                Capsule::table('tblclients')->where('id', $clientId)->lockForUpdate()->first(['id']);
            }
            $userId = (int) ($user['id'] ?? 0);
            if ($userId > 0) {
                Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->lockForUpdate()->first(['id']);
            }
            return $callback();
        });
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
     * The uploaded code is listed too, as a row with NO service behind it (§10 "panel rows for codes
     * with no service behind them"): it is a bearer string the account holds, not a purchase, so it
     * has no billing and no expiry the portal knows. Its controls are Remove and — when a device has
     * reported the access server refusing it — Allow again, which is the client-area half of Retry
     * (§4).
     *
     * @return array<int, array{accessCode: string, expiresAt: ?string, isAutoSelectable: bool, serviceId: ?int, uploaded: bool, rejected: bool}>
     */
    public function webKeysForUser(array $user): array
    {
        $reader = new DeliveryReader();
        $rejected = $this->rejectedCodeHashes($user);
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
                'accessCode'       => $code,
                'expiresAt'        => $state['expiresAt'],
                'isAutoSelectable' => $meta['autoSelectable'],
                'serviceId'        => $serviceId,
                'uploaded'         => false,
                'rejected'         => isset($rejected[IapRepository::codeHash($code)]),
            ];
        }

        $uploaded = $this->uploadedAccessCode($user);
        if ($uploaded !== null) {
            $items[] = [
                'accessCode'       => $uploaded,
                'expiresAt'        => null,   // nothing behind it to ask, and nothing reports one (§4)
                'isAutoSelectable' => true,   // the slot has no off switch — emptying it IS the off switch
                'serviceId'        => null,
                'uploaded'         => true,
                'rejected'         => isset($rejected[IapRepository::codeHash($uploaded)]),
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
     * The service ids this account can see, with per-service flags. autoSelectable defaults to TRUE
     * and only a claim row can turn it off (§3), so a code nobody has an opinion about is eligible
     * without anyone deciding anything.
     *
     * @return array<int, array{source: string, autoSelectable: bool, bulk: bool}>
     */
    private function visibleServices(array $user): array
    {
        $out = [];
        $clientId = $this->clientIdOf($user);
        if ($clientId !== null) {
            foreach ($this->clientKeyServices($clientId) as $serviceId => $flags) {
                $out[$serviceId] = ['source' => 'website', 'autoSelectable' => true] + $flags;
            }
        }
        foreach ($this->claimsFor($user)->get() as $claim) {
            $serviceId = (int) $claim->service_id;
            if (!isset($out[$serviceId])) {
                $live = Capsule::table('tblhosting')->where('id', $serviceId)
                    ->whereIn('domainstatus', self::LiveServiceStatuses)->exists();
                if (!$live) {
                    continue;
                }
                $out[$serviceId] = ['source' => 'claimed', 'autoSelectable' => true,
                    'bulk' => IapRepository::serviceProperty($serviceId, 'bulkDelivery') === 'yes'];
            }
            // the column is absent on an install that has not migrated yet: absent = the default
            $out[$serviceId]['autoSelectable'] = !property_exists($claim, 'is_auto_selectable')
                || (bool) $claim->is_auto_selectable;
        }
        return $out;
    }

    /**
     * The client's own code-bearing services (any VpnHood provisioning module —
     * they all store accessTokenId or accessCode), stock excluded from ever
     * being "a code of theirs" but still listed as a delivery.
     *
     * The isDefaultKey service property is deliberately NOT read: there is no stored selection any
     * more (§2), so a mark left on a service by an older provisioning run means nothing. The
     * ranking recomputes on every read instead.
     *
     * @return array<int, array{bulk: bool}>
     */
    private function clientKeyServices(int $clientId): array
    {
        $rows = Capsule::table('tblhosting as h')
            ->join('tblcustomfieldsvalues as v', 'v.relid', '=', 'h.id')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('h.userid', $clientId)
            ->whereIn('h.domainstatus', self::LiveServiceStatuses)
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
                'bulk' => IapRepository::serviceProperty($serviceId, 'bulkDelivery') === 'yes',
            ];
        }
        return $out;
    }
}
