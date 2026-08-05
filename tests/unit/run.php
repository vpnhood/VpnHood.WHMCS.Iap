<?php
/**
 * run.php — execute every tests/unit/*.test.php with the tiny harness in
 * lib/UnitTest.php. Pure PHP 8.1+; needs neither WHMCS nor Composer, so it
 * runs anywhere a php binary exists (locally, CI, or the dev server via
 * scripts/test-dev.sh).
 *
 * Module lib classes guard themselves with VPNHOODIAP_TEST so they can be
 * loaded outside WHMCS here.
 */

define('VPNHOODIAP_TEST', true);
error_reporting(E_ALL);

require __DIR__ . '/lib/UnitTest.php';

// Module classes under test resolve relative to the repo layout; test files
// use IAP_LIB . '/<Class>.php'.
define('IAP_LIB', dirname(__DIR__, 2) . '/modules/addons/vpnhoodiap/lib');

$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);
if ($files === []) {
    fwrite(STDERR, "no unit test files found\n");
    exit(1);
}
foreach ($files as $file) {
    require $file;
}

exit(UnitTest::run());
