#!/usr/bin/env bash
# Full live verification run: build → deploy → WooCommerce smoke → event matrix.
# Artifacts (screenshots, traces, log excerpts, results.json) land in a temp dir
# whose path is printed at the end.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIVE_DIR="$(dirname "$HERE")"
E2E_DIR="$(dirname "$LIVE_DIR")"
PLUGIN_DIR="$(dirname "$E2E_DIR")"

[ -f "$LIVE_DIR/.env" ] && set -a && . "$LIVE_DIR/.env" && set +a

: "${PF_ADMIN_PASS:?set PF_ADMIN_PASS (copy e2e/live/.env.example to .env)}"
: "${PF_CUSTOMER_PASS:?set PF_CUSTOMER_PASS (copy e2e/live/.env.example to .env)}"

export PF_ARTIFACTS_DIR="${PF_ARTIFACTS_DIR:-$(mktemp -d -t pixelflow-live-XXXXXX)}"
echo "Artifacts: $PF_ARTIFACTS_DIR"

echo
echo "== 1/4 build =="
( cd "$PLUGIN_DIR" && sh build_plugin.sh prod )
export PF_PLUGIN_ZIP="$PLUGIN_DIR/build/pixelflow.zip"
export PF_PLUGIN_VERSION="$(sed -n "s/^ \* Version: *//p" "$PLUGIN_DIR/pixelflow.php" | head -1 | tr -d '[:space:]')"
echo "Built $PF_PLUGIN_ZIP (version $PF_PLUGIN_VERSION)"

echo
echo "== 2/4 deploy through the WordPress plugin installer =="
( cd "$LIVE_DIR" && npx playwright test --config=playwright.config.ts --project=deploy )

echo
echo "== 3/4 WooCommerce deactivate / reactivate smoke =="
"$HERE/woo-smoke.sh"

echo
echo "== 4/4 event matrix =="
( cd "$LIVE_DIR" && npx playwright test --config=playwright.config.ts --project=live "$@" )

echo
echo "Run complete. Artifacts: $PF_ARTIFACTS_DIR"
