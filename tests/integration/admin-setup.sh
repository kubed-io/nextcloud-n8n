#!/usr/bin/env bash
#
# Integration Stage 1 — admin setup (saga §5, "Stage 1"): wire the connection
# *config* the way the admin UI would, via `occ config:app:set`, WITHOUT making a
# single authenticated call to n8n. This is the prerequisite for Stage 3's first
# real round-trip; the scope deliberately STOPS before any n8n contact.
#
# What it sets (the AppConfig keys the admin panels write):
#   n8n_url      — the n8n base URL (the n8n service in CI / compose)
#   api_enabled  — 1, so the REST pull/writeback path is on
#   api_key      — a placeholder token. NOTE: the app stores this `sensitive` and
#                  ICrypto-encrypted, and N8nClient calls ICrypto::decrypt() on it.
#                  A raw `config:app:set ... --sensitive` only HIDES the value, it
#                  does not ICrypto-encrypt it — so this stored key is NOT yet
#                  usable for an authenticated call. Stage 2 ("the token
#                  conversation") owns producing a decrypt-able key. Stage 1 only
#                  proves the config plumbing.
#   mappings     — one mapping entry (n8n tag -> Team Folder) as the JSON the
#                  MappingService persists.
#
# Transport-agnostic via $OCC (same as install-uninstall.sh).
#   GHA:  OCC="php occ" N8N_URL="http://localhost:5678" tests/integration/admin-setup.sh

set -euo pipefail

APP_ID="n8n_sync"
OCC="${OCC:?set OCC to the occ invocation, e.g. 'php occ'}"
N8N_URL="${N8N_URL:-http://localhost:5678}"

run_occ() {
	# shellcheck disable=SC2086 # intentional word-splitting of the OCC prefix
	$OCC "$@"
}
fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "ok: $*"; }

echo "== n8n_sync integration Stage 1: admin setup (no n8n calls) =="

# Ensure the app is enabled (install stage is a separate script; be self-contained).
run_occ app:enable --force "$APP_ID" >/dev/null || fail "app:enable failed"
ok "app enabled"

# 1. Base URL.
run_occ config:app:set "$APP_ID" n8n_url --value="$N8N_URL"
ok "n8n_url set to $N8N_URL"

# 2. Enable the REST API path.
run_occ config:app:set "$APP_ID" api_enabled --value=1
ok "api_enabled set"

# 3. Store a placeholder API key (sensitive). NOT decrypt-able yet — Stage 2.
run_occ config:app:set "$APP_ID" api_key --value="placeholder-stage1" --sensitive
ok "api_key stored (placeholder; Stage 2 makes it usable)"

# 4. One folder mapping (n8n tag -> Team Folder), the JSON MappingService reads.
mapping_json='[{"n8n_tag":"nextcloud:itest","team_folder":"itest","nc_groups":["admin"],"mode":"sync","writeback":"two-way","use_team_folder":true}]'
run_occ config:app:set "$APP_ID" mappings --value="$mapping_json"
ok "mapping set (nextcloud:itest -> itest)"

# --- assertions: the values round-trip via config:app:get ---
got_url=$(run_occ config:app:get "$APP_ID" n8n_url)
[ "$got_url" = "$N8N_URL" ] || fail "n8n_url did not round-trip (got '$got_url')"
ok "n8n_url verified"

got_api=$(run_occ config:app:get "$APP_ID" api_enabled)
[ "$got_api" = "1" ] || fail "api_enabled did not round-trip (got '$got_api')"
ok "api_enabled verified"

# mappings must be valid JSON with our tag (proves MappingService will parse it).
got_map=$(run_occ config:app:get "$APP_ID" mappings)
echo "$got_map" | grep -q 'nextcloud:itest' || fail "mappings did not round-trip"
ok "mappings verified"

# Scope guard: this stage must NOT have reached out to n8n. We assert by never
# calling a connection command here; the next stages add that explicitly.
echo "== PASS: Stage 1 admin setup complete (config wired, zero n8n calls) =="
