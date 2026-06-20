#!/usr/bin/env bash
#
# Mint an n8n public-API key for the integration tests — a PREREQUISITE, not a
# feature. The app under test does nothing about how an n8n key is created; this
# only exists so the test can act as "an admin who already pasted a valid key".
#
# How (proven recipe, saga §5 Stage 2): log in to the n8n service as the
# env-provisioned owner over the INTERNAL REST API, then create a public API key.
# n8n has no headless key mint, so this two-step login→create is the way.
#
#   POST /rest/login      -> sets an n8n-auth session cookie
#   POST /rest/api-keys   -> returns { data: { rawApiKey: "<jwt>" } }
#
# IMPORTANT: n8n's auth cookie attributes make curl's cookie *jar* drop it, so we
# read the Set-Cookie header and replay it verbatim.
#
# Inputs (env):  N8N_URL, N8N_OWNER_EMAIL, N8N_OWNER_PASSWORD
# Output:        the raw API key on stdout (nothing else). Exits non-zero if it
#                cannot obtain a key — the pipeline must fail loud before tests.

set -euo pipefail

: "${N8N_URL:?N8N_URL is required}"
N8N_OWNER_EMAIL="${N8N_OWNER_EMAIL:-owner@example.com}"
N8N_OWNER_PASSWORD="${N8N_OWNER_PASSWORD:-n8npassword}"

# 1. Log in; capture the n8n-auth cookie from the response headers (not the jar).
cookie=$(
  curl -fsS -D - -o /dev/null -X POST "$N8N_URL/rest/login" \
    -H 'Content-Type: application/json' \
    -d "{\"emailOrLdapLoginId\":\"$N8N_OWNER_EMAIL\",\"password\":\"$N8N_OWNER_PASSWORD\"}" \
  | grep -i '^set-cookie:' | sed 's/^[Ss]et-[Cc]ookie: *//' | cut -d';' -f1 | paste -sd'; ' -
)
if [ -z "$cookie" ]; then
  echo "mint-n8n-key: login did not return an auth cookie" >&2
  exit 1
fi

# 2. Create a public API key with the session cookie.
raw=$(
  curl -fsS -X POST "$N8N_URL/rest/api-keys" \
    -H 'Content-Type: application/json' \
    -H "Cookie: $cookie" \
    -d '{"label":"integration-tests","expiresAt":null,"scopes":["workflow:read","workflow:list","workflow:create","workflow:update","workflow:delete","workflow:move"]}' \
  | sed -n 's/.*"rawApiKey":"\([^"]*\)".*/\1/p'
)
if [ -z "$raw" ]; then
  echo "mint-n8n-key: /rest/api-keys did not return a rawApiKey" >&2
  exit 1
fi

printf '%s' "$raw"
