#!/usr/bin/env bash
# Verifies the site stays healthy while WooCommerce is deactivated, then puts
# WooCommerce back. PHP errors are routed into wp-content/debug.log for the
# duration of the check because the site's FPM log is root-only.
set -euo pipefail

SSH_KEY="${PF_SSH_KEY:-$HOME/.claude/keys/rift}"
SSH_HOST="${PF_SSH_HOST:-claude@rift.kskonovalov.me}"
WP_ROOT="${PF_WP_ROOT:-/var/www/rift.kskonovalov.me/www}"
WP="${PF_WP_CLI:-~/bin/wp}"
BASE_URL="${PF_BASE_URL:-https://rift.kskonovalov.me}"

remote() {
  ssh -i "$SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes "$SSH_HOST" "cd $WP_ROOT && $*"
}

restore() {
  remote "$WP plugin activate woocommerce" >/dev/null 2>&1 || true
  remote "$WP config set WP_DEBUG false --raw" >/dev/null 2>&1 || true
  remote "$WP config delete WP_DEBUG_LOG" >/dev/null 2>&1 || true
  remote "$WP config delete WP_DEBUG_DISPLAY" >/dev/null 2>&1 || true
}
trap restore EXIT

echo "→ enabling PHP error capture"
remote "$WP config set WP_DEBUG true --raw" >/dev/null
remote "$WP config set WP_DEBUG_LOG true --raw" >/dev/null
remote "$WP config set WP_DEBUG_DISPLAY false --raw" >/dev/null
remote ": > wp-content/debug.log"

echo "→ deactivating WooCommerce"
remote "$WP plugin deactivate woocommerce" >/dev/null

failed=0
for path in "/" "/wp-admin/"; do
  code=$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 30 "${BASE_URL}${path}" || echo 000)
  echo "   ${path} → HTTP ${code}"
  case "$code" in
    200|30[0-9]) ;;
    *) echo "   ✗ ${path} did not respond successfully"; failed=1 ;;
  esac
done

errors=$(remote "grep -i pixelflow wp-content/debug.log 2>/dev/null || true")
if [ -n "$errors" ]; then
  echo "   ✗ PHP errors mentioning the plugin while WooCommerce was off:"
  echo "$errors"
  failed=1
else
  echo "   ✓ no plugin-attributable PHP errors"
fi

echo "→ reactivating WooCommerce"
remote "$WP plugin activate woocommerce" >/dev/null
code=$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 30 "${BASE_URL}/" || echo 000)
echo "   / → HTTP ${code} (WooCommerce restored)"
[ "$code" = "200" ] || failed=1

exit "$failed"
