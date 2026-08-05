<?php

namespace WHMCS\Module\Addon\VpnHoodIap;

use WHMCS\Database\Capsule;

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
            $plain = (string) ($result['password'] ?? '');
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
        $systemUrl = rtrim((string) Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')->value('value'), '/');
        return $systemUrl . '/modules/addons/vpnhoodiap/webhook.php?store='
            . rawurlencode((string) $app['store']) . '&t=' . rawurlencode((string) $app['webhook_token']);
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
