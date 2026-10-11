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

# EU egress for the browser. PixelFlow picks the consent regime from the visitor's IP, and
# outside the EU it answers opt_out: the tracking script then drops _pf_no_consent_decision
# and every consent scenario tests the wrong regime while the banner still shows. The browser
# therefore reaches the internet through a SOCKS tunnel to the test server, and the run
# refuses to start unless that exit is in the EEA. PF_EU_EGRESS=off skips it, for a machine
# that already has an EU address.
if [ "${PF_EU_EGRESS:-on}" != "off" ]; then
  : "${PF_SSH_HOST:?PF_SSH_HOST is not set (see e2e/live/.env.example)}"
  PROXY_PORT="${PF_EU_PROXY_PORT:-1089}"
  KEY_ARGS=()
  if [ -n "${PF_SSH_KEY:-}" ]; then
    KEY_ARGS=(-i "${PF_SSH_KEY/#\~/$HOME}" -o IdentitiesOnly=yes)
  fi

  # A connection of its own, outside the suite's multiplexed one, so closing it touches nothing else.
  ssh "${KEY_ARGS[@]}" -o BatchMode=yes -o ExitOnForwardFailure=yes \
    -o ControlMaster=no -o ControlPath=none \
    -N -D "127.0.0.1:$PROXY_PORT" "$PF_SSH_HOST" &
  TUNNEL_PID=$!
  trap 'kill "$TUNNEL_PID" 2>/dev/null || true' EXIT

  # The first service that answers with a two-letter code wins. A single lookup service can
  # rate-limit the test server's address (ipinfo.io answers 429 with a JSON body), and that
  # must not stop a run whose exit is fine.
  exit_country() {
    local url code
    for url in https://www.cloudflare.com/cdn-cgi/trace https://ifconfig.co/country-iso https://ipinfo.io/country; do
      # Cloudflare's trace carries the country as `loc=DE`; the others answer with the bare code.
      code="$(curl -s --max-time 5 --socks5-hostname "127.0.0.1:$PROXY_PORT" "$url" 2>/dev/null \
        | sed -n -e 's/^loc=//p' -e '/^[A-Z][A-Z][[:space:]]*$/p' | head -n 1 | tr -d '[:space:]')"
      if [[ "$code" =~ ^[A-Z]{2}$ ]]; then
        echo "$code"
        return
      fi
    done
  }

  EXIT_COUNTRY=""
  for _ in $(seq 1 15); do
    EXIT_COUNTRY="$(exit_country || true)"
    [ -n "$EXIT_COUNTRY" ] && break
    kill -0 "$TUNNEL_PID" 2>/dev/null || break
    sleep 1
  done

  EEA=" AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE IS LI NO "
  if [ -z "$EXIT_COUNTRY" ] || [[ "$EEA" != *" $EXIT_COUNTRY "* ]]; then
    echo "The browser's exit is '${EXIT_COUNTRY:-unreachable}', not an EEA country: the consent" >&2
    echo "scenarios would run under PixelFlow's opt_out regime. Check the tunnel to $PF_SSH_HOST," >&2
    echo "or set PF_EU_EGRESS=off on a machine that already has an EU address." >&2
    exit 1
  fi
  export PF_BROWSER_PROXY="socks5://127.0.0.1:$PROXY_PORT"
  echo "Browser egress: $EXIT_COUNTRY through $PF_SSH_HOST"
fi

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
