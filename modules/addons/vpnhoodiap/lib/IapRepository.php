<?php

namespace WHMCS\Module\Addon\VpnHoodIap;

use WHMCS\Database\Capsule;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Data access for the mod_vpnhood_iap_* tables plus addon settings and secrets.
 *
 * Write rule (house rule shared with vpnhoodpartnerhub): orders/invoices/services are
 * only ever touched through localAPI (by the provisioning services, not here); Capsule
 * writes are restricted to this module's own tables. WHMCS core tables are read-only.
 */
class IapRepository
{
    /**
     * Store ids an app row may carry. `web` is the odd one and deliberate: a direct-download
     * build (our own site, a sideloaded APK) belongs to no store, but its package still has to
     * be registered here or sign-in answers `unknown_app`. Such a row holds no credentials and
     * no adapter, so it can never validate a purchase or receive a webhook — registration, and
     * nothing else. It is an install-side label only: `web` is never a storeId on the wire.
     */
    public const STORES = ['googleplay', 'appstore', 'microsoft', 'web'];
    public const MODULE = 'vpnhoodiap';

    /** @throws \RuntimeException when the store id is unknown */
    public static function assertStore(string $store): string
    {
        if (!in_array($store, self::STORES, true)) {
            throw new \RuntimeException("Unknown store: $store");
        }
        return $store;
    }

    /**
     * Whether the addon is activated on this install. The public endpoints fail closed
     * (404) when it is not — shipped-but-unactivated code must expose nothing.
     */
    public static function isModuleActive(): bool
    {
        return Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->exists();
    }

    /** Read one addon setting (tbladdonmodules), '' when unset. */
    public function setting(string $name): string
    {
        $value = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->where('setting', $name)
            ->value('value');
        return $value === null ? '' : (string) $value;
    }

    // -- secrets ------------------------------------------------------------

    /** Encrypt a secret for storage via WHMCS's own mechanism. */
    public function encryptSecret(string $plain): string
    {
        $result = localAPI('EncryptPassword', ['password2' => $plain]);
        $encrypted = (string) ($result['password'] ?? '');
        if ($encrypted === '') {
            throw new \RuntimeException('Could not encrypt the credential.');
        }
        return $encrypted;
    }

    /**
     * Decrypt a stored secret, tolerating plaintext storage. DecryptPassword does not fail
     * on a value that was never encrypted — it returns binary garbage, so the decrypted
     * value is only trusted when printable. (Ported from VpnHood.WHMCS.Partner HubClient.)
     */
    public function decryptSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            $result = localAPI('DecryptPassword', ['password2' => $value]);
            // WHMCS HTML-escapes API output, credentials included: a JSON secret comes
            // back with &quot; for every quote and fails to parse. Undo it — a real
            // secret never contains literal HTML entities, a JSON one cannot.
            $plain = html_entity_decode((string) ($result['password'] ?? ''), ENT_QUOTES | ENT_HTML5);
            if ($plain !== '' && !preg_match('/[^\x20-\x7E\r\n\t]/', $plain)) {
                return $plain;
            }
        } catch (\Throwable $e) {
            // fall back to the raw value below
        }
        return $value;
    }

    // -- apps ---------------------------------------------------------------

    /** @return array<int,array> */
    public function allApps(): array
    {
        return Capsule::table('mod_vpnhood_iap_apps')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
    }

    public function getApp(int $id): ?array
    {
        $row = Capsule::table('mod_vpnhood_iap_apps')->find($id);
        return $row === null ? null : (array) $row;
    }

    public function findAppByWebhookToken(string $store, string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $row = Capsule::table('mod_vpnhood_iap_apps')
            ->where('store', $store)
            ->where('webhook_token', $token)
            ->where('status', 'active')
            ->first();
        return $row === null ? null : (array) $row;
    }

    public function findAppByPackageName(string $store, string $packageName): ?array
    {
        $row = Capsule::table('mod_vpnhood_iap_apps')
            ->where('store', $store)
            ->where('package_name', $packageName)
            ->where('status', 'active')
            ->first();
        return $row === null ? null : (array) $row;
    }

    /**
     * Sign-in requests carry only the package/bundle name (the store is not
     * known yet at sign-in time); package names are unique across stores in
     * practice, and the unique (store, package_name) index bounds this to one
     * row per store.
     */
    public function findAppByPackageAnyStore(string $packageName): ?array
    {
        $row = Capsule::table('mod_vpnhood_iap_apps')
            ->where('package_name', $packageName)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
        return $row === null ? null : (array) $row;
    }

    public function createApp(array $data): array
    {
        $data['webhook_token'] = bin2hex(random_bytes(24));
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        $id = Capsule::table('mod_vpnhood_iap_apps')->insertGetId($data);
        $app = $this->getApp((int) $id);
        if ($app === null) {
            throw new \RuntimeException('App row disappeared right after insert.');
        }
        return $app;
    }

    public function updateApp(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table('mod_vpnhood_iap_apps')->where('id', $id)->update($data);
    }

    /** Removes the app and its catalog mappings. Purchase history is deliberately kept. */
    public function deleteApp(int $id): void
    {
        Capsule::table('mod_vpnhood_iap_products')->where('app_id', $id)->delete();
        Capsule::table('mod_vpnhood_iap_apps')->where('id', $id)->delete();
    }

    /** Public webhook URL for an app row (secret path token included). */
    public function webhookUrl(array $app): string
    {
        return $this->systemUrl() . '/modules/addons/vpnhoodiap/webhook.php?store='
            . rawurlencode((string) $app['store']) . '&t=' . rawurlencode((string) $app['webhook_token']);
    }

    /** Base URL of the Portal API — what an app is configured to talk to. */
    public function portalApiUrl(): string
    {
        return $this->systemUrl() . '/modules/addons/vpnhoodiap/api.php';
    }

    private function systemUrl(): string
    {
        return rtrim((string) Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')->value('value'), '/');
    }

    /**
     * The portal's host, used as the restore-credential relying-party id. One
     * value per install, stable across requests — derived from SystemURL, never
     * from request headers, so a spoofed Host can not mint a parallel rp.
     */
    public function portalHost(): string
    {
        $host = parse_url($this->systemUrl(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('SystemURL carries no host.');
        }
        return strtolower($host);
    }

    // -- catalog ------------------------------------------------------------

    /** @return array<int,array> joined with app + product names for the admin UI */
    public function allProductMappings(): array
    {
        return Capsule::table('mod_vpnhood_iap_products as m')
            ->leftJoin('mod_vpnhood_iap_apps as a', 'a.id', '=', 'm.app_id')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'm.whmcs_product_id')
            ->orderBy('m.id')
            ->get(['m.*', 'a.package_name', 'a.store', 'p.name as product_name'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function addProductMapping(array $data): void
    {
        $exists = Capsule::table('mod_vpnhood_iap_products')
            ->where('app_id', $data['app_id'])
            ->where('store_product_id', $data['store_product_id'])
            ->where('store_base_plan_id', $data['store_base_plan_id'] ?? '')
            ->exists();
        if ($exists) {
            throw new \RuntimeException('That store product is already mapped for this app.');
        }
        Capsule::table('mod_vpnhood_iap_products')->insert($data);
    }

    public function deleteProductMapping(int $id): void
    {
        Capsule::table('mod_vpnhood_iap_products')->where('id', $id)->delete();
    }

    /** Enabled mappings for one app + store SKU (a bundle SKU may return several rows). */
    public function findMappings(int $appId, string $storeProductId, string $basePlanId): array
    {
        return Capsule::table('mod_vpnhood_iap_products')
            ->where('app_id', $appId)
            ->where('store_product_id', $storeProductId)
            ->where('store_base_plan_id', $basePlanId)
            ->where('enabled', 1)
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    /** WHMCS products for the admin dropdown (read-only core access). */
    public function whmcsProducts(): array
    {
        return Capsule::table('tblproducts')->orderBy('id')
            ->get(['id', 'name', 'paytype'])->map(fn ($row) => (array) $row)->all();
    }

    /**
     * The provisioned service's recurrence as an ISO-8601 duration ('P1M', 'P1Y', …),
     * or null for a one-off or unrecognised cycle.
     *
     * Read from the service WHMCS actually created rather than from the store: that
     * service is what the order was placed against, and it spares the entitlement
     * endpoints a round trip to the store API. The duration form keeps a WHMCS cycle
     * name off the wire, and it is already the vocabulary the app speaks for store
     * plan periods.
     */
    public static function billingPeriodForService(int $serviceId): ?string
    {
        $cycle = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('billingcycle');
        return [
            'Monthly'       => 'P1M',
            'Quarterly'     => 'P3M',
            'Semi-Annually' => 'P6M',
            'Annually'      => 'P1Y',
            'Biennially'    => 'P2Y',
            'Triennially'   => 'P3Y',
        ][$cycle] ?? null;
    }

    // -- users --------------------------------------------------------------

    /** RFC 4122 v4 UUID — the external_uid format (Apple appAccountToken requires UUID). */
    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function getUser(int $id): ?array
    {
        $row = Capsule::table('mod_vpnhood_iap_users')->find($id);
        return $row === null ? null : (array) $row;
    }

    public function getUserByExternalUid(string $externalUid): ?array
    {
        $row = Capsule::table('mod_vpnhood_iap_users')->where('external_uid', $externalUid)->first();
        return $row === null ? null : (array) $row;
    }

    /**
     * The account for an address. Ordered by id so that an install whose unique index
     * could not be applied (pre-existing duplicates) still resolves to one stable row
     * — the oldest, which is the one purchases were provisioned against.
     */
    public function findUserByEmail(string $email): ?array
    {
        $row = Capsule::table('mod_vpnhood_iap_users')
            ->where('email', self::normalizeEmail($email))
            ->orderBy('id')
            ->first();
        return $row === null ? null : (array) $row;
    }

    /**
     * Accounts whose SIGN-IN METHODS currently report this address, oldest first.
     * Resolution matches against these, never against the account row's own email:
     * that one is a contact snapshot, and a snapshot goes stale — an address the
     * owner abandoned (and an employer may since have handed to someone else) must
     * stop opening the account the moment no sign-in method carries it any more.
     */
    public function findUsersByIdentityEmail(string $email): array
    {
        $userIds = Capsule::table('mod_vpnhood_iap_identities')
            ->where('email', self::normalizeEmail($email))
            ->pluck('user_id')->unique()->values()->all();
        if ($userIds === []) {
            return [];
        }
        return Capsule::table('mod_vpnhood_iap_users')
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Read a WHMCS service property (the hidden per-product custom-field
     * mechanism serviceProperties uses) for ANY service — module code can only
     * reach its own service's properties through the model, and the account/key
     * features need to read other services'. Read-only, ever.
     */
    public static function serviceProperty(int $serviceId, string $name): ?string
    {
        $row = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('v.relid', $serviceId)
            ->where('f.type', 'product')
            ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = ?", [strtolower($name)])
            ->first(['v.value']);
        $value = $row?->value;
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * The one canonical hash of an access code (claim-by-code lookups). The hub
     * stores only this — never the code — so its "codes are not persisted"
     * stance survives the feature; a hash opens nothing.
     */
    public static function codeHash(string $accessCode): string
    {
        return hash('sha256', trim($accessCode));
    }

    /**
     * An access code's SHAPE, and nothing about whether it exists — the same rule the apps apply
     * before they send one (AccessCodeUtils): separators are ignored, version 1 is 20 digits, and
     * the second digit is a checksum over the other eighteen.
     *
     * Shape is not validity. The portal must never be an authority on whether a code works — that
     * is the access server's answer, given at use time (§5) — but it must not store a string the
     * access server could not possibly accept, and the slot column is 64 characters wide.
     *
     * @return string|null the normalized code, or null when the string is not one
     */
    public static function normalizeAccessCode(string $accessCode): ?string
    {
        // separators are decoration; the app strips them the same way before validating
        $code = preg_replace('/[^a-zA-Z0-9]/', '', trim($accessCode)) ?? '';
        if (strlen($code) !== 20 || !ctype_alnum($code)) {
            return null;
        }
        // version 1, then one checksum DIGIT, then eighteen alphanumerics. The random part is not
        // digits-only — AccessCodeUtils sums character codes, so letters are legal there and a
        // stricter rule here would refuse codes the apps accept.
        if ($code[0] !== '1' || !ctype_digit($code[1])) {
            return null;
        }
        return (int) $code[1] === self::accessCodeChecksum(substr($code, 2)) ? $code : null;
    }

    /** Sum the character codes, then fold to one digit — AccessCodeUtils.CalculateChecksum. */
    private static function accessCodeChecksum(string $input): int
    {
        $sum = 0;
        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $sum += ord($input[$i]);
        }
        while ($sum >= 10) {
            $sum = array_sum(str_split((string) $sum));
        }
        return $sum;
    }

    /** Case and surrounding space never distinguish two people. */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Resolve a verified sign-in to THE account — the person — creating it when new.
     *
     *   1. A known identity (provider, subject) always wins. This is what keeps an
     *      account stable when the provider changes its email address.
     *   2. A NEW identity joins an existing account only when its verified email
     *      matches an address one of that account's sign-in methods CURRENTLY
     *      reports: Google today, Apple or a password login tomorrow, same address,
     *      same account, same external_uid — so a purchase made under one provider
     *      is still bound (the stores echo external_uid back) after signing in with
     *      another. Matching is against the identities, never the account row's
     *      email: that is a contact snapshot, and a stale snapshot is how a
     *      recycled work address would open its previous owner's account. Only
     *      safe because the caller has already rejected sign-ins the provider did
     *      not mark email_verified — an unverified address would let anyone claim
     *      someone else's account by naming it. The owner is told whenever a
     *      sign-in method is added: a silent join is what turns a mistaken or
     *      hostile link into a quiet takeover.
     *   3. An address that several accounts currently answer to joins NONE of
     *      them (split accounts from the pre-email era): guessing would hand one
     *      person's purchases to another. A fresh account is created and the
     *      collision reported loudly; merging is a human decision.
     *   4. Otherwise this is a new person: account + first linked identity.
     *
     * The account row's email mirrors the most recent sign-in, like
     * provider/provider_subject — contact and display only, never resolution.
     */
    public function findOrCreateUser(string $provider, string $subject, string $email, bool $emailVerifiedClaim, ?string $displayName): array
    {
        $now = date('Y-m-d H:i:s');
        $email = self::normalizeEmail($email);
        $displayName = trim((string) $displayName) === '' ? null : trim((string) $displayName);

        // -- 1. known identity
        $identity = Capsule::table('mod_vpnhood_iap_identities')
            ->where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();
        if ($identity !== null) {
            $user = $this->getUser((int) $identity->user_id);
            if ($user === null) {
                throw new \RuntimeException("Identity #{$identity->id} points at a missing user row.");
            }
            if ((string) $identity->email !== $email) {
                Capsule::table('mod_vpnhood_iap_identities')
                    ->where('id', $identity->id)
                    ->update(['email' => $email, 'updated_at' => $now]);
            }
            return $this->recordSignIn($user, $provider, $subject, $email, $emailVerifiedClaim, $displayName, $now);
        }

        // -- 2. new identity, address currently reported by exactly one account
        $candidates = $this->findUsersByIdentityEmail($email);
        if (count($candidates) === 1) {
            $user = $candidates[0];
            $this->linkIdentity((int) $user['id'], $provider, $subject, $email, $now);
            $this->notifyNewSignInMethod($user, $provider);
            return $this->recordSignIn($user, $provider, $subject, $email, $emailVerifiedClaim, $displayName, $now);
        }

        // -- 3. several accounts answer to this address: join none, say so loudly
        if (count($candidates) > 1) {
            $ids = implode(', #', array_map(fn ($candidate) => $candidate['id'], $candidates));
            $this->alertAdmins("vpnhoodiap: sign-in address {$email} is currently reported by the sign-in methods of "
                . count($candidates) . " accounts (#{$ids}); linked none — a new account was created. Merge by hand.");
        }

        // -- 4. new person
        return $this->createUserWithIdentity($provider, $subject, $email, $emailVerifiedClaim, $displayName, null, $now);
    }

    /**
     * Join-only resolution for the password grant (lifecycle: that grant never
     * creates an account — this method only ever finds one that exists):
     *
     *   1. the account already carrying this 'whmcs' identity;
     *   2. the ONE account bound to a client this login owns — ownership of the
     *      client is a stronger proof than any email match;
     *   3. the ONE account whose sign-in methods currently report this email —
     *      only when WHMCS itself verified the address (`$emailVerified`); an
     *      unverified address must never join, or squatting a victim's email in
     *      a WHMCS signup would open their app account.
     *
     * Ambiguity on the email (rule 3 of findOrCreateUser) joins none and falls
     * through to null; ambiguity on the CLIENT link throws, because the caller
     * must refuse rather than guess or create a further account on that client.
     *
     * @param array<int,int> $ownedClientIds
     * @return ?array the account, or null when nothing matches
     * @throws \RuntimeException when several accounts sit on this login's own clients
     */
    public function findUserForWhmcsSignIn(string $subject, array $ownedClientIds, string $email, bool $emailVerified, ?string $displayName): ?array
    {
        $now = date('Y-m-d H:i:s');
        $email = self::normalizeEmail($email);
        $displayName = trim((string) $displayName) === '' ? null : trim((string) $displayName);
        $provider = 'whmcs';

        // -- 1. known identity
        $identity = Capsule::table('mod_vpnhood_iap_identities')
            ->where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();
        if ($identity !== null) {
            $user = $this->getUser((int) $identity->user_id);
            if ($user === null) {
                throw new \RuntimeException("Identity #{$identity->id} points at a missing user row.");
            }
            if ((string) $identity->email !== $email) {
                Capsule::table('mod_vpnhood_iap_identities')
                    ->where('id', $identity->id)
                    ->update(['email' => $email, 'updated_at' => $now]);
            }
            return $this->recordSignIn($user, $provider, $subject, $email, $emailVerified, $displayName, $now);
        }

        // -- 2. the account bound to a client this login owns
        if ($ownedClientIds !== []) {
            $rows = Capsule::table('mod_vpnhood_iap_users')
                ->whereIn('client_id', $ownedClientIds)
                ->get();
            if (count($rows) === 1) {
                $user = (array) $rows->first();
                $this->linkIdentity((int) $user['id'], $provider, $subject, $email, $now);
                $this->notifyNewSignInMethod($user, $provider);
                return $this->recordSignIn($user, $provider, $subject, $email, $emailVerified, $displayName, $now);
            }
            if (count($rows) > 1) {
                $ids = implode(', #', $rows->pluck('id')->all());
                $this->alertAdmins("vpnhoodiap: WHMCS user {$subject} ({$email}) signed in with a password, but "
                    . count($rows) . " app accounts (#{$ids}) sit on clients that login owns; refused — merge by hand.");
                throw new \RuntimeException('Several app accounts on this login\'s own clients.');
            }
        }

        // -- 3. verified-email join, same rules as findOrCreateUser but never creating
        if ($emailVerified) {
            $candidates = $this->findUsersByIdentityEmail($email);
            if (count($candidates) === 1) {
                $user = $candidates[0];
                $this->linkIdentity((int) $user['id'], $provider, $subject, $email, $now);
                $this->notifyNewSignInMethod($user, $provider);
                return $this->recordSignIn($user, $provider, $subject, $email, $emailVerified, $displayName, $now);
            }
            if (count($candidates) > 1) {
                $ids = implode(', #', array_map(fn ($candidate) => $candidate['id'], $candidates));
                $this->alertAdmins("vpnhoodiap: password sign-in address {$email} is currently reported by the "
                    . 'sign-in methods of ' . count($candidates) . " accounts (#{$ids}); joined none. Merge by hand.");
            }
        }
        return null;
    }

    /**
     * The app-side row for an EXISTING WHMCS client, for the password grant's
     * pure-web-customer case: bound to the client from birth, so this is not a
     * new account — the client is the account, this row is its handle here.
     */
    public function createUserForWhmcsClient(string $subject, string $email, bool $emailVerified, ?string $displayName, int $clientId): array
    {
        $now = date('Y-m-d H:i:s');
        $email = self::normalizeEmail($email);
        $displayName = trim((string) $displayName) === '' ? null : trim((string) $displayName);
        return $this->createUserWithIdentity('whmcs', $subject, $email, $emailVerified, $displayName, $clientId, $now);
    }

    /** Account row + first linked identity, with the concurrent-race handling both creators need. */
    private function createUserWithIdentity(string $provider, string $subject, string $email, bool $emailVerifiedClaim, ?string $displayName, ?int $clientId, string $now): array
    {
        try {
            $id = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
                'provider'             => $provider,
                'provider_subject'     => $subject,
                'email'                => $email,
                'display_name'         => $displayName,
                'email_verified_claim' => $emailVerifiedClaim ? 1 : 0,
                'client_id'            => $clientId,
                'external_uid'         => self::uuidV4(),
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        } catch (\Throwable $e) {
            // only the pre-identity unique(email) index can reject this insert; it
            // must be gone before resolution is safe, so fail loudly with the cure
            throw new \RuntimeException(
                'vpnhoodiap: users.email still carries the pre-identity unique index — open the addon page so _upgrade() runs.',
                0, $e);
        }
        if (!$this->linkIdentity($id, $provider, $subject, $email, $now)) {
            // lost a concurrent race for this same identity: the winner's account is
            // the account. Drop the row just created for nothing and use theirs.
            Capsule::table('mod_vpnhood_iap_users')->where('id', $id)->delete();
            $identity = Capsule::table('mod_vpnhood_iap_identities')
                ->where('provider', $provider)
                ->where('provider_subject', $subject)
                ->first();
            $user = $identity === null ? null : $this->getUser((int) $identity->user_id);
            if ($user === null) {
                throw new \RuntimeException('Lost the identity race but the winning identity is gone.');
            }
            return $this->recordSignIn($user, $provider, $subject, $email, $emailVerifiedClaim, $displayName, $now);
        }
        $user = $this->getUser($id);
        if ($user === null) {
            throw new \RuntimeException('User row disappeared right after insert.');
        }
        return $user;
    }

    /** Mirror the sign-in onto the account row (contact and display only — never resolution). */
    private function recordSignIn(array $user, string $provider, string $subject, string $email, bool $emailVerifiedClaim, ?string $displayName, string $now): array
    {
        $update = [];
        if ($user['provider'] !== $provider || $user['provider_subject'] !== $subject) {
            $update = ['provider' => $provider, 'provider_subject' => $subject];
        }
        // keep the contact address at the one the person actually signs in with, so
        // notices reach a live mailbox instead of a years-old snapshot
        if ((string) $user['email'] !== $email) {
            $update['email'] = $email;
        }
        if ((bool) $user['email_verified_claim'] !== $emailVerifiedClaim) {
            $update['email_verified_claim'] = $emailVerifiedClaim ? 1 : 0;
        }
        // the IdP is the source of truth for the person's name; a sign-in that
        // carries none (Apple after the first) leaves the last known name alone
        if ($displayName !== null && ($user['display_name'] ?? null) !== $displayName) {
            $update['display_name'] = $displayName;
        }
        if ($update !== []) {
            $update['updated_at'] = $now;
            Capsule::table('mod_vpnhood_iap_users')->where('id', $user['id'])->update($update);
            $user = array_merge($user, $update);
        }
        return $user;
    }

    /** Attach a sign-in proof to an account. False = lost the unique-insert race (the identity already exists). */
    private function linkIdentity(int $userId, string $provider, string $subject, string $email, string $now): bool
    {
        try {
            Capsule::table('mod_vpnhood_iap_identities')->insert([
                'user_id'          => $userId,
                'provider'         => $provider,
                'provider_subject' => $subject,
                'email'            => $email,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            return true;
        } catch (\Throwable) {
            // unique (provider, subject) already present — a concurrent request linked it
            return false;
        }
    }

    /**
     * Tell the owner a sign-in method was added: a silent join is what turns a
     * mistaken or hostile link into a quiet takeover, and this mail is the one
     * trace the rightful owner would ever see. Best-effort — mail must never break
     * sign-in — and an account that never bought has no client to write to (and
     * nothing worth taking). The log row is the durable trace either way.
     */
    private function notifyNewSignInMethod(array $user, string $provider): void
    {
        $this->log((int) $user['id'], 'identity_linked', '', 0, null, $provider);
        if (empty($user['client_id']) || !function_exists('localAPI')) {
            return;
        }
        try {
            localAPI('SendEmail', [
                'id'            => (int) $user['client_id'],
                'customtype'    => 'general',
                'customsubject' => 'A new sign-in method was added to your account',
                'custommessage' => '<p>A new way of signing in (' . htmlspecialchars($provider, ENT_QUOTES)
                    . ') was just added to your VpnHood account.</p>'
                    . '<p>If this was you, there is nothing to do. If it was not, contact support immediately.</p>',
            ]);
        } catch (\Throwable) {
            // tolerated — see above
        }
    }

    /** Loud ops: system activity log + module log (same channels as the redeem pipeline's alerts). */
    private function alertAdmins(string $message): void
    {
        try {
            if (function_exists('localAPI')) {
                localAPI('LogActivity', ['description' => $message]);
            }
        } catch (\Throwable) {
            // the alert must never take sign-in down
        }
        $this->log(null, 'alert', '', 0, null, $message);
    }

    /** All sign-in proofs attached to an account, oldest first. */
    public function identitiesForUser(int $userId): array
    {
        return Capsule::table('mod_vpnhood_iap_identities')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    public function linkUserClient(int $userId, int $clientId): void
    {
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->update([
            'client_id'  => $clientId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Mark this account's client area as closed until WHMCS confirms the email.
     * Set when a purchase attaches to a WHMCS client that already existed — the
     * store proved the buyer, but nothing yet proves the pre-existing client
     * record is the same person. The flag alone decides nothing: the gate hook
     * also re-reads WHMCS's own verification state, so confirming the address
     * opens the client area whether or not the flag is ever cleared.
     */
    public function requireEmailVerification(int $userId): void
    {
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->update([
            'requires_email_verification' => 1,
            'updated_at'                  => date('Y-m-d H:i:s'),
        ]);
    }

    /** The gated accounts for a WHMCS client, if any. Used by the client-area gate hook. */
    public function clientRequiresEmailVerification(int $clientId): bool
    {
        return Capsule::table('mod_vpnhood_iap_users')
            ->where('client_id', $clientId)
            ->where('requires_email_verification', 1)
            ->exists();
    }

    // -- monitors -----------------------------------------------------------

    public function recentPurchases(int $limit): array
    {
        return Capsule::table('mod_vpnhood_iap_purchases')->orderByDesc('id')->limit($limit)
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    public function recentEvents(int $limit): array
    {
        return Capsule::table('mod_vpnhood_iap_events')->orderByDesc('id')->limit($limit)
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    public function recentLog(int $limit): array
    {
        return Capsule::table('mod_vpnhood_iap_log')->orderByDesc('id')->limit($limit)
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    // -- refund marks ---------------------------------------------------------

    /**
     * The 24-month one-way fingerprint of a refunded account (lifecycle §8):
     * a salted-nothing sha256 of the normalized address — it cannot be turned
     * back into a person, it survives deletion, and its only use is judging
     * future refund requests. Disclosed at refund time.
     */
    public function addRefundMark(string $email): void
    {
        Capsule::table('mod_vpnhood_iap_refund_marks')->insert([
            'email_hash' => hash('sha256', self::normalizeEmail($email)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Was this address refunded before (within the retained 24 months)? */
    public function hasRefundMark(string $email): bool
    {
        return Capsule::table('mod_vpnhood_iap_refund_marks')
            ->where('email_hash', hash('sha256', self::normalizeEmail($email)))
            ->exists();
    }

    // -- audit log + rate limiting ------------------------------------------

    /**
     * Append to the request log. Trims oversized bodies; never throws (logging must not
     * take the request down with it).
     */
    public function log(?int $userId, string $action, string $remoteIp, int $httpStatus, $request, $response): void
    {
        try {
            Capsule::table('mod_vpnhood_iap_log')->insert([
                'user_id'     => $userId,
                'action'      => substr($action, 0, 64),
                'remote_ip'   => substr($remoteIp, 0, 64),
                'http_status' => $httpStatus,
                'request'     => substr(is_string($request) ? $request : json_encode($request), 0, 65000),
                'response'    => substr(is_string($response) ? $response : json_encode($response), 0, 65000),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // swallow: the log table must never break the API path
        }
    }

    /** Sliding-window request count for one IP + action, for rate limiting. */
    public function requestCount(string $remoteIp, string $action, int $windowSeconds): int
    {
        return (int) Capsule::table('mod_vpnhood_iap_log')
            ->where('remote_ip', $remoteIp)
            ->where('action', $action)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $windowSeconds))
            ->count();
    }

    /** Sliding-window request count for one authenticated account + action. */
    public function requestCountForUser(int $userId, string $action, int $windowSeconds): int
    {
        return (int) Capsule::table('mod_vpnhood_iap_log')
            ->where('user_id', $userId)
            ->where('action', $action)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $windowSeconds))
            ->count();
    }
}
