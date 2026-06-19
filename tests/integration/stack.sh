#!/usr/bin/env bash
#
# Dev convenience for the local / devcontainer integration stack (NC + n8n).
# Plain bash — CI does NOT use this (it uses the workflow in
# .github/workflows/integration.yml). docker-compose.yaml is dev-only (saga §4a).
#
# Usage (from the repo root):
#   tests/integration/stack.sh up                 # start + wait healthy
#   tests/integration/stack.sh down               # stop + drop volumes
#   tests/integration/stack.sh logs               # follow logs
#   tests/integration/stack.sh occ app:list       # run occ in the NC container
#   tests/integration/stack.sh test               # run the install/uninstall test
#
# Run from the repo root so docker compose finds docker-compose.yaml + .env.

set -euo pipefail

COMPOSE=${COMPOSE:-docker compose}
NC_SVC=${NC_SVC:-nextcloud}
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

occ() { $COMPOSE exec -T -u www-data "$NC_SVC" php occ "$@"; }

cmd=${1:-}; shift || true
case "$cmd" in
	up)
		[ -f .env ] || { echo "copy .env.example to .env first" >&2; exit 1; }
		$COMPOSE up -d --wait
		;;
	down)  $COMPOSE down -v ;;
	logs)  $COMPOSE logs -f ;;
	occ)   occ "$@" ;;
	test)  OCC="$COMPOSE exec -T -u www-data $NC_SVC php occ" "$HERE/install-uninstall.sh" ;;
	*)
		echo "usage: tests/integration/stack.sh {up|down|logs|occ <args>|test}" >&2
		exit 2
		;;
esac
