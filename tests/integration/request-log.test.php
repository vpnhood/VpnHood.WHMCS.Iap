<?php
/**
 * request-log.test.php — the request log's daily cleanup (IapRepository::pruneRequestLog) inside
 * the deployed dev WHMCS: an HTTP row goes a day before the RequestLogRetentionDays the privacy
 * policy promises (30 while unset), and the module's own audit rows stay. The ages stay at or
 * past what the daily cron already deletes, so nothing but the fixtures is touched. The setting
 * is a tbladdonmodules row no localAPI command writes: the test sets it and puts it back.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('IapRepository.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}

$marker = 'itest-' . bin2hex(random_bytes(4));

/** A log row this many days old (a minute more): an HTTP request, or one of the module's audit lines. */
function logRow(string $marker, string $action, int $ageDays): int
{
    return (int) Capsule::table('mod_vpnhood_iap_log')->insertGetId([
        'action'      => $action,
        'remote_ip'   => $marker,
        'http_status' => 200,
        'created_at'  => date('Y-m-d H:i:s', time() - $ageDays * 86400 - 60),
    ]);
}

/** The RequestLogRetentionDays setting, as WHMCS stores an addon's settings; null removes it. */
function setRetentionDays(?string $value): void
{
    Capsule::table('tbladdonmodules')->where('module', 'vpnhoodiap')->where('setting', 'RequestLogRetentionDays')->delete();
    if ($value !== null) {
        Capsule::table('tbladdonmodules')->insert(['module' => 'vpnhoodiap', 'setting' => 'RequestLogRetentionDays', 'value' => $value]);
    }
}

function kept(int $rowId): bool
{
    return Capsule::table('mod_vpnhood_iap_log')->where('id', $rowId)->exists();
}

$saved = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodiap')
    ->where('setting', 'RequestLogRetentionDays')->value('value');
$repo = new IapRepository();

try {
    // -- unset: the 30 days of the policy, so a row goes after 29 ---------------
    setRetentionDays(null);
    $within = logRow($marker, 'GET /v1/account', 28);
    $past = logRow($marker, 'GET /v1/account', 29);
    $audit = logRow($marker, 'legacy.handover', 400);
    $repo->pruneRequestLog();
    (kept($within) && !kept($past))
        ? ok('unset: a request of 28 days stays, one of 29 goes, so none outlives 30')
        : bad('unset: ' . json_encode(['28 days kept' => kept($within), '29 days kept' => kept($past)]));
    kept($audit)
        ? ok("the module's own audit row stays, however old")
        : bad('the audit row was deleted');

    // -- a policy of 60 days ------------------------------------------------------
    setRetentionDays('60');
    $within = logRow($marker, 'POST /v1/auth/sessions', 58);
    $past = logRow($marker, 'POST /v1/auth/sessions', 59);
    $repo->pruneRequestLog();
    (kept($within) && !kept($past))
        ? ok('RequestLogRetentionDays 60: a request of 58 days stays, one of 59 goes')
        : bad('60 days: ' . json_encode(['58 days kept' => kept($within), '59 days kept' => kept($past)]));
} finally {
    setRetentionDays($saved === null ? null : (string) $saved);
    Capsule::table('mod_vpnhood_iap_log')->where('remote_ip', $marker)->delete();
    ok('fixture rows removed, the setting put back');
}

finish();
