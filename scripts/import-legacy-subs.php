<?php

/**
 * import-legacy-subs.php — one-shot, run ON the WHMCS box (dev or prod):
 *
 *   php scripts/import-legacy-subs.php legacy-google-subs.json [--dry-run]
 *
 * Loads the retired .NET store's live subscriptions into
 * `mod_vpnhood_iap_legacy_subs`, so that LegacyStoreHandover can hand each
 * customer their entitlement the moment they sign into the new app. The JSON is
 * produced by .user/store.vpnhood.com/legacy-migration/export-legacy-subs.mjs.
 *
 * RUN IT AGAIN, WITH A FRESH EXPORT, IMMEDIATELY BEFORE THE OLD STORE IS SWITCHED
 * OFF. Subscriptions renew, lapse and cancel continuously, so an import from last
 * week describes a world that no longer exists. Re-importing is safe: rows are
 * matched on (store, purchase_key) and updated in place, and a row that has
 * already been claimed is never reopened.
 *
 * Runbook: <Vh root>/.user/docs/legacy-store-shutdown.md
 *
 * TEMPORARY — delete with the table and LegacyStoreHandover once drained.
 */

foreach (['/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html', getcwd(), dirname(__DIR__)] as $root) {
    if (file_exists($root . '/init.php')) {
        require_once $root . '/init.php';
        break;
    }
}
if (!defined('WHMCS')) {
    fwrite(STDERR, "could not find WHMCS init.php\n");
    exit(1);
}

use WHMCS\Database\Capsule;

$args = array_values(array_filter($argv ?? [], fn ($a) => $a !== '' && $a[0] !== '-'));
$dryRun = in_array('--dry-run', $argv ?? [], true);
$file = $args[1] ?? '';
if ($file === '' || !is_readable($file)) {
    fwrite(STDERR, "usage: php scripts/import-legacy-subs.php <legacy-google-subs.json> [--dry-run]\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($file), true);
if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
    fwrite(STDERR, "not an export file (no rows[])\n");
    exit(1);
}
if (!Capsule::schema()->hasTable('mod_vpnhood_iap_legacy_subs')) {
    fwrite(STDERR, "mod_vpnhood_iap_legacy_subs is missing — is the module at 1.8.0 and upgraded?\n");
    exit(1);
}

// The export names the OLD store's app ("vpnhoodconnect"); the module keys apps by
// their store package name. Only CONNECT ever sold through the legacy store.
$packageByApp = ['vpnhoodconnect' => 'com.vpnhood.connect.android'];

echo "export taken {$payload['takenUtc']} — {$payload['count']} rows";
$age = (time() - strtotime((string) $payload['takenUtc'])) / 86400;
if ($age > 2) {
    printf("  *** %.1f days old — re-run the export before a real shutdown ***", $age);
}
echo "\n\n";

$now = date('Y-m-d H:i:s');
$inserted = $updated = $skipped = 0;

foreach ($payload['rows'] as $r) {
    // PayPlans (billingProviderId 2) is dead and unreachable — its endpoint is gone and
    // nothing can renew through it, so importing those rows would promise a handover
    // that can never verify. Google only.
    if ((int) ($r['billingProviderId'] ?? 0) !== 1) {
        $skipped++;
        continue;
    }
    $package = $packageByApp[$r['app']] ?? null;
    $email = strtolower(trim((string) ($r['email'] ?? '')));
    $token = (string) ($r['purchaseToken'] ?? '');
    if ($package === null || $email === '' || $token === '') {
        fwrite(STDERR, "skipping row with no package/email/token (order {$r['orderId']})\n");
        $skipped++;
        continue;
    }

    $fields = [
        'store'              => 'googleplay',
        'package_name'       => $package,
        'store_product_id'   => (string) $r['providerSubscriptionId'],
        'store_base_plan_id' => (string) $r['planId'],
        'purchase_key'       => $token,
        'provider_order_id'  => (string) ($r['orderId'] ?? ''),
        'obfuscated_uid'     => (string) ($r['obfuscatedUid'] ?? ''),
        'email'              => $email,
        'expires_at'         => $r['expiresUtc'] ? date('Y-m-d H:i:s', strtotime((string) $r['expiresUtc'])) : null,
        'is_auto_renew'      => !empty($r['isAutoRenew']) ? 1 : 0,
        'price_amount'       => $r['priceAmount'] ?? null,
        'price_currency'     => $r['priceCurrency'] ?? null,
        'imported_at'        => $now,
    ];

    $existing = Capsule::table('mod_vpnhood_iap_legacy_subs')
        ->where('store', 'googleplay')->where('purchase_key', $token)->first();

    if ($existing === null) {
        $inserted++;
        if (!$dryRun) {
            Capsule::table('mod_vpnhood_iap_legacy_subs')->insert($fields + ['status' => 'pending']);
        }
        continue;
    }
    // Already handed over: refresh the facts, never reopen the claim.
    if ($existing->status === 'claimed') {
        $skipped++;
        continue;
    }
    $updated++;
    if (!$dryRun) {
        Capsule::table('mod_vpnhood_iap_legacy_subs')->where('id', $existing->id)->update($fields);
    }
}

printf("%s  inserted %d, updated %d, skipped %d\n", $dryRun ? '[dry-run]' : '[applied] ', $inserted, $updated, $skipped);

foreach (Capsule::table('mod_vpnhood_iap_legacy_subs')
    ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get() as $row) {
    printf("   %-10s %d\n", $row->status, $row->n);
}
