#!/usr/bin/env bash
#
# test-dev.sh — run the vpnhoodiap test suites against the dev WHMCS
# (https://whmcs-dev.vpnhood.com). This box has no local PHP, so BOTH suites
# execute with the dev server's PHP over SSH:
#
#   unit         tests/unit/*.test.php — pure PHP, no WHMCS: this repo's
#                working tree (tests + module lib) is uploaded to a temp dir
#                and run there, independent of what is deployed
#   integration  tests/integration/*.test.php — run INSIDE the deployed
#                WHMCS install (init.php, localAPI, real DB); deploy first:
#                  (from the sibling VpnHood.WHMCS repo) scripts/deploy-dev.sh iap
#   endpoints    black-box HTTP checks of the deployed api.php/webhook.php
#
# Usage:
#   scripts/test-dev.sh [all|unit|integration|endpoints|<test-name>...]
#
#   <test-name> is an integration test basename, e.g. "activation" for
#   tests/integration/activation.test.php.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, WHMCS_DEV_URL

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/whmcs/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"
REMOTE_DIR="tmp/vpnhoodiap-tests"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

FAIL=0

# All integration tests, in dependency order (activation first: it activates
# the addon the others rely on).
INTEGRATION_TESTS=(activation identity secrets sessions suppress-emails webhook redeem)

upload() {
  echo "== Uploading tests + module lib to the dev box"
  "${SSH[@]}" "rm -rf $REMOTE_DIR && mkdir -p $REMOTE_DIR"
  tar -C "$REPO_ROOT" -cf - tests modules/addons/vpnhoodiap/lib \
    | "${SSH[@]}" "tar -C $REMOTE_DIR -xf -"
}

cleanup() {
  "${SSH[@]}" "rm -rf $REMOTE_DIR" || true
}

run_unit() {
  echo "== Unit suite (server PHP, no WHMCS)"
  if "${SSH[@]}" "php $REMOTE_DIR/tests/unit/run.php"; then
    echo "   unit suite OK"
  else
    echo "!! UNIT SUITE FAILED" >&2
    FAIL=1
  fi
}

run_integration_test() {
  local name="$1"
  local file="$REPO_ROOT/tests/integration/$name.test.php"
  [ -f "$file" ] || { echo "!! no such integration test: $name" >&2; FAIL=1; return; }
  echo "== Integration: $name"
  if "${SSH[@]}" "php $REMOTE_DIR/tests/integration/$name.test.php"; then
    echo "   $name OK"
  else
    echo "!! INTEGRATION TEST FAILED: $name" >&2
    FAIL=1
  fi
}

# One HTTP probe: POST $2 (or GET when $2 is GET) to $1, expect status $3 and
# body containing $4.
probe() {
  local url="$1" payload="$2" want_code="$3" want_body="$4" label="$5"
  local resp code body
  if [ "$payload" = "GET" ]; then
    resp="$("${SSH[@]}" "curl -sk -m 30 -w '\n%{http_code}' '$url'")"
  else
    resp="$("${SSH[@]}" "curl -sk -m 30 -w '\n%{http_code}' -X POST '$url' -H 'Content-Type: application/json' -d '$payload'")"
  fi
  code="$(printf '%s' "$resp" | tail -n1)"
  body="$(printf '%s' "$resp" | sed '$d')"
  if [ "$code" = "$want_code" ] && printf '%s' "$body" | grep -q "$want_body"; then
    echo "   PASS $label (HTTP $code)"
  else
    echo "!! FAIL $label — want HTTP $want_code + '$want_body', got HTTP $code: $(printf '%s' "$body" | head -c 200)" >&2
    FAIL=1
  fi
}

run_endpoints() {
  echo "== Endpoint checks (deployed api.php / webhook.php)"
  local api="$SITE_URL/modules/addons/vpnhoodiap/api.php"
  local hook="$SITE_URL/modules/addons/vpnhoodiap/webhook.php"
  probe "$api" '{"action":"ping"}'    200 '"success":true'  'ping answers the success envelope'
  probe "$api" '{"action":"nope"}'    400 '"success":false' 'unknown action is a clean 400'
  probe "$api" 'not json'             400 '"success":false' 'non-JSON body is a clean 400'
  probe "$api" 'GET'                  405 '"success":false' 'GET is rejected with 405'
  probe "$hook?store=bogus&t=x"  '{}' 404 '"success":false' 'webhook: unknown store is 404'
  probe "$hook?store=googleplay&t=wrong-token" '{}' 401 '"success":false' 'webhook: bad token is 401'
}

TARGETS=("${@:-all}")
NEED_UPLOAD=0
for t in "${TARGETS[@]}"; do
  [ "$t" = "endpoints" ] || NEED_UPLOAD=1
done

[ "$NEED_UPLOAD" = "1" ] && upload
trap cleanup EXIT

for t in "${TARGETS[@]}"; do
  case "$t" in
    all)
      run_unit
      for name in "${INTEGRATION_TESTS[@]}"; do run_integration_test "$name"; done
      run_endpoints
      ;;
    unit)        run_unit ;;
    integration) for name in "${INTEGRATION_TESTS[@]}"; do run_integration_test "$name"; done ;;
    endpoints)   run_endpoints ;;
    *)           run_integration_test "$t" ;;
  esac
done

if [ "$FAIL" -ne 0 ]; then
  echo "TESTS FINISHED WITH FAILURES" >&2
  exit 1
fi
echo "All tests OK"
