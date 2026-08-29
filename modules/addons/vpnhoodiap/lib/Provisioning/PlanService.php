<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * The PRICED plan list for a WEB-distributed app — what its plans page renders
 * before anyone signs in. Store-distributed apps never get prices from here:
 * their store prices their plans, and store policy forbids pointing users at
 * an external purchase — so this service answers only for store `web`, and the
 * refusal is the server-side half of that rule (the app hides the UI, the
 * portal refuses the data).
 *
 * Prices come from the WHMCS product pricing table — the same rows the cart
 * bills from — in ONE currency per response: the signed-in client's locked
 * currency when there is one, the install's default otherwise. Each plan
 * carries a ready-made cart URL pinned to that same currency, so the price on
 * the card and the price at checkout agree by construction; a promise the
 * checkout could break is never made.
 */
class PlanService
{
    /** cycle months → the cart's billingcycle token / the pricing-table column / the wire period */
    private const CYCLES = [
        1  => ['monthly', 'P1M'],
        3  => ['quarterly', 'P3M'],
        6  => ['semiannually', 'P6M'],
        12 => ['annually', 'P1Y'],
        24 => ['biennially', 'P2Y'],
        36 => ['triennially', 'P3Y'],
    ];

    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * @param array $app the mod_vpnhood_iap_apps row
     * @param ?array $sessionUser the signed-in module user, when there is one
     * @return array[] [{planId, billingPeriod, priceAmount, priceCurrency, priceCurrencySymbol, purchaseUrl}]
     * @throws ApiException 403 for a store-distributed app
     */
    public function plansForApp(array $app, ?array $sessionUser): array
    {
        if (($app['store'] ?? '') !== 'web') {
            throw new ApiException(
                'Priced plans are served only to web-distributed apps; a store app prices its plans at its store.',
                403, 'store_not_supported');
        }

        $currency = null;
        if ($sessionUser !== null && $sessionUser['client_id'] !== null) {
            $currency = $this->repo->clientCurrency((int) $sessionUser['client_id']);
        }
        $currency ??= $this->repo->defaultCurrency();

        // the exact prefix the cart renders; a prefix-less currency falls back to "CODE 9.99"
        $currencySymbol = $currency['prefix'] !== '' ? $currency['prefix'] : $currency['code'] . ' ';

        $plans = [];
        foreach ($this->repo->findSellableMappings((int) $app['id']) as $mapping) {
            $cycle = self::CYCLES[(int) $mapping['billing_cycle_months']] ?? null;
            if ($cycle === null) {
                continue; // one-time and unknown cycles carry no recurring price to show
            }
            [$cycleName, $period] = $cycle;
            $price = $this->repo->productPrice((int) $mapping['whmcs_product_id'], $cycleName, (int) $currency['id']);
            if ($price === null) {
                continue; // the cycle is disabled for this currency — nothing to promise
            }
            $plans[] = [
                'planId'              => (string) $mapping['store_product_id'],
                'billingPeriod'       => $period,
                'priceAmount'         => $price,
                'priceCurrency'       => (string) $currency['code'],
                'priceCurrencySymbol' => $currencySymbol,
                'purchaseUrl'         => $this->repo->portalBaseUrl()
                    . '/cart.php?a=add&pid=' . (int) $mapping['whmcs_product_id']
                    . "&billingcycle=$cycleName&currency=" . (int) $currency['id'],
            ];
        }
        return $plans;
    }
}
