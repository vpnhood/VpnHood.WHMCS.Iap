<?php
/**
 * pending-upgrade.test.php — the cron's catch-up of an update (hooks.php AfterCronJob →
 * vpnhoodiap_runPendingUpgrade) on the dev WHMCS: a recorded version older than the files runs
 * vpnhoodiap_upgrade() and becomes the files' version, with one activity-log line; a second run
 * changes nothing; a newer one (a rollback's) is recorded as the files' without the upgrade.
 * WHMCS loads hooks.php only while the addon is in its AddonModulesHooks list, so that comes first.
 *
 * Runs ON the dev server (uploaded by scripts/test-dev.sh). The upgrade's mark is the bookkeeping
 * gateway's checkout visibility, which the upgrade clamps off: the test turns it on raw (no
 * localAPI command writes it), and leaves it off and the version row at the files' version.
 */

require __DIR__ . '/lib/common.php';

use WHMCS\Database\Capsule;

function recordedVersion(): ?string
{
    $value = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodiap')->where('setting', 'version')->value('value');
    return $value === null ? null : (string) $value;
}

function recordVersion(string $version): void
{
    Capsule::table('tbladdonmodules')->where('module', 'vpnhoodiap')->where('setting', 'version')->update(['value' => $version]);
}

function gatewayVisible(): string
{
    return (string) Capsule::table('tblpaymentgateways')->where('gateway', 'vpnhoodiappay')->where('setting', 'visible')->value('value');
}

function setGatewayVisible(string $value): void
{
    Capsule::table('tblpaymentgateways')->where('gateway', 'vpnhoodiappay')->where('setting', 'visible')->update(['value' => $value]);
}

function logLines(int $afterId, string $description): int
{
    return Capsule::table('tblactivitylog')->where('id', '>', $afterId)->where('description', $description)->count();
}

if (!iapModuleActive($db)) {
    bad('vpnhoodiap is not active on the dev install: run the activation test first');
    finish();
}

$hookList = explode(',', (string) Capsule::table('tblconfiguration')->where('setting', 'AddonModulesHooks')->value('value'));
in_array('vpnhoodiap', $hookList, true)
    ? ok('vpnhoodiap is in AddonModulesHooks, so WHMCS loads its cron hook')
    : bad('vpnhoodiap is active but not in AddonModulesHooks: WHMCS never loads its hooks.php; Save Changes on any addon in Addon Modules rebuilds the list');

if (!Capsule::table('tblpaymentgateways')->where('gateway', 'vpnhoodiappay')->where('setting', 'visible')->exists()) {
    bad('the vpnhoodiappay gateway is not activated: run the activation test first');
    finish();
}

require_once WEBROOT . '/modules/addons/vpnhoodiap/vpnhoodiap.php';
$config = vpnhoodiap_config();
$version = (string) $config['version'];
$marker = (int) Capsule::table('tblactivitylog')->max('id');

try {
    setGatewayVisible('on');
    recordVersion('0.0.1');
    vpnhoodiap_runPendingUpgrade();
    $line = "{$config['name']} updated from 0.0.1 to $version.";
    (recordedVersion() === $version && gatewayVisible() === '' && logLines($marker, $line) === 1)
        ? ok("an older recorded version runs the upgrade and becomes $version, with one activity-log line")
        : bad('after an update: ' . json_encode(['recorded' => recordedVersion(), 'visible' => gatewayVisible(), 'lines' => logLines($marker, $line)]));

    setGatewayVisible('on');
    vpnhoodiap_runPendingUpgrade();
    (recordedVersion() === $version && gatewayVisible() === 'on' && logLines($marker, $line) === 1)
        ? ok('a second run changes nothing')
        : bad('a second run: ' . json_encode(['recorded' => recordedVersion(), 'visible' => gatewayVisible(), 'lines' => logLines($marker, $line)]));

    recordVersion('99.0.0');
    vpnhoodiap_runPendingUpgrade();
    (recordedVersion() === $version && gatewayVisible() === 'on')
        ? ok("a rollback's older files are recorded as $version, without the upgrade")
        : bad('after a rollback: ' . json_encode(['recorded' => recordedVersion(), 'visible' => gatewayVisible()]));
} finally {
    setGatewayVisible('');
    recordVersion($version);
}

finish();
