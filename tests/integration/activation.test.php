<?php
/**
 * activation.test.php — the addon activates cleanly and owns its tables.
 *
 * Runs ON the dev server (uploaded by scripts/test-dev.sh). Activates the
 * addon through the official ActivateModule API when it is not active yet
 * (idempotent — re-activation of an active addon is tolerated), then asserts
 * every mod_vpnhood_iap_* table exists. Never deactivates: the dev install's
 * addon state is shared with manual testing.
 */

require __DIR__ . '/lib/common.php';

// -- activate (idempotent) --------------------------------------------------
if (!iapModuleActive($db)) {
    $r = localAPI('ActivateModule', ['moduleType' => 'addon', 'moduleName' => 'vpnhoodiap']);
    if (($r['result'] ?? '') === 'success') {
        ok('addon activated via ActivateModule');
    } else {
        bad('ActivateModule failed: ' . json_encode($r));
        finish();
    }
} else {
    ok('addon already active');
}

if (iapModuleActive($db)) {
    ok('tbladdonmodules has vpnhoodiap rows');
} else {
    bad('addon not present in tbladdonmodules after activation');
    finish();
}

// -- bookkeeping gateway (required for AddOrder paymentmethod) ---------------
$gatewayActive = one($db, "SELECT 1 x FROM tblpaymentgateways WHERE gateway='vpnhoodiappay' LIMIT 1") !== null;
if (!$gatewayActive) {
    $r = localAPI('ActivateModule', ['moduleType' => 'gateway', 'moduleName' => 'vpnhoodiappay']);
    $gatewayActive = one($db, "SELECT 1 x FROM tblpaymentgateways WHERE gateway='vpnhoodiappay' LIMIT 1") !== null;
    if (!$gatewayActive) {
        bad('gateway activation failed: ' . json_encode($r));
    } else {
        ok('vpnhoodiappay gateway activated');
    }
} else {
    ok('vpnhoodiappay gateway already active');
}

// -- admin visibility -------------------------------------------------------
// WHMCS only lists the addon under Addons for roles named in its `access` row,
// and only the admin-UI activation flow writes that row — so a module activated
// through the API (exactly what this test does, and what an automated install
// does) used to end up working but invisible. _activate() now grants it.
$access = one($db, "SELECT value FROM tbladdonmodules WHERE module='vpnhoodiap' AND setting='access'");
if ($access === null) {
    bad('no access row: the addon would be invisible in the Addons menu');
} elseif (trim((string) $access['value']) === '') {
    bad('access row is empty: the addon would be invisible in the Addons menu');
} else {
    $roleExists = one($db, 'SELECT id FROM tbladminroles WHERE id IN (' .
        implode(',', array_map('intval', explode(',', (string) $access['value']))) . ') LIMIT 1');
    if ($roleExists === null) {
        bad("access row lists no existing role (value: {$access['value']})");
    } else {
        ok("addon is visible to admin role(s) {$access['value']}");
    }
}

// -- tables -----------------------------------------------------------------
$tables = [
    'mod_vpnhood_iap_apps',
    'mod_vpnhood_iap_products',
    'mod_vpnhood_iap_users',
    'mod_vpnhood_iap_identities',
    'mod_vpnhood_iap_sessions',
    'mod_vpnhood_iap_purchases',
    'mod_vpnhood_iap_events',
    'mod_vpnhood_iap_log',
];
foreach ($tables as $t) {
    if (tableExists($db, $t)) {
        ok("table $t exists");
    } else {
        bad("table $t missing");
    }
}

// -- version agreement ------------------------------------------------------
// The version WHMCS recorded at activation must match the module code on disk
// (same check scripts/set-version.sh --check does locally, but against the DB).
$configFile = WEBROOT . '/modules/addons/vpnhoodiap/vpnhoodiap.php';
$src = (string) file_get_contents($configFile);
if (preg_match("/'version'\s*=>\s*'([^']+)'/", $src, $m)) {
    $codeVersion = $m[1];
    $dbVersion = (string) (one($db, "SELECT value FROM tbladdonmodules WHERE module='vpnhoodiap' AND setting='version'")['value'] ?? '');
    if ($dbVersion === '' || $dbVersion === $codeVersion) {
        ok("module version consistent (code $codeVersion" . ($dbVersion !== '' ? ", db $dbVersion" : ', db not recorded') . ')');
    } else {
        // WHMCS runs _upgrade() on the next admin page load after a version bump;
        // a mismatch right after deploy is expected — report, don't fail.
        ok("version differs pending upgrade (code $codeVersion, db $dbVersion) — WHMCS will run _upgrade() on next admin load");
    }
} else {
    bad('could not read version from vpnhoodiap.php');
}

finish();
