<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Stores;

use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Stores\AppStore\AppStoreAdapter;
use WHMCS\Module\Addon\VpnHoodIap\Stores\GooglePlay\GooglePlayAdapter;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * store id → adapter. Tests may register a replacement adapter (a fake store)
 * before the pipeline runs; production code never does.
 */
final class StoreAdapterRegistry
{
    /** @var array<string,StoreAdapterInterface> */
    private static array $overrides = [];

    public static function register(StoreAdapterInterface $adapter): void
    {
        self::$overrides[$adapter->storeId()] = $adapter;
    }

    public static function get(string $store): StoreAdapterInterface
    {
        if (isset(self::$overrides[$store])) {
            return self::$overrides[$store];
        }
        return match ($store) {
            'googleplay' => new GooglePlayAdapter(),
            'appstore'   => new AppStoreAdapter(),
            'microsoft'  => throw new ApiException("Store '$store' is not supported yet.", 501, 'store_not_supported'),
            // registration-only: a direct-download build has no store to validate against, so a
            // purchase or webhook claiming this "store" is a category error, not a missing feature
            'web'        => throw new ApiException("'$store' is a direct-download build, not a store.", 400, 'unknown_store'),
            default      => throw new ApiException("Unknown store: $store", 400, 'unknown_store'),
        };
    }
}
