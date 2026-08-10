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
    public const STORES = ['googleplay', 'appstore', 'microsoft'];
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

    /** The account key. Case and surrounding space never distinguish two people. */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Resolve a verified sign-in to THE account — the person — creating it when new.
     *
     *   1. A known identity (provider, subject) always wins. This is what keeps an
     *      account stable when the provider changes its email address.
     *   2. A NEW identity whose verified email matches an existing account joins that
     *      account: Google today, Apple or a password login tomorrow, same address,
     *      same account, same external_uid — so a purchase made under one provider
     *      is still bound (the stores echo external_uid back) after signing in with
     *      another. Only safe because the caller has already rejected sign-ins the
     *      provider did not mark email_verified — an unverified address would let
     *      anyone claim someone else's account by naming it.
     *   3. Otherwise this is a new person: account + first linked identity.
     *
     * The account keeps the email it was created with; a provider-side address
     * change updates the identity row only (rule 1 found it, nothing to re-key).
     * provider/provider_subject on the account mirror the most recent sign-in for
     * the admin's benefit — resolution never reads them.
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
            return $this->recordSignIn($user, $provider, $subject, $emailVerifiedClaim, $displayName, $now);
        }

        // -- 2. new identity, known address
        $user = $this->findUserByEmail($email);
        if ($user !== null) {
            $this->linkIdentity((int) $user['id'], $provider, $subject, $email, $now);
            return $this->recordSignIn($user, $provider, $subject, $emailVerifiedClaim, $displayName, $now);
        }

        // -- 3. new person
        try {
            $id = Capsule::table('mod_vpnhood_iap_users')->insertGetId([
                'provider'             => $provider,
                'provider_subject'     => $subject,
                'email'                => $email,
                'display_name'         => $displayName,
                'email_verified_claim' => $emailVerifiedClaim ? 1 : 0,
                'external_uid'         => self::uuidV4(),
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        } catch (\Throwable $e) {
            // lost a concurrent-insert race on unique (email) — reread and link
            $row = $this->findUserByEmail($email);
            if ($row === null) {
                throw $e;
            }
            $this->linkIdentity((int) $row['id'], $provider, $subject, $email, $now);
            return $row;
        }
        $this->linkIdentity((int) $id, $provider, $subject, $email, $now);
        $user = $this->getUser((int) $id);
        if ($user === null) {
            throw new \RuntimeException('User row disappeared right after insert.');
        }
        return $user;
    }

    /** Mirror the sign-in onto the account row (admin display only — never resolution). */
    private function recordSignIn(array $user, string $provider, string $subject, bool $emailVerifiedClaim, ?string $displayName, string $now): array
    {
        $update = [];
        if ($user['provider'] !== $provider || $user['provider_subject'] !== $subject) {
            $update = ['provider' => $provider, 'provider_subject' => $subject];
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

    /** Attach a sign-in proof to an account. Losing the unique-insert race is fine — the identity exists. */
    private function linkIdentity(int $userId, string $provider, string $subject, string $email, string $now): void
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
        } catch (\Throwable) {
            // unique (provider, subject) already present — a concurrent request linked it
        }
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
}
