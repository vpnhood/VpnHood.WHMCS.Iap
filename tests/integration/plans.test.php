<?php
/**
 * plans.test.php — PlanService against the real module tables inside the
 * deployed dev WHMCS: the priced plan list a WEB-distributed app renders.
 * Ordering, field shape, the sellable/enabled gates, cycle handling (one-time
 * and disabled cycles are skipped), and the store gate (a store-distributed
 * app is refused — its store prices its plans). Pricing rows are synthetic
 * (a throwaway pid in tblpricing, removed in cleanup) so no real product's
 * pricing is read or touched.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'IapRepository.php', 'Provisioning/PlanService.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\PlanService;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
if (!columnExists($db, 'mod_vpnhood_iap_products', 'sellable')) {
    bad('sellable column missing — WHMCS has not run the module upgrade yet');
    finish();
}

$marker = 'itest-' . bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$repo = new IapRepository();
$service = new PlanService($repo);

$currency = $repo->defaultCurrency();
ok("default currency: #{$currency['id']} {$currency['code']}");

// throwaway pid: pricing rows only — PlanService never reads tblproducts
$fakePid = 900000 + random_int(0, 99999);
Capsule::table('tblpricing')->insert([
    ['type' => 'product', 'relid' => $fakePid, 'currency' => $currency['id'],
        'monthly' => '7.90', 'quarterly' => '-1.00', 'annually' => '46.80'],
]);

$webAppId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store' => 'web', 'package_name' => "com.vpnhood.$marker.web",
    'webhook_token' => bin2hex(random_bytes(24)), 'status' => 'active',
    'created_at' => $now, 'updated_at' => $now,
]);
$playAppId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store' => 'googleplay', 'package_name' => "com.vpnhood.$marker.play",
    'webhook_token' => bin2hex(random_bytes(24)), 'status' => 'active',
    'created_at' => $now, 'updated_at' => $now,
]);
Capsule::table('mod_vpnhood_iap_products')->insert([
    // the two real plans, inserted yearly-first to prove the service reorders
    ['app_id' => $webAppId, 'store_product_id' => 'premium-yearly', 'store_base_plan_id' => '',
        'whmcs_product_id' => $fakePid, 'billing_cycle_months' => 12, 'enabled' => 1, 'sellable' => 1],
    ['app_id' => $webAppId, 'store_product_id' => 'premium-monthly', 'store_base_plan_id' => '',
        'whmcs_product_id' => $fakePid, 'billing_cycle_months' => 1, 'enabled' => 1, 'sellable' => 1],
    // never shown: redemption-only, disabled, one-time, disabled-cycle price (-1)
    ['app_id' => $webAppId, 'store_product_id' => 'premium-retired', 'store_base_plan_id' => '',
        'whmcs_product_id' => $fakePid, 'billing_cycle_months' => 1, 'enabled' => 1, 'sellable' => 0],
    ['app_id' => $webAppId, 'store_product_id' => 'premium-disabled', 'store_base_plan_id' => '',
        'whmcs_product_id' => $fakePid, 'billing_cycle_months' => 1, 'enabled' => 0, 'sellable' => 1],
    ['app_id' => $webAppId, 'store_product_id' => 'premium-lifetime', 'store_base_plan_id' => '',
        'whmcs_product_id' => $fakePid, 'billing_cycle_months' => 0, 'enabled' => 1, 'sellable' => 1],
    ['app_id' => $webAppId, 'store_product_id' => 'premium-quarterly', 'store_base_plan_id' => '',
        'whmcs_product_id' => $fakePid, 'billing_cycle_months' => 3, 'enabled' => 1, 'sellable' => 1],
]);
ok("fixtures created (web app #$webAppId, play app #$playAppId, pricing pid $fakePid)");

try {
    $webApp = $repo->getApp($webAppId);
    $plans = $service->plansForApp($webApp, null);

    (count($plans) === 2)
        ? ok('exactly the two priced, sellable, recurring plans are served')
        : bad('unexpected plan count: ' . json_encode($plans));
    (($plans[0]['planId'] ?? '') === 'premium-monthly' && ($plans[1]['planId'] ?? '') === 'premium-yearly')
        ? ok('plans come shortest cycle first regardless of insert order')
        : bad('plan order wrong: ' . json_encode(array_column($plans, 'planId')));

    $monthly = $plans[0];
    ($monthly['billingPeriod'] === 'P1M' && $monthly['priceAmount'] === '7.90'
        && $monthly['priceCurrency'] === $currency['code'])
        ? ok('monthly plan carries period, price and currency from the pricing table')
        : bad('monthly plan wrong: ' . json_encode($monthly));
    $expectedSymbol = $currency['prefix'] !== '' ? $currency['prefix'] : $currency['code'] . ' ';
    ($monthly['priceCurrencySymbol'] === $expectedSymbol)
        ? ok("plan carries the checkout's own display symbol ('$expectedSymbol')")
        : bad('currency symbol wrong: ' . json_encode($monthly['priceCurrencySymbol'] ?? null));
    $expectedUrl = "cart.php?a=add&pid=$fakePid&billingcycle=monthly&currency={$currency['id']}";
    (str_contains($monthly['purchaseUrl'], $expectedUrl) && str_starts_with($monthly['purchaseUrl'], 'http'))
        ? ok('purchase URL pins plan, cycle and the SAME currency as the shown price')
        : bad('purchase URL wrong: ' . $monthly['purchaseUrl']);
    ($plans[1]['billingPeriod'] === 'P1Y' && $plans[1]['priceAmount'] === '46.80')
        ? ok('yearly plan priced from the annually column')
        : bad('yearly plan wrong: ' . json_encode($plans[1]));

    $servedIds = array_column($plans, 'planId');
    (!in_array('premium-retired', $servedIds, true) && !in_array('premium-disabled', $servedIds, true))
        ? ok('redemption-only (sellable=0) and disabled rows never appear')
        : bad('gated rows leaked: ' . json_encode($servedIds));
    (!in_array('premium-lifetime', $servedIds, true) && !in_array('premium-quarterly', $servedIds, true))
        ? ok('one-time and disabled-cycle (-1 priced) rows are skipped')
        : bad('unpriceable rows leaked: ' . json_encode($servedIds));

    // the store gate: a store-distributed app must not get prices from the portal
    try {
        $service->plansForApp($repo->getApp($playAppId), null);
        bad('a store-distributed app was served priced plans');
    } catch (ApiException $e) {
        ($e->getHttpStatus() === 403 && $e->getErrorCode() === 'store_not_supported')
            ? ok('a store-distributed app is refused with 403 store_not_supported')
            : bad('wrong refusal: ' . $e->getHttpStatus() . ' / ' . $e->getErrorCode());
    }
} finally {
    Capsule::table('mod_vpnhood_iap_products')->whereIn('app_id', [$webAppId, $playAppId])->delete();
    Capsule::table('mod_vpnhood_iap_apps')->whereIn('id', [$webAppId, $playAppId])->delete();
    Capsule::table('tblpricing')->where('type', 'product')->where('relid', $fakePid)->delete();
    ok('fixtures cleaned up');
}

finish();
