# Developer convenience targets for the local / devcontainer integration stack.
# CI does NOT use these (it uses GitHub Actions services: — see
# .github/workflows/integration.yml). docker-compose.yaml is dev-only.

COMPOSE ?= docker compose
NC_SVC  ?= nextcloud

.PHONY: stack-up stack-down stack-logs occ test-integration

## Bring the NC + n8n stack up and wait for both to be healthy.
stack-up:
	@test -f .env || (echo "copy .env.example to .env first"; exit 1)
	$(COMPOSE) up -d --wait

## Tear the stack down and remove volumes (ephemeral test data).
stack-down:
	$(COMPOSE) down -v

stack-logs:
	$(COMPOSE) logs -f

## Run an occ command in the running Nextcloud container.
##   make occ -- app:list
occ:
	$(COMPOSE) exec -T -u www-data $(NC_SVC) php occ $(filter-out $@,$(MAKECMDGOALS))

## Run the integration scaffolding test against the running stack.
test-integration:
	OCC="$(COMPOSE) exec -T -u www-data $(NC_SVC) php occ" \
		tests/integration/install-uninstall.sh

# Swallow extra goals so `make occ -- app:list` doesn't error on `app:list`.
%:
	@:
