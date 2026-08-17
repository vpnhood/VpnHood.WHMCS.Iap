<?php
/**
 * common.php — shared helpers for the vpnhoodiap integration test scripts.
 *
 * Runs ON the dev server (uploaded alongside each *.test.php by
 * scripts/test-dev.sh). Provides DB access, a tiny assertion/report harness,
 * and module-table lookups.
 *
 * Every write in these scripts goes through localAPI() or the module's own
 * mod_vpnhood_iap_* tables — never a raw INSERT/UPDATE against WHMCS core
 * orders, invoices, or hosting. (Same rule as the hub repo's suite.)
 */

error_reporting(E_ALL);
const WEBROOT = '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html';

// Fixture accounts created by the hub repo's bootstrap; reused here so this
// suite never has to create clients of its own.
const BUYER_EMAIL = 'test-buyer@vpnhood.com';

require_once WEBROOT . '/init.php';

/** @var PDO $db */
$db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_username, $db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$report = ['steps' => [], 'pass' => 0, 'fail' => 0];
function ok(string $msg): void  { global $report; $report['steps'][] = "PASS $msg"; $report['pass']++; }
function bad(string $msg): void { global $report; $report['steps'][] = "FAIL $msg"; $report['fail']++; }
function finish(): never {
    global $report;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($report['fail'] > 0 ? 1 : 0);
}

function one(PDO $db, string $sql, array $args = []): ?array {
    $st = $db->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function clientByEmail(PDO $db, string $email): ?array {
    return one($db, 'SELECT id, credit FROM tblclients WHERE email=?', [$email]);
}

function tableExists(PDO $db, string $table): bool {
    return one($db, 'SELECT 1 x FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== null;
}

function columnExists(PDO $db, string $table, string $column): bool {
    return one($db, 'SELECT 1 x FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]) !== null;
}

function iapModuleActive(PDO $db): bool {
    return one($db, "SELECT 1 x FROM tbladdonmodules WHERE module='vpnhoodiap' LIMIT 1") !== null;
}

/** Load the module's lib classes for in-process tests (SessionService etc.). */
function requireIapLib(string ...$relPaths): void {
    foreach ($relPaths as $rel) {
        require_once WEBROOT . '/modules/addons/vpnhoodiap/lib/' . $rel;
    }
}
