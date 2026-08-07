#!/usr/bin/env bash
#
# watch-dev.sh — live tail of the vpnhoodiap pipeline on the dev WHMCS, so a
# purchase can be followed from the device in real time:
#
#   API calls      POST /auth/sessions, POST /billing/purchases … (status + route)
#   Store events   RTDN/ASSN notifications as they are received and processed
#   Purchases      the state machine, with the WHMCS order/service it produced
#   Alerts         anything the module logged as an admin alert
#
# Usage:
#   scripts/watch-dev.sh            # follow (Ctrl-C to stop)
#   scripts/watch-dev.sh --once     # print the current tail and exit
#   scripts/watch-dev.sh --since 30 # start from the last 30 minutes (default 10)
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VH_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
WEBROOT="/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html"

FOLLOW=1
SINCE_MINUTES=10
while [ $# -gt 0 ]; do
  case "$1" in
    --once)  FOLLOW=0; shift ;;
    --since) SINCE_MINUTES="$2"; shift 2 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }

# The remote watcher: polls the module's own tables and prints only rows it has
# not printed yet. Runs entirely on the server so each poll is a local query.
REMOTE_PHP=$(cat <<'PHP'
<?php
require getenv('IAP_WEBROOT') . '/init.php';
use WHMCS\Database\Capsule;

$follow  = (int) getenv('IAP_FOLLOW') === 1;
$since   = date('Y-m-d H:i:s', time() - 60 * max(1, (int) getenv('IAP_SINCE')));
$seen    = ['log' => 0, 'events' => 0, 'purchases' => []];

function paint(string $color, string $text): string {
    $codes = ['red' => '0;31', 'green' => '0;32', 'yellow' => '0;33', 'blue' => '0;36', 'grey' => '0;90'];
    return "\033[" . ($codes[$color] ?? '0') . "m" . $text . "\033[0m";
}

// prime: everything before the window is considered already seen
$seen['log'] = (int) Capsule::table('mod_vpnhood_iap_log')->where('created_at', '<', $since)->max('id');
$seen['events'] = (int) Capsule::table('mod_vpnhood_iap_events')->where('created_at', '<', $since)->max('id');

do {
    foreach (Capsule::table('mod_vpnhood_iap_log')->where('id', '>', $seen['log'])->orderBy('id')->get() as $r) {
        $seen['log'] = (int) $r->id;
        $status = (int) $r->http_status;
        $color  = $status === 0 ? 'grey' : ($status < 300 ? 'green' : ($status < 500 ? 'yellow' : 'red'));
        $line = sprintf('%s  API   %-18s %-4s %s', substr((string) $r->created_at, 11), $r->action, $status ?: '-', $r->remote_ip);
        echo paint($color, $line), "\n";
        $detail = trim((string) $r->response);
        if ($detail !== '' && ($status >= 300 || $r->action === 'alert' || $r->action === 'order.rollback')) {
            echo paint('grey', '        ' . substr($detail, 0, 300)), "\n";
        }
    }

    foreach (Capsule::table('mod_vpnhood_iap_events')->where('id', '>', $seen['events'])->orderBy('id')->get() as $r) {
        $seen['events'] = (int) $r->id;
        $color = $r->status === 'processed' ? 'blue' : ($r->status === 'failed' ? 'red' : 'grey');
        echo paint($color, sprintf('%s  EVENT %-18s %-10s %s',
            substr((string) $r->created_at, 11), $r->event_type, $r->status, $r->purchase_key ?? '')), "\n";
        if (!empty($r->error)) echo paint('red', '        ' . substr((string) $r->error, 0, 300)), "\n";
    }

    foreach (Capsule::table('mod_vpnhood_iap_purchases')->orderBy('id')->get() as $p) {
        $fingerprint = $p->status . '|' . $p->service_id . '|' . $p->whmcs_order_id . '|' . $p->updated_at;
        if (($seen['purchases'][$p->id] ?? null) === $fingerprint) continue;
        $isNew = !isset($seen['purchases'][$p->id]);
        $seen['purchases'][$p->id] = $fingerprint;
        if (!$isNew || strtotime((string) $p->created_at) >= strtotime($GLOBALS['sinceTs'] ?? '1970-01-01')) {
            $color = in_array($p->status, ['provisioned'], true) ? 'green'
                : (in_array($p->status, ['failed', 'refunded'], true) ? 'red' : 'yellow');
            echo paint($color, sprintf('%s  BUY   #%d %-28s %-12s client=%s order=%s service=%s%s',
                substr((string) $p->updated_at, 11), $p->id, $p->purchase_key, $p->status,
                $p->client_id ?: '-', $p->whmcs_order_id ?: '-', $p->service_id ?: '-',
                $p->is_test ? ' [test]' : '')), "\n";
            if (!empty($p->last_error)) echo paint('red', '        ' . substr((string) $p->last_error, 0, 300)), "\n";
        }
    }

    if ($follow) { flush(); sleep(2); }
} while ($follow);
PHP
)

echo "== watching vpnhoodiap on $SSH_HOST (last $SINCE_MINUTES min, follow=$FOLLOW)"
echo "   API = app calls · EVENT = store notifications · BUY = purchase state"
echo

printf '%s' "$REMOTE_PHP" | ssh -i "$SSH_KEY" -o BatchMode=yes "$SSH_HOST" \
  "IAP_WEBROOT='$WEBROOT' IAP_FOLLOW='$FOLLOW' IAP_SINCE='$SINCE_MINUTES' php"
