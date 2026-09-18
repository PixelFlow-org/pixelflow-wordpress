#!/usr/bin/env bash
# Publishes docs/test-scenarios.html to the test site's uploads, at
# wp-content/uploads/pf-docs/test-scenarios.html. The site location comes from
# e2e/live/.env like the rest of the live suite, so no hostname is committed.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIVE_DIR="$(dirname "$HERE")"
PLUGIN_DIR="$(dirname "$(dirname "$LIVE_DIR")")"

[ -f "$LIVE_DIR/.env" ] && set -a && . "$LIVE_DIR/.env" && set +a

SSH_HOST="${PF_SSH_HOST:?PF_SSH_HOST is not set (see e2e/live/.env.example)}"
WP_ROOT="${PF_WP_ROOT:?PF_WP_ROOT is not set (see e2e/live/.env.example)}"
BASE_URL="${PF_BASE_URL:?PF_BASE_URL is not set (see e2e/live/.env.example)}"
SSH_KEY="${PF_SSH_KEY:-}"

# With no explicit key, the operator's ~/.ssh/config entry for $SSH_HOST picks the identity.
if [ -n "$SSH_KEY" ]; then
  KEY_ARGS=(-i "${SSH_KEY/#\~/$HOME}" -o IdentitiesOnly=yes)
else
  KEY_ARGS=()
fi

REPORT="$PLUGIN_DIR/docs/test-scenarios.html"
DEST_DIR="$WP_ROOT/wp-content/uploads/pf-docs"

ssh "${KEY_ARGS[@]}" -o BatchMode=yes "$SSH_HOST" "mkdir -p '$DEST_DIR'"
scp "${KEY_ARGS[@]}" -o BatchMode=yes -q "$REPORT" "$SSH_HOST:$DEST_DIR/test-scenarios.html"
ssh "${KEY_ARGS[@]}" -o BatchMode=yes "$SSH_HOST" "chmod 0664 '$DEST_DIR/test-scenarios.html'"

echo "Published: ${BASE_URL%/}/wp-content/uploads/pf-docs/test-scenarios.html"
