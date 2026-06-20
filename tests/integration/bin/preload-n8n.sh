#!/usr/bin/env bash
#
# Preload the n8n service with example workflows for the integration tests — a
# CONTROL CASE that does NOT rely on the app under test or on our test logic.
# These are "workflows that already exist in n8n", created straight through n8n's
# own public API, so mapping/pull scenarios have real, pre-existing resources to
# act on (the equivalent of "an n8n admin already built some flows").
#
# Each example (tests/workflows/*.json) is created and tagged with the n8n tag a
# mapping scenario will bind to:
#   Alpha Demo   -> nextcloud:alpha
#   Bravo Demo   -> nextcloud:bravo
#   Charlie Demo -> nextcloud:charlie
#   Delta Demo   -> nextcloud:delta
#
# Inputs (env):  N8N_URL, N8N_API_KEY (minted by mint-n8n-key.sh)
# Idempotent-ish: intended for a fresh CI n8n; re-runs would create duplicates.

set -euo pipefail

: "${N8N_URL:?N8N_URL is required}"
: "${N8N_API_KEY:?N8N_API_KEY is required (run mint-n8n-key.sh first)}"

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../workflows" && pwd)"

api() {
  # api <method> <path> [json-body]
  local method="$1" path="$2" body="${3:-}"
  if [ -n "$body" ]; then
    curl -fsS -X "$method" "$N8N_URL/api/v1$path" \
      -H "X-N8N-API-KEY: $N8N_API_KEY" -H 'Content-Type: application/json' -d "$body"
  else
    curl -fsS -X "$method" "$N8N_URL/api/v1$path" -H "X-N8N-API-KEY: $N8N_API_KEY"
  fi
}

# Create a tag, returning its id (n8n requires tags to exist before attaching).
create_tag() {
  api POST '/tags' "{\"name\":\"$1\"}" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["id"])'
}

# Create a workflow from a file (strip to the API-accepted fields), return its id.
create_workflow() {
  local file="$1"
  local body
  body="$(python3 -c '
import sys, json
w = json.load(open(sys.argv[1]))
print(json.dumps({"name": w["name"], "nodes": w["nodes"],
                  "connections": w["connections"], "settings": w.get("settings", {})}))
' "$file")"
  api POST '/workflows' "$body" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["id"])'
}

attach_tag() {
  # attach_tag <workflowId> <tagId>
  api PUT "/workflows/$1/tags" "[{\"id\":\"$2\"}]" >/dev/null
}

preload() {
  # preload <file> <tag>
  local file="$1" tag="$2"
  local wf tagid
  wf="$(create_workflow "$here/$file")"
  tagid="$(create_tag "$tag")"
  attach_tag "$wf" "$tagid"
  echo "  loaded $file -> workflow $wf, tag $tag"
}

echo "== preloading n8n with example workflows =="
preload alpha-demo.json   nextcloud:alpha
preload bravo-demo.json   nextcloud:bravo
preload charlie-demo.json nextcloud:charlie
preload delta-demo.json   nextcloud:delta
echo "== preload complete =="
