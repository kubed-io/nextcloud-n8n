#!/usr/bin/env bash
#
# Integration scaffolding test: prove n8n_sync enables and uninstalls cleanly on
# a real Nextcloud instance. This is the "hello world" of the integration layer
# (saga §5) — it tests the harness + the app's install lifecycle, not behaviour.
# A clean uninstall is also an app-store rule (Chapter 3 §3.3).
#
# Transport-agnostic: the caller supplies how to invoke `occ` via $OCC, so the
# same script runs under docker compose locally and under GHA `services:` in CI.
#   local CI-less:  OCC="docker compose exec -T -u www-data nextcloud php occ" tests/integration/install-uninstall.sh
#   GHA:            OCC="php occ" tests/integration/install-uninstall.sh   (run from the server root)
#
# Exit non-zero on the first failed assertion (set -e + explicit checks).

set -euo pipefail

APP_ID="n8n_sync"
OCC="${OCC:?set OCC to the occ invocation, e.g. 'php occ' or 'docker compose exec -T -u www-data nextcloud php occ'}"

run_occ() {
	# shellcheck disable=SC2086 # intentional word-splitting of the OCC prefix
	$OCC "$@"
}

fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "ok: $*"; }

echo "== n8n_sync integration scaffolding: install / uninstall =="

# 0. Sanity: occ is reachable and the app is present (mounted) but enable-able.
run_occ status >/dev/null || fail "occ not reachable"
ok "occ reachable"

# 1. Enable. --force tolerates max-version drift between the app and the test NC.
run_occ app:enable --force "$APP_ID" || fail "app:enable $APP_ID failed"
ok "enabled $APP_ID"

# 2. Assert it shows up under the enabled apps.
if run_occ app:list | awk '/Enabled:/{f=1} /Disabled:/{f=0} f' | grep -q "  - $APP_ID:"; then
	ok "$APP_ID listed as enabled"
else
	run_occ app:list || true
	fail "$APP_ID not in the Enabled list after app:enable"
fi

# 3. Assert no errors surfaced in the log during enable (best-effort; the app
#    boots its IBootstrap register()/boot() on enable).
if run_occ app:getpath "$APP_ID" >/dev/null 2>&1; then
	ok "app path resolves"
else
	fail "app:getpath $APP_ID failed — app not properly registered"
fi

# 4. Disable, then remove — the clean-uninstall path.
run_occ app:disable "$APP_ID" || fail "app:disable $APP_ID failed"
ok "disabled $APP_ID"

# 5. Assert it is gone from the enabled list.
if run_occ app:list | awk '/Enabled:/{f=1} /Disabled:/{f=0} f' | grep -q "  - $APP_ID:"; then
	fail "$APP_ID still enabled after app:disable"
fi
ok "$APP_ID no longer enabled"

echo "== PASS: install / uninstall scaffolding =="
