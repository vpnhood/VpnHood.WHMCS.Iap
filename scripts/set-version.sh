#!/usr/bin/env bash
#
# set-version.sh — propagate the repo version into the module.
#
# The root VERSION file is the single source of truth. vpnhoodiap deliberately has
# its OWN version stream, independent of the hub and partner repos: the same module
# code ships inside both the hub and partner packages, and WHMCS compares the
# version in vpnhoodiap_config() against tbladdonmodules to decide whether to run
# _upgrade() — so the same code must carry the same number on every install.
# The consuming packages copy this module verbatim and never restamp it.
#
# Usage:
#   scripts/set-version.sh            # apply VERSION to the module
#   scripts/set-version.sh 1.4.2      # set VERSION to 1.4.2, then apply
#   scripts/set-version.sh --check    # verify the module matches VERSION (exit 1 if not)
#
# (Trimmed from VpnHood.WHMCS scripts/set-version.sh — one addon module, no
# whmcs.json manifests.)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION_FILE="$REPO_ROOT/VERSION"

CHECK_ONLY=0
[ "${1:-}" = "--check" ] && { CHECK_ONLY=1; shift || true; }

# An explicit version argument rewrites VERSION first.
if [ $# -gt 0 ] && [ -n "$1" ]; then
  printf '%s\n' "$1" > "$VERSION_FILE"
fi

[ -f "$VERSION_FILE" ] || { echo "!! missing $VERSION_FILE" >&2; exit 1; }
VERSION="$(tr -d ' \t\r\n' < "$VERSION_FILE")"

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "!! VERSION must be MAJOR.MINOR.PATCH, got '$VERSION'" >&2
  exit 1
fi

# Addon modules: the 'version' key inside <module>_config().
PHP_MODULES=(
  "modules/addons/vpnhoodiap/vpnhoodiap.php"
)

FAIL=0

# Rewrite  'version' => '...'  in a module's _config(). Exactly one such key is
# expected; bailing out otherwise stops us silently editing the wrong line.
apply_php() {
  local rel="$1" path="$REPO_ROOT/$1" count current
  [ -f "$path" ] || { echo "!! missing $rel" >&2; FAIL=1; return; }

  count="$(grep -cE "'version'[[:space:]]*=>" "$path" || true)"
  if [ "$count" != "1" ]; then
    echo "!! $rel: expected exactly one 'version' key, found $count" >&2
    FAIL=1
    return
  fi

  current="$(sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']*)'.*/\1/p" "$path")"
  if [ "$CHECK_ONLY" = "1" ]; then
    [ "$current" = "$VERSION" ] \
      && echo "   ok      $rel ($current)" \
      || { echo "!! stale   $rel ($current, want $VERSION)" >&2; FAIL=1; }
    return
  fi

  # -i.bak keeps this portable between GNU sed and the BSD sed on macOS.
  sed -i.bak -E "s/('version'[[:space:]]*=>[[:space:]]*')[^']*(')/\1$VERSION\2/" "$path"
  rm -f "$path.bak"
  echo "   set     $rel  $current -> $VERSION"
}

if [ "$CHECK_ONLY" = "1" ]; then
  echo "Checking modules against VERSION $VERSION"
else
  echo "Applying VERSION $VERSION to all modules"
fi

for m in "${PHP_MODULES[@]}"; do apply_php "$m"; done

if [ "$FAIL" -ne 0 ]; then
  if [ "$CHECK_ONLY" = "1" ]; then
    echo "VERSION MISMATCH — run scripts/set-version.sh to re-sync" >&2
  else
    echo "FAILED to apply version to every module" >&2
  fi
  exit 1
fi

echo "All modules at $VERSION"
