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

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${SITE_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac
REMOTE_DIR="tmp/vpnhoodiap-tests"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

FAIL=0

# All integration tests, in dependency order (activation first: it activates
# the addon the others rely on).
INTEGRATION_TESTS=(activation identity secrets sessions password-login suppress-emails webhook redeem claims delete-account)

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

# One HTTP probe: $1 $2 with body $3 ('-' = none), expecting status $4 and a
# body containing $5. Runs from the server itself, so it also proves the route
# survives the real web server (PATH_INFO in particular).
probe() {
  local method="$1" url="$2" payload="$3" want_code="$4" want_body="$5" label="$6"
  local resp code body curl_cmd
  curl_cmd="curl -sk -m 30 -w '\n%{http_code}' -X $method '$url'"
  [ "$payload" = "-" ] || curl_cmd="$curl_cmd -H 'Content-Type: application/json' -d '$payload'"
  resp="$("${SSH[@]}" "$curl_cmd")"
  code="$(printf '%s' "$resp" | tail -n1)"
  body="$(printf '%s' "$resp" | sed '$d')"
  if [ "$code" = "$want_code" ] && printf '%s' "$body" | grep -q "$want_body"; then
    echo "   PASS $label (HTTP $code)"
  else
    echo "!! FAIL $label — want HTTP $want_code + '$want_body', got HTTP $code: $(printf '%s' "$body" | head -c 200)" >&2
    FAIL=1
  fi
}

# The Portal API contract as a black box: routing, verbs, auth and the
# problem+json error shape. Nothing here needs store credentials or a session.
run_endpoints() {
  echo "== Endpoint checks (deployed api.php / webhook.php)"
  local api="$SITE_URL/modules/addons/vpnhoodiap/api.php"
  local hook="$SITE_URL/modules/addons/vpnhoodiap/webhook.php"

  probe GET "$api/v1/system/status" - 200 '"status":"ok"' 'status answers over PATH_INFO'
  probe GET "$api?path=/v1/system/status" - 200 '"status":"ok"' 'the ?path= form routes identically'
  probe GET "$api/openapi.json" - 200 '"openapi"' 'the contract is served from the module'
  probe GET "$api/v1/nope" - 404 '"code":"not_found"' 'unknown resource is a clean 404'
  probe GET "$api/v1/account" - 401 '"code":"unauthorized"' 'a protected resource needs a session'
  probe DELETE "$api/v1/account" - 401 '"code":"unauthorized"' 'account deletion needs a session'
  probe POST "$api/v1/account" '{}' 405 '"code":"method_not_allowed"' 'wrong verb on a real resource is 405'
  probe GET "$api/v1/billing/products?store=googleplay&packageName=no.such.app" - 403 '"code":"unknown_app"' \
    'the product catalog is public — an unknown app is 403, never 401'
  probe GET "$api/v1/billing/products" - 400 '"code":"bad_request"' 'products without store/packageName is a clean 400'
  probe POST "$api/v1/billing/purchases" '{}' 401 '"code":"unauthorized"' 'purchases need a session'
  probe POST "$api/v1/auth/sessions" '{}' 400 '"code":"bad_request"' 'sign-in without an id token is a clean 400'
  probe POST "$api/v1/auth/sessions" 'not json' 400 '"code":"bad_request"' 'non-JSON body is a clean 400'
  probe POST "$api/v1/auth/sessions" '{"provider":"nope","idToken":"x","packageName":"y"}' \
    400 '"code":"unsupported_provider"' 'an unknown sign-in provider names itself'

  probe POST "$hook?store=bogus&t=x" '{}' 404 '"success":false' 'webhook: unknown store is 404'
  probe POST "$hook?store=googleplay&t=wrong-token" '{}' 401 '"success":false' 'webhook: bad token is 401'
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
