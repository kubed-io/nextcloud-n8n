# Chapter 2 — Pretty Package

> The app works (Chapter 1). Now make it something a contributor can pick up cold,
> a CI pipeline can ship, and eventually the Nextcloud app store can accept (Chapter 3).
> This chapter is the bridge.

---

## Where we are

Phase 0–5 are live in production on the homelab cluster. The code lives in a public
GitHub repo. A working release pipeline exists but is rough around the edges. The
development story is undocumented. That's what this chapter fixes.

> **Progress (2026-06-20):** unit + CI green and surfaced in the GitHub UI (§5.1, §10);
> contribution infra shipped (CONTRIBUTING/AGENTS/SECURITY, PR-housekeeping, ESLint,
> Dependabot — PRs #3/#5/#7); code-scanning paydown 239→14 (§12.1). The **integration suite
> is now live and authenticated**: Behat on real NC + n8n, results in a sticky PR comment.
> Done stages — install (§5 Stage 0, PR #12), admin connection incl. the **defeated token**
> (Stages 1–3, PRs #20/#22/#23), and **admin mapping** (Stage 3a, PR #24) with example
> workflows preloaded as a control case. A small **occ admin CLI** was added alongside (§5.4).
> Remaining: the CRUD safety net (§5 Stage 4 — pull/writeback/delete/rename), the §4a
> docker-compose stack for local/devcontainer parity, and the rest of the GitHub **Security**
> track (Dependabot security updates, secret scanning, dependency review, branch protection).

---

## The epics (this chapter's arc)

A chapter is a large arc; these are the epic-sized units inside it (the numbered §items below
are the detailed backlog under them). Roughly in order.

**The through-line: each testing layer paid for itself as a refactor.** The devops + unit +
static-analysis work (epics 1–3) *uncovered* a pile of issues — which the **first refactor
(security)** then cleaned up. The **integration suite** (epic 4) is the safety net that now
makes the **second refactor** (the mode-model overhaul + motion, epic 6) safe to attempt at
all. Testing wasn't a gate bolted on at the end; it was the thing that made each refactor
possible.

| # | Epic | Status | Detail |
|---|---|---|---|
| 1 | **DevOps workflows** — publish/test/quality/integration CI | ✅ | §2, §10, §13.1 |
| 2 | **GitHub project setup** — repo, contributing/agents/standards, PR flow, security | ✅ / ⚠️ | §1, §6–8, §11, §13 |
| 3 | **Testing: unit** | ✅ | §5.1 |
| 4 | **Testing: integration** — Behat on real NC + n8n (create/rename/delete live) | ✅ | §5.3 |
| 5 | **First refactor — security** — static-analysis/code-scanning paydown (Psalm 239→14), `IConfig → IAppConfig` migration | ✅ | §12, §12.1 |
| 6 | **Second refactor + edge-case features** — the mode-model overhaul (sync/link/unmapped; drop backup/writeback) **+** the motion lifecycle (move-out/restore, copy-strips, merge), *made safe by the integration suite* | ☐ | §14 |
| 7 | **Secondary refactor** — a cleanup pass once the edge-case work reveals the real shape (scope TBD) | ☐ | §14 (follow-on) |

Epics 1–5 are delivered (the **first refactor was the security pass**); **6 is next** — the
mode/motion work the testing was built to make safe. The chapter closes — and **branding**
(Ch3 §3.1) begins — when Kelly judges the app fully functional and usable for the market.

---

## The items (loose chronological order; status noted per item)

### 1. Public repo ✅

The POC was extracted and pushed to a public GitHub repo. This is the foundation
everything else here builds on.

### 2. Initial publish workflow ✅

A `publish.yml` GitHub Actions workflow exists. It:
- accepts a version bump type (patch / minor / major / pre-*) via `workflow_dispatch`
- runs `npm version` to compute the next semver
- mirrors the version into `appinfo/info.xml`
- builds the JS bundle
- packages a Nextcloud-conformant tarball (`n8n_sync-<version>.tar.gz`)
- uses `duplocloud/version-bump` to commit, tag, and generate changelog
- creates a GitHub Release with the tarball attached as an artifact

A `push` boolean input gates whether commits and the release are actually pushed,
so the workflow can be run dry first. GitHub App authentication (client ID + private key)
is used to produce a token that can bypass branch protection on the version bump commit.

**Status:** functional but untested end-to-end against a real run. The main unknowns
are the GitHub App token flow and whether the tarball structure satisfies Nextcloud's
layout expectations.

### 3. Devcontainer ✅ (started, untested)

A `.devcontainer/devcontainer.json` exists. It provides:
- PHP 8.3 base image
- Node (via nvm, version from `.nvmrc`)
- GitHub CLI
- Docker-outside-of-Docker (for spinning up a Nextcloud test container)
- VS Code extensions for PHP (Intelephense + Xdebug), YAML, GitHub Actions, Copilot

`postCreateCommand` installs Node deps. The devcontainer gives contributors a
zero-setup path to the toolchain.

**Status:** defined but not tested. Needs a real run-through: does `postCreateCommand`
succeed, does PHP tooling resolve, can a Nextcloud container actually be reached from
inside the container for `occ` calls?

### 4. Testing — due diligence and plan ✅ (researched)

The Nextcloud ecosystem has a well-established, two-language testing story. Here is what
the community actually does, based on studying the official docs and production apps
(nextcloud/deck, nextcloud/integration_openai).

#### The unit ↔ integration boundary (read this first)

The original research below described both kinds but never drew a hard line between them.
This is that line — it is the single most important decision in the test strategy because
it dictates what infrastructure each layer needs (and therefore what CI must spin up).

A test is a **unit test** iff it can run with **nothing but PHP + the class under test**
(plus mocked collaborators). No Nextcloud server, no database, no n8n, no network. These
are fast (milliseconds), deterministic, and run on every PR with zero services. Our pure-
logic classes (`FilenameCodec`, `Mapping` validation, `SyncGuard`, interval parsing,
`DeleteService` rule table) are all unit-testable because they have no NC dependencies, or
their NC dependencies are interfaces that can be mocked against `nextcloud/ocp`.

A test is an **integration test** iff it needs a **real running system** — a live Nextcloud
instance (with a real DB), a live n8n, or both talking to each other over HTTP. These prove
the wiring the unit tests deliberately mock out: pull writes real files with real metadata,
a save really reaches n8n, a trash really archives. They are slow, need services, and are
the natural consumer of the docker-compose stack in §4a.

| | **Unit** | **Integration** |
|---|---|---|
| Needs NC server | no | yes (real instance + DB) |
| Needs n8n | no | yes (real or stubbed HTTP) |
| Needs the docker stack (§4a) | no | yes |
| Speed | ms | seconds–minutes |
| Runs on every PR | yes (now) | yes, once the stack exists (later) |
| Mocks | all collaborators | nothing — the point is the real wiring |
| First delivery | **this chapter** | scaffolded later against §4a |

**Delivery decision:** ship the **unit** layer first (it needs no infrastructure and turns
the PR quality gate green immediately). The **integration** layer is scaffolded afterwards
on top of the §4a docker-compose stack, which is the same stack the devcontainer and the
GitHub Actions `services:` block reuse. Unit and integration are separate PHPUnit suites
(separate config files, separate `composer` scripts, separate CI jobs) so one can be green
while the other is still being built.

#### Backend — PHP / PHPUnit

The standard is **PHPUnit** (currently v9.x in active NC apps). There is no NC-specific
test runner; it's vanilla PHPUnit wired through Composer scripts (`composer run test:unit`,
`composer run test:integration`). The community convention:

- **Unit tests** — live in `tests/unit/`, mirror the `lib/` tree by class. No live NC
  instance needed. NC dependencies are mocked using PHPUnit's built-in mock builder against
  the `nextcloud/ocp` interfaces package (installed as a `require-dev` dep). The test bootstrap
  (`tests/bootstrap.php`) requires `nextcloud/server`'s own `tests/bootstrap.php` (which
  bootstraps the NC autoloader) and the app's `autoload.php`. This means unit tests must
  be run from inside a checked-out NC server tree, or the bootstrap path must be adjusted
  for standalone operation.

- **Integration tests** — live in `tests/integration/`. Require a real, running NC instance.
  The CI pattern (Deck, openai) checks out `nextcloud/server` at the target NC version,
  installs it with `occ maintenance:install`, enables the app, then runs PHPUnit against
  the live instance. SQLite is used for speed in CI; MySQL/Postgres runs as a service for
  the database-specific matrix.

- **Static analysis** — **Psalm** (`vimeo/psalm` + `nextcloud/ocp` stubs) is the community
  standard for type-checking PHP. Apps maintain a `psalm.xml` and a `tests/psalm-baseline.xml`.
  Runs as a separate CI job on every PR (`psalm.yml`).

- **Code style** — `php-cs-fixer` with `nextcloud/coding-standard` config (`.php-cs-fixer.dist.php`).
  Separate lint job in CI.

- **Useful library** — [`christophwurst/nextcloud_testing`](https://github.com/ChristophWurst/nextcloud_testing)
  provides convenience traits: `DatabaseTransaction` (auto-rollback per test), `TestUser`
  (random UID generation). MIT licensed, NC 25+. Worth pulling in for integration tests.

Key `composer.json` shape (what the NC template workflow looks for):
```
"scripts": {
  "test:unit": "vendor/bin/phpunit -c tests/phpunit.xml",
  "test:integration": "vendor/bin/phpunit -c tests/phpunit.integration.xml"
}
```
The standard CI workflow (`nextcloud/.github` org template) checks for these exact script
names and skips gracefully if absent — so they're the conventional entry points.

#### Frontend — JavaScript / Cypress

The Nextcloud community standard for frontend e2e testing is **Cypress** (v13.x), not
Playwright or Jest alone. Deck uses Cypress for all browser-level tests with the
`@nextcloud/cypress` helper package (`^1.0.0-beta.15`). For unit-level JS logic,
**Jest** (v29.x) with `@vue/test-utils` covers component and utility tests.

The NC ecosystem provides two official packages that eliminate the boilerplate of spinning
up a test instance:

- **`@nextcloud/e2e-test-server`** (`v0.4.0`, Oct 2025, still pre-1.0) — spins up a
  Nextcloud Docker container for Cypress or Playwright. Exports `startNextcloud`,
  `stopNextcloud`, `waitOnNextcloud`, `configureNextcloud`. Wires into `cypress.config.js`
  lifecycle hooks; sets the container IP as `baseUrl`. This is the intended vehicle for
  integration-level browser tests.
  Docs: https://nextcloud-libraries.github.io/nextcloud-cypress/

- **`@nextcloud/cypress`** — Cypress commands, utils, and selectors tailored for NC's
  DOM structure. Companion to `@nextcloud/e2e-test-server`.

The Cypress CI pattern (Deck) spins up a real NC server in the workflow (PHP built-in
server on `localhost:8081`), installs the app, and runs Cypress against it — same docker-
outside-of-docker pattern the devcontainer already wires up. Cypress tests live in
`cypress/e2e/` and are split across parallel runners for speed.

#### Linting / static analysis on the JS side

- **ESLint** with `@nextcloud/eslint-config` — standard across all NC apps
- **Stylelint** with `@nextcloud/stylelint-config` — for CSS/SCSS
- **REUSE** (`reuse.yml`) — SPDX license header compliance, used by official NC apps

#### The combined picture

There is no single unified PHP+JS test runner, but the two sides compose cleanly under
`make` or a top-level Makefile (which Deck uses). The natural split:

| Layer | Tool | When runs |
|---|---|---|
| PHP syntax | `php -l` / `lint-php.yml` | every PR |
| PHP style | `php-cs-fixer` | every PR |
| PHP types | Psalm | every PR |
| PHP unit | PHPUnit unit suite | every PR |
| PHP integration | PHPUnit integration suite | every PR (SQLite fast path) |
| JS lint | ESLint + Stylelint | every PR |
| JS unit | Jest | every PR |
| Browser e2e | Cypress + NC Docker | every PR (can be nightly if slow) |
| Code scanning | CodeQL (JS) + Psalm SARIF (PHP) — §10/§13 | every PR + push to main |
| Supply chain | Dependabot + dep review + audits — §13 | PRs + scheduled |

#### Decisions made

- **Bootstrap strategy — DECIDED (split by layer).** The **unit** suite uses a **standalone
  bootstrap** (composer-autoloaded `lib/` + `nextcloud/ocp` for mockable interfaces); it runs
  with no NC server tree. The **integration** suite runs against the **§4a docker-compose
  stack** (the same stack the devcontainer and CI reuse), *not* a cloned `nextcloud/server`
  tree — the live-container path is higher fidelity and shares one definition with local dev.
  This is the unit↔integration boundary made concrete; see the boundary table in §4.

#### Decisions still to make

- **Cypress vs Playwright** — Cypress is the NC community standard; Playwright is supported
  by `@nextcloud/e2e-test-server` too and has better cross-browser coverage. Either works;
  Cypress is the lower-friction choice given existing NC tooling.
- **Scope of e2e** — full browser tests are expensive. A small, high-value set (pull → file
  appears with icon, writeback → n8n receives update, delete → n8n archives) is probably
  the right starting target.

#### Reference links
- [NC unit testing docs](https://docs.nextcloud.com/server/latest/developer_manual/core/unit-testing.html)
- [`christophwurst/nextcloud_testing`](https://github.com/ChristophWurst/nextcloud_testing)
- [`@nextcloud/e2e-test-server`](https://nextcloud-libraries.github.io/nextcloud-cypress/)
- [`nextcloud-libraries/nextcloud-e2e-test-server`](https://github.com/nextcloud-libraries/nextcloud-e2e-test-server)
- [Deck as reference app](https://github.com/nextcloud/deck) — full test suite, CI workflows, Cypress setup
- [integration_openai as reference app](https://github.com/nextcloud/integration_openai) — lean unit-only suite, good for simpler apps

### 4a. Local stack — docker-compose (lightweight Nextcloud + n8n) ☐

The integration layer (§4 table) needs a real Nextcloud and a real n8n that can see each
other. Rather than three slightly-different definitions of "spin up the stack" (one for the
contributor's laptop, one for the devcontainer, one for CI), there should be **one
docker-compose file that is the single source of truth**, and the other two consume it.

**What the stack contains (keep it minimal):**

| Service | Image | Why |
|---|---|---|
| `nextcloud` | `nextcloud:33-apache` (matches `info.xml` max-version) | the app under test; the n8n_sync app dir is bind-mounted into `custom_apps/` |
| `db` | `postgres:16-alpine` (or `mariadb`) | NC needs a real DB for integration; SQLite is the fast CI fallback |
| `n8n` | `docker.n8n.io/n8nio/n8n` | the real writeback/pull target; owner pre-provisioned via env, API key seeded (see §4a.1 — it is *not* a simple env var) |

Keep it ultra-light: no Redis, no Collabora, no Talk — only what the integration tests
actually touch. **n8n itself uses default SQLite** (no Postgres/Redis for n8n — those are
prod-only luxuries). A `compose.yaml` at the repo root with a small `.env.example` (NC admin
creds, n8n owner creds + the seeded API key) is enough. A `make stack-up` / `make stack-down`
wraps it.

**Two consumers, same services — but expressed differently per environment (DECIDED):**

```
        compose.yaml (devcontainer + laptop)        integration.yml (CI)
        ┌────────────────────────────────┐          ┌──────────────────────────┐
        │ docker compose: nextcloud + n8n│          │ GHA `services:` n8n      │
        │ (+ db) — humans `make stack-up`│          │ + nextcloud image / occ  │
        └────────────────────────────────┘          └──────────────────────────┘
                    devcontainer                            no docker-compose
                    & local dev only                        in CI — services only
```

- **`compose.yaml` is for the devcontainer and local dev ONLY.** It is *not* run in CI.
  Humans (and the devcontainer's docker-outside-of-docker) `make stack-up` to get NC + n8n
  reachable for `occ` and API calls — closing the §3 devcontainer "untested" caveat.
- **CI does NOT run `docker compose up`** (explicit decision). The integration job
  (`integration.yml`, shipped) follows what the **official NC apps do** (deck /
  integration_openai): **check out `nextcloud/server`**, mount this app into `apps/n8n_sync`,
  `setup-php`, **`occ maintenance:install` on SQLite**, then drive `occ` directly.
  - **n8n runs as a GHA `services:` container** — it's a pre-built image with no checkout
    dependency, so the services feature fits it cleanly (this is the bit of the feature we
    keep). The owner is pre-provisioned via the §4a.1 env so the service boots signup-free.
  - **Nextcloud is NOT a service container.** Service containers start *before* the job's
    steps — i.e. before `checkout` — so they cannot bind-mount the app under test into
    `custom_apps`. That ordering constraint is exactly why the ecosystem uses the
    checkout-server pattern; we follow it rather than fight the services feature.
- **Why not one file for both?** A compose file can't cleanly express GHA service health-gates
  / port mappings, and `docker compose up` on a runner is heavier and drifts from how GHA
  wants containers declared. The two stay **semantically identical** (same images, same n8n
  owner-env, same NC autoinstall env) without sharing one literal YAML. Keep the image tags
  and env in sync by hand (a short shared `.env.example` documents the canonical values).

**Status:** not yet built. It is the prerequisite for the integration suite in §5 and for
closing the §3 devcontainer "untested" caveat. The unit suite (shipped first) does **not**
depend on it.

#### 4a.1 Can we even spin up n8n without signing up? (researched — yes, with one wrinkle)

The open question was whether a fresh n8n forces an interactive owner-signup/login that would
block headless CI. **Answer: the owner screen is skippable, but the API key is not a simple
env var.** Findings (from the n8n docs source, not memory — see the memory note
`nextcloud-n8n-ci-n8n-instance`):

- **Skip the owner-setup wizard via env.** Set `N8N_INSTANCE_OWNER_MANAGED_BY_ENV=true` and
  provide `N8N_INSTANCE_OWNER_EMAIL`, `N8N_INSTANCE_OWNER_FIRST_NAME`,
  `N8N_INSTANCE_OWNER_LAST_NAME`, and **`N8N_INSTANCE_OWNER_PASSWORD_HASH`** (a **bcrypt**
  hash, not plaintext). This pre-provisions the instance owner and bypasses the first-run UI.
- **`N8N_USER_MANAGEMENT_DISABLED` is gone** — recent n8n removed it; there is no "turn login
  off" switch anymore. Don't plan around it.
- **The public API key has no headless mint.** It is created only in the UI (Settings → n8n
  API) or by writing n8n's DB directly. CI therefore needs **one** of:
  - **(A) REST login + create key** — boot n8n with the env-provisioned owner, have a tiny
    setup step log in with those creds over REST and create an API key, then hand it to the
    PHPUnit integration config. Most faithful to a real instance; no DB poking. *(preferred)*
  - **(B) Seed the key into SQLite** — start n8n once, insert a known API-key row into the
    SQLite file the compose mounts. Fastest/most deterministic, but couples to n8n's schema.
- **Self-hosted public API needs no license.** The "API not available in free trial" caveat
  applies to n8n **Cloud** only; a self-hosted container exposes the public API freely.
- **Storage:** n8n runs on its **default SQLite** here — fine for ephemeral CI, no extra
  service.

So the integration job's setup sequence is: `docker compose up` (NC + db + n8n with owner
env) → wait healthy → mint/seed the n8n API key → `occ` enable the app in NC → run the
PHPUnit integration suite against both. The API-key step (A vs B) is the one real decision
left for whoever builds §4a; default to **(A)**.

#### 4a.2 Agent environments (Copilot setup) — model on Drupal, but trimmed

This repo already hands tasks to **GitHub Copilot** (the coding agent) under a human lead.
Copilot's cloud runs in an ephemeral environment that we can pre-provision, exactly like
`apps/drupal/.github/workflows/copilot-setup-steps.yml` does:

- **`copilot-setup-steps.yml`** — a job **named `copilot-setup-steps`** (Copilot ignores it
  otherwise) that installs the toolchain the agent needs before it starts: `setup-php` (8.4,
  matching the pod — see the CI-PHP memory), Node from `.nvmrc`, Composer, and — once §4a
  exists — `docker compose up` of the test stack so the agent can run integration tests too.
  Triggered on `workflow_dispatch` + on changes to the file itself (validation only); it is
  **not** driven by public input, so it is safe on a public repo.

**Deliberately NOT copied from Drupal: the `plan-agent.yml` planning agent.** That workflow
triggers on `issues: labeled` and `issue_comment` containing `@claude` — on a **public** repo
that is an abuse vector: any stranger can open an issue or comment to spin up a runner and
burn the LLM key. Until there's a trusted-association gate (e.g. restrict to members /
collaborators, or require an org-member author), **the planning agent stays out of this
repo.** Human-authored issues + Copilot-on-assignment is the flow; the "thinking half" is
done by the maintainer (or a local agent), not a public-triggerable cloud workflow.

> Decision: ship `copilot-setup-steps.yml` only. Revisit a planning agent **only** behind an
> author-association guard (`github.event.comment.author_association` ∈ {OWNER, MEMBER,
> COLLABORATOR}) so public comments can't trigger it.

### 5. Tests ✅ (unit + integration live and green in CI)

Implement the test suite in the two layers defined by the §4 boundary. The layers ship in
order — **unit first** (no infrastructure), **integration second** (on the §4a stack).

> **Status (2026-06-19):** the **unit layer is live on PR #1** (`chore/testing-scaffold-ci`)
> and **green in CI**, with results surfaced in the GitHub UI. Integration + e2e remain
> scaffolded-only (await §4a). See **§5.1 (what was built)** and **§5.2 (lessons learned)**
> below for the completed-work report and the hard-won gotchas.
>
> **Status (2026-06-22):** the **integration layer is now LIVE** — Behat on a real Nextcloud
> (stable33, SQLite) + a real n8n service container, **17 scenarios / 81 steps green**
> (PR #20→#25). The big unlock was bootstrapping n8n headlessly in CI (mint an API key with
> zero secrets, preload control-case workflows), then driving the app's real listeners over
> **WebDAV** and asserting both sides — n8n over its REST API, the NC stamp over DAV PROPFIND.
> Live features: **create-on-land**, **rename** (three-way name sync via `ReconcileNameJob`),
> **delete** (trash→archive, restore→unarchive, backup/link tag-strip, unmapped no-op).
> Deferred `@todo` (documented, CI-skipped): **purge→permanent-delete** (a manual trashbin
> DAV DELETE doesn't appear to fire the Files `BeforeNodeDeletedEvent` the hard-delete leg
> needs — a real listener-side follow-up) and **n8n-unreachable abort** (better as a unit test
> vs a mocked `N8nClient`). See **§5.3** for the integration-layer build report + gotchas.

#### Unit suite — `tests/unit/`, runs on every PR *(shipping first)*

Standalone PHPUnit: no NC server, no docker stack. Pure-logic classes run as-is; classes
with NC collaborators mock those collaborators against `nextcloud/ocp` interfaces. Priority
order (do the dep-free ones first — they are the literal scaffold):

**Zero NC deps — the scaffold target:**
- `FilenameCodec` — pure string logic (`parse`/`format` round-trip, id detection, collision
  suffix, sanitisation). **This is the initial "hello world but real" test.**
- `ScheduledPullJob` interval parsing — pure logic, verified live but not in tests
- `Mapping` validation — `fromArray` invariants (reference⇒no writeback, sync⇒writeback set,
  legacy `link`→`reference` upgrade, comma-in-tag rejection)
- `SyncGuard` — the loop-prevention counter; wrong behavior here has wide blast radius
- `DeleteService` rule table — soft/hard/restore logic is clearly specced

**Mock NC interfaces:**
- `MappingService` resolve/routing — the core address book (mock `IAppConfig`)
- `N8nClient` — HTTP boundary; mock `IClientService`, test request shaping + error handling
- `PushService` routing — channel dispatch logic
- Controller responses — admin-gated, JSON shapes

#### Integration suite — `tests/integration/`, runs on the §4a stack *(staged roadmap)*

Requires a live NC + n8n (CI uses the checkout-server pattern + n8n as a service; §10).
This is the wiring the unit suite mocks out, and the **automated safety net** that proves the
real behaviour still works after all the Chapter-2 refactoring. It is built up the same way a
human operator would set the integration up — each stage is a prerequisite for the next, so the
suite grows along this road rather than in one leap:

> **Unplanned win — the app is now BDD.** Choosing Behat for the integration layer turned the
> repo into a **behaviour-driven** project: the `features/*.feature` files are plain-English,
> medium-agnostic Gherkin (no JSON, no `occ` — the *function*, not the medium), so they double
> as **living, human-readable documentation** of every use case. The README's feature showcase
> links straight to them, and they drive the tests — docs, spec, and tests are one artifact.
> This wasn't in the original plan; it fell out of picking the Nextcloud-native test framework,
> and it's a keeper.

**Stage 0 — Install ✅ (PR #12).** App enables + uninstalls cleanly on a real NC
(`tests/integration/install-uninstall.sh`). No n8n contact. The harness itself.

**Stage 1 — Admin setup ✅ (PR #20).** Drive the same AppConfig
the admin UI writes, via `occ config:app:set n8n_sync …`, to wire the connection *config*
without making a single call to n8n:
- `n8n_url` → the n8n service URL.
- `api_enabled` → `1` (REST writeback/pull path on).
- `api_key` → the token. **This is the crux** (see Stage 2): the key is stored
  **`sensitive` and `ICrypto`-encrypted**; `N8nClient` calls `ICrypto::decrypt()` on it. A plain
  `occ config:app:set … --sensitive` only *hides* the value, it does **not** `ICrypto`-encrypt
  it, so a plaintext key fails `decrypt()`. Stage 1 just has to get a value *stored*; Stage 2
  owns getting a *usable* one.
- `mappings` → one entry (n8n tag → Team Folder) via the `mappings` JSON key.
- **Exit:** `occ config:app:get` shows the values set; the app is "configured"; **still zero
  authenticated calls to n8n.** This is the deliberate scope line for the next milestone.

**Stage 2 — The token conversation ✅ DEFEATED (the main antagonist; PR #22).** *Where does
the API key come from?* n8n has **no headless API-key mint** (§4a.1), so the token has been the
one thing standing between us and live integration tests — the boss fight. **It is now
defeated:** proven end-to-end against a real n8n that path A works, with pure `curl` and **zero
secrets**. Once the token falls, the rest of the integration suite is off to the races.

*The proven recipe (verified live — login → mint → public API all 200):*
1. **`POST /rest/login`** with `{"emailOrLdapLoginId":"owner@example.com","password":"n8npassword"}`
   → 200, sets an `n8n-auth` session cookie. **Read the `Set-Cookie` header and replay it
   verbatim** — do NOT rely on curl's cookie jar: n8n's cookie attributes make curl's `-c/-b`
   jar drop `n8n-auth`, which silently 401s the next call. (`node`'s manual replay or
   `curl -D - … | grep -i ^set-cookie` both work; the jar does not.)
2. **`POST /rest/api-keys`** with `Cookie: n8n-auth=…` and body
   `{"label":"itest","expiresAt":null,"scopes":["workflow:read","workflow:list"]}`
   → 200, returns `data.rawApiKey` (a ~267-char JWT). Route confirmed from source:
   `@RestController('/api-keys')` → mounted at `/rest/api-keys`, guarded by `apiKey:create` +
   the api-enabled middleware.
3. **Verify:** `GET /api/v1/workflows?limit=1` with `X-N8N-API-KEY: <rawApiKey>` → 200
   `{"data":[],"nextCursor":null}` — i.e. the minted key authenticates the *public* API exactly
   as `N8nClient` uses it.

*Storing it the way the app expects (the second half of the fight):* write the raw key through
**`ICrypto::encrypt()`** (a tiny `occ` helper command on our side) so `N8nClient::decrypt()` can
use it — **not** a raw `occ config:app:set` (that stores plaintext; `decrypt()` then throws).
- **Exit:** the stored `api_key` decrypts and authenticates (Stage 3 is then trivial).

  *Secrets / registration — researched, decided (no GitHub secrets needed):*
  - **The emailed n8n "registration key" is NOT needed.** It's the optional *Registered
    Community Edition* license, which only unlocks Folders / Debug-in-editor / Custom execution
    data — none of which the tests touch. The **public REST API + API keys work on the plain,
    unregistered community edition**; the "API unavailable during free trial" caveat is n8n
    *Cloud* only. Keep that personal license out of CI.
  - **No GitHub Actions secrets required at all.** The throwaway stack's creds are non-secret
    and committed: n8n owner (`owner@example.com` / bcrypt of `n8npassword`) and NC admin
    (`admin`/`admin`). The API key is **minted at runtime** (path A), so there is nothing to
    store. Plaintext throwaway creds in the repo are correct here — they live only inside the
    job's disposable containers and grant access to nothing real.
  - **Tell secret scanning it's not a leak** with `.github/secret_scanning.yml` →
    `paths-ignore:` covering the test-fixture paths (the n8n owner hash, any seeded test key).
    (A bcrypt *hash* generally isn't flagged anyway, but the exclusion makes intent explicit and
    covers a plaintext seeded key if path B is ever used.)

**Stage 3 — First authenticated call ✅ (PR #23).** The "Test connection" path: `N8nClient` lists
workflows (`GET /api/v1/workflows?limit=1`) with `X-N8N-API-KEY`. Proves the encrypted key +
URL + n8n service all line up end to end. The smallest possible real round-trip.

**Stage 3a — Admin mapping ✅ (PR #24).** "Admin makes a mapping" — bind an n8n tag to a NC
folder with a storage kind (Team Folder vs admin-owned) and a mode (sync/backup/link), across a
representative slice of that matrix, plus the reject-invalid rules (sync needs a writeback;
reference must not have one). Two supporting pieces landed here:
- **Control-case preload (prerequisite):** `tests/workflows/*.json` — example flows
  (Manual Trigger → Set), validated as real via the n8n MCP `validate_workflow` (never created
  on a live instance) — are loaded into the CI n8n through **n8n's own API** + tagged
  (`tests/integration/bin/preload-n8n.sh`), so mapping/pull scenarios act on real, pre-existing
  resources independent of our code.
- **occ admin CLI (§5.4)** for the mapping operations.

**Stage 4 — CRUD integration tests ☐.** The full safety net, building on Stages 1–3a:
- Pull: `pullAll()` → files appear in NC with correct metadata + mimetype.
- Writeback: save a `sync·two-way` file → n8n receives the PATCH.
- Delete: trash → n8n archives; purge → hard-delete; restore → unarchive.
- Name sync: rename → JSON name and n8n name converge within one cron tick.

Each stage is independently committable and leaves CI green; the suite is the arbiter that the
refactored data plane still behaves.

#### 5.3 Deferred feature — "move a sync workflow out = unmap" (Chapter-1 leftover)

A genuine behaviour gap surfaced while writing the `features/` specs against the code:

- **Now (code):** `MoveGuardListener` **hard-blocks** moving a managed `*.n8n.json` out of /
  across mappings, for *every* mode (`AbortedEventException`). The class comment is explicit:
  *"the simple rule is 'you can't move it out'"*, chosen to dodge the delete/unlink/convert
  edge cases.
- **Intended end state (sync only):** moving a **sync** file *out* of its mapped folder should
  **strip its n8n metadata** → a plain, unmanaged `.n8n.json` still present in NC but no longer
  tracked in n8n. Moving it back into a mapped folder should be a **create in n8n + re-stamp
  metadata** (a move in NC, a create in n8n). **Link/backup move-out stays blocked** — that
  case isn't designed yet.
- **Why deferred:** this was a Chapter-1 leftover that wasn't *enabled* then; its prerequisites
  (the delete/restore lifecycle, the metadata contract, the move guard itself) now exist, so we
  *could* build it — but **not until the current behaviour is covered by passing integration
  tests.** Sequence: (1) `features/move.feature` documents today's block and goes green;
  (2) then implement the sync strip/re-enrich, flipping those scenarios to the new end state.
- The README "Moving files" section states this end state for users; `features/move.feature`
  stays accurate-to-code (blocked) until step (2).

#### 5.4 occ admin CLI — headless parity for occ/helm/k8s ✅ (backfilled)

The admin operations already existed (Settings panels + the `MappingController`/`ConfigController`
HTTP endpoints). To make the app **automatable the k8s way** (and to give the integration tests a
real CLI to drive — the medium the Behat steps shell out to), we bound the same operations to
`occ`. These are **thin CLI bindings over the existing services — no new business logic**:

| Command | Wraps | Added in |
|---|---|---|
| `n8n_sync:test-connection` | `N8nClient::ping()` (the admin "Test connection" button) | PR #23 |
| `n8n_sync:set-api-key` | `ICrypto::encrypt()` + store (the Settings `sensitive` field's headless equivalent — plain `config:app:set` stores plaintext that won't decrypt) | PR #23 |
| `n8n_sync:add-mapping` / `list-mappings` / `remove-mapping` | `Mapping::fromArray()` + `MappingService::add/list/delete` (the Settings mapping CRUD) | PR #24 |
| `n8n_sync:list-workflows` / `get-workflow` | `N8nClient` read paths (pre-existing smoke commands) | earlier |

Design notes worth keeping: these mirror how this repo's `apps/nextcloud` injects config/secrets
via an app's own occ command (e.g. `user_oidc:provider --clientsecret`); each maps a validation
failure to a **non-zero exit** so automation (and the tests' reject-invalid scenarios) can rely on
it; and the integration **features stay medium-agnostic** — they describe the admin action, with
occ hidden in the step definitions, so a scenario reads equally as CLI or admin-UI.

> Still HTTP/UI-only (no occ binding yet, add if useful): the manual **sync** actions
> (`SyncController` — pull all / per-mapping), the webhook test, and mapping `update`.

#### Browser e2e (Cypress) — small, high-value set *(on the §4a stack, latest)*
- The "Open in n8n" click opens the correct n8n URL
- The n8n icon appears on `.n8n.json` rows in the Files view
- The "Edit as text" modal loads and saves cleanly

The §4a docker-compose stack is the single local + CI vehicle for the integration and e2e
layers (consumed by the devcontainer and the GitHub Actions integration job alike). The unit
suite needs none of it and gates every PR from day one.

#### 5.1 What was built (completed-work report + checkoff)

Delivered on **PR #1** (`chore/testing-scaffold-ci` → `main`), verified green in CI and,
where it needed a real PHP, validated against the cluster's Nextcloud pod (PHP 8.4).

**Requirements captured (the brief that drove this):**
- ☑ Scaffold the unit testing suite with a **simple-but-real** first test (a "hello world
  that actually tests something"), chosen from the app — `FilenameCodec` (pure logic).
- ☑ Build the test workflow in **GitHub Actions**, triggered **on PR into `main`** (and push
  to `main` so the badge tracks trunk).
- ☑ Use **current** action versions — explicitly *not* the stale majors an LLM reaches for
  (verified each via `gh api repos/<a>/releases/latest`; see §5.2).
- ☑ The tests **actually run because of the PR and succeed** — confirmed via authenticated
  `gh` watching the run to terminal.
- ☑ Split work into a **Tests** flow (build/lint/test) and a **Quality** flow
  (audit/static/CodeQL) — two workflows (§10).
- ☑ **Per-language jobs** within each workflow (PHP job / JS job), no language matrix.
- ☑ **CI PHP pinned to the pod's runtime (8.4)** — tests/cs/psalm run against prod PHP.
- ☑ **Surface results in the GitHub UI**: artifacts uploaded; sticky PR comment + inline
  annotations + job summaries; works at PR *and* push-to-`main` level.

**Concrete artifacts added:**
- `composer.json` — PSR-4 (`OCA\N8nSync\ → lib/`), dev deps (PHPUnit 12, `nextcloud/ocp` ^33,
  `nextcloud/coding-standard` ^1.4, Psalm 6) + scripts (`test:unit`, `cs:check`/`cs:fix`,
  `lint`, `psalm`).
- `tests/bootstrap.php` (standalone), `tests/phpunit.unit.xml` (with `<junit>` logging →
  `tests/results/junit.xml`).
- `tests/unit/Service/FilenameCodecTest.php` — 15 tests / 31 assertions: `parse`/`format`
  round-trip, id detection, collision suffix, sanitisation, non-`.n8n.json` rejection.
- `.php-cs-fixer.dist.php` (Nextcloud coding standard), `psalm.xml` (lib-only, level 6,
  `errorBaseline`), `tests/psalm-baseline.xml` (committed ledger of pre-existing findings).
- `.github/workflows/tests.yml` and `.github/workflows/quality.yml` (see §10).

**Reporting surfaces (verified rendering):**
- **JUnit** via PHPUnit → **`EnricoMi/publish-unit-test-result-action@v2`**: one **sticky PR
  comment** (updates in place), a **check run with inline annotations** on failures, and a
  **job-summary table**. De-facto NC-ecosystem-compatible JUnit reporter; does comment **and**
  annotations in one action.
- **Artifacts** via `actions/upload-artifact@v7`: `php-unit-junit`, `js-bundle`, `psalm-sarif`.
- **Audits** (`composer audit`, `npm audit`) write `$GITHUB_STEP_SUMMARY` blocks.
- **Code scanning** (Security tab): CodeQL for JS + **Psalm SARIF for PHP** (§13).

**Still ☐ (unchanged by this work):** integration suite, browser e2e — both await §4a.

#### 5.2 Lessons learned (don't relearn these)

- **CI PHP must equal the pod's PHP.** php-cs-fixer applies version-specific rules, so a job
  on 8.3 disagreed with the pod's 8.4 (a template the pod run "passed" failed in CI). Pin
  `setup-php` to whatever `kubectl exec … php --version` reports. (Memory: pod is 8.4.21.)
- **PSR-4 paths are case-sensitive and must mirror the namespace segment-for-segment.**
  `Tests\Unit\Service → tests/unit/Service` only resolves when the autoload-dev key is
  `OCA\N8nSync\Tests\Unit\ → tests/unit/`. A mismatch is a *silent* composer skip warning,
  not an error.
- **Know the code before asserting on it.** The first `FilenameCodec` fallback test was wrong:
  `sanitiseName('///')` → `___` (slashes are *substituted*), so the id-fallback only triggers
  on input that sanitises to truly empty (control chars, which are *stripped*).
- **CodeQL has no PHP extractor** — PHP is the only top-10 GitHub language without one. Don't
  list `php` as a CodeQL language (it errors `Did not recognize…`). Psalm is the PHP scanner.
- **Always verify action majors.** Shipped stale `upload-artifact@v4` / `checkout@v6` /
  `setup-node@v5`; the runner's "Node 20 is being deprecated" warning is the tell. Current:
  `upload-artifact@v7`, `checkout@v7`, `setup-node@v6`.
- **Don't run heavy tools (Psalm) repeatedly in the shared prod pod.** Stacked, un-reapable
  `psalm` processes thrashed the Nextcloud pod; deleting the pod (Deployment respawns it
  fresh) is the clean reset. Better: let CI be the authoritative Psalm runner; use the pod
  only for one-shot validation.
- **Psalm baseline is the deferred-cleanup ledger.** 185 pre-existing findings (legacy
  strictness + `nextcloud/ocp` snapshot gaps like `IDelegatedSettings`); baseline them so the
  gate fails only on *new* issues, then shrink the baseline over time (§12).

#### 5.3 Integration layer — what was built + lessons (2026-06-22)

The integration suite went from "scaffolded, occ-only" to a real behavioural net across
PRs #20→#25. What landed:

- **Three transport channels in one `FeatureContext`** — `occ` (admin setup), **WebDAV**
  (Guzzle, admin basic-auth: MKCOL/PUT/MOVE/DELETE/PROPFIND), **n8n REST** (Guzzle,
  `X-N8N-API-KEY`: assertions + teardown). WebDAV is non-negotiable: the create/rename/delete
  listeners only fire on real NC filesystem events, which a WebDAV write produces and an
  `occ config` does not.
- **Admin-owned mappings** (`use_team_folder=false`) so CI needs no groupfolders app.
- **`@AfterScenario` teardown** deletes created workflows + folders and clears the `mappings`
  key, keeping re-runs isolated on the shared CI n8n + NC.

Hard-won lessons (the green came after each of these bit):

- **CI must serve NC over HTTP for the WebDAV channel.** occ-only scenarios needed no web
  server; once WebDAV entered, the suite needed `php -S localhost:8080 -t $GITHUB_WORKSPACE`
  (enough for DAV) + `localhost:8080` trusted + a `status.php` readiness wait.
- **Async listeners need a deterministic job drain.** Rename/JSON-edit defer to
  `ReconcileNameJob` (the file is locked during a rename). `background-job:worker --once`
  honours the worker's last-run/reservation timing and *skips* a job queued microseconds
  earlier → flaky. The fix: `background-job:list --class=… --output=json`, then run each id
  with `background-job:execute <id> --force-execute`.
- **Don't assert with PHPUnit `Assert` on the failure path under Behat.** PHPUnit 12's
  failure exporter reaches into `PHPUnit\TextUI\Configuration\Registry`, which is null with no
  TextUI bootstrap → an opaque `Registry::get(): … null returned` TypeError that *masks* the
  real value. Use a plain `RuntimeException` helper for HTTP-status checks; passing assertions
  are fine, so a green run hides this until something fails.
- **Literal `( )` in a step's Gherkin text becomes a regex capture group** → the step reads as
  *undefined* and the suite fails while looking green. Pin with an escaped regex annotation.
- **The PR-gating workflows' `cancel-in-progress` concurrency froze the PR.** Keyed on
  `github.ref` (= `refs/pull/N/merge`), a rapid second push cancelled the first run during
  GitHub's merge-ref recompute and the latest commit ended up with *no* status on HEAD →
  required checks stuck "waiting" forever. Removed the `concurrency` block from pr/tests/
  quality (kept it on the slow, non-required integration workflow). Separately, GitHub
  occasionally just fails to create runs for a push (event-delivery wedge) — a fresh push or
  PR close/reopen clears it; it is not a quota/billing limit (public repo = unlimited CI).

#### 5.5 First real-instance install (2026-06-22) ✅

Deployed the merged `main` build (0.1.1) into the live homelab Nextcloud (NC 33.0.4, the
`cloud/nextcloud` pod) — the instance had been running the end-of-Chapter-1 0.0.2 copy. Method
is still a manual `kubectl cp` into `custom_apps/` (persistent host volume; no automated app
installer in the Nextcloud deployment yet).

- **Avoiding the "update failed" limbo.** A higher `info.xml` version copied in *while the app
  is enabled* makes NC try to auto-run the app upgrade on the next page load and, with
  auto-update off, can wedge it half-upgraded. The clean sequence that sidesteps it:
  `maintenance:mode --on` → `app:disable` (still at old version) → swap files →
  `maintenance:mode --off` → `app:enable` (clean version transition, repair steps run). Keep
  `app:remove` + re-copy + `app:enable` staged as the recovery.
- **Result:** `installed_version` 0.0.2 → 0.1.1, enabled, instance healthy (no needsupgrade),
  mimetype repair step re-ran (`application/n8n+json` registered), existing mappings preserved,
  zero warn/error log entries. As expected for a refactor, no behavioural change — UI parity
  is the remaining manual confirmation.

#### 5.6 Known coverage gaps (future tasks) ☐

The integration suite green-lit the create/rename/delete *happy paths*, but it leans on a
narrow slice of the configuration matrix. These cracks are real and worth their own scenarios
later (some unblock only after infra work):

- **`link` vs `sync` coverage is thin.** Almost every live scenario exercises `sync`. `link`
  behaviour (a link is *pulled* from n8n, not authored; click-opens n8n; delete untags; it
  never pushes; move-out is blocked) is under-specified in `features/`. Add link-specific
  scenarios across create-from-pull, file-type (click-to-open), and delete. *(Note: the
  `mode` model itself is being reworked in §14 above — write these against the new
  `sync`/`link`/`unmapped` model, not the old mode+writeback.)*
- **Admin-owned vs Team Folder is untested.** All integration mappings use
  `use_team_folder=false` (admin-owned) so CI needs no groupfolders app. The Team Folder path
  (`TeamFolderService`, groupfolders mount, the actor group, group-scoped visibility) has
  **zero** integration coverage. Future task: stand up groupfolders in the CI stack and add
  team-folder scenarios — the storage-backend branch is a real fork we never exercise.
- **Async vs sync push timing is untested.** The admin `push_timing` setting (async via
  `PushWorkflowJob` vs inline) changes *when* a save reaches n8n. The integration tests drain
  jobs manually and implicitly assume one path; neither timing is asserted as a distinct
  behaviour. Future task: scenarios that flip `push_timing` and assert the save lands in n8n
  under both (inline immediately; async only after the worker runs).

These are **documentation of gaps**, not regressions — the shipped behaviour works; it's the
*test matrix* that's partial.

### 6. CONTRIBUTING.md / developer setup doc ✅ (PR #3)

`CONTRIBUTING.md` exists at the repo root and is the canonical contributor entry point.
It covers:

- Prerequisites + devcontainer / manual dev setup
- The build loop (edit → build bundle → deploy to the cluster's NC pod → verify)
- Test policy ("every PR should have tests when reasonable") and how to run them
- The **issue → PR flow** (issues *preferred but not gated*; `Closes #N` keyword links a
  PR to its issue and auto-closes on merge — the official GraphQL `closingIssuesReferences`
  Development link)
- CI gate expectations (Tests + Quality + PR housekeeping must be green)
- Changelog principles — *"the changelog is the release notes; keep entries short and
  sweet, one line per entry; breaking changes are the only exception, marked
  `**BREAKING:**`"*
- Repo tour table mapping every important path
- Release flow (`publish.yml` with `push: true`; AI deep validation + human validation
  on a real NC instance required)

The issue-first flow is taught here and enforced in spirit by the PR housekeeping workflow
(§13.1) which assigns the author and demands a CHANGELOG entry.

### 7. AGENTS.md / AI context ✅ (PR #3)

`AGENTS.md` exists at the repo root and is the cold-start orientation for AI coding
agents. It contains:

- Repo map + locked architectural decisions (no external storage, metadata as link,
  `SyncGuard`, custom mimetype) — lifted from Chapter 1's decisions, condensed
- Hard-won gotchas (never bump `info.xml` in a feature PR, CI PHP must match the pod's
  8.4, CodeQL has no PHP extractor, `kubectl cp` not whole-dir, etc.)
- Short process summary mirroring CONTRIBUTING.md so an agent doesn't have to read two
  files to know the issue→PR flow and the changelog-is-release-notes principle

Chapter 1 stays the source of truth; AGENTS.md is the index.

### 8. Repo standards / git rules ✅ (folded into CONTRIBUTING.md, PR #3)

Document the conventions so contributors (and CI) know what to expect:

- Branch naming and PR flow (see §11)
- Commit message style (conventional commits, or whatever is chosen)
- What goes in CHANGELOG.md and when
- Semver policy: what constitutes a patch vs minor vs major for a Nextcloud app
- Tag format (`v0.1.1`)

These are now documented as a section inside `CONTRIBUTING.md` rather than a separate
file (branch naming, PR-vs-issue flow, conventional-ish commit style, when to update
`CHANGELOG.md`, semver policy for an NC app, `v0.x.y` tag format). Branch protection
rules in repo settings that *enforce* these are still to be turned on (§11, §13).

### 9. License ✅ (formalized)

**AGPL-3.0-or-later** — the de-facto Nextcloud license (server itself + deck +
integration_openai + effectively every official/community app), and required for app-store
eligibility. Now fully formalized:
- **`LICENSE`** at the repo root — the **canonical AGPL-3.0 text copied verbatim from
  gnu.org** (`agpl-3.0.txt`, 661 lines), not generated. Cross-checked against the SPDX copy.
- SPDX `AGPL-3.0-or-later` already declared in `package.json`, `composer.json`, and source
  `SPDX-License-Identifier` headers.
- `info.xml` uses **`<licence>agpl</licence>`** — confirmed: the official apps use this short
  form, NOT the SPDX string (see Chapter 3 §3.2). Also fixed the `bugs` URL and added
  `<repository>` while here.

### 10. CI workflows — two flows: Tests vs Quality ✅ (implemented)

CI is deliberately **two workflows**, both triggered on **PR into `main`** and **push to
`main`**, each split into **per-language jobs** (PHP / JS) — no language matrix. The division
is by *purpose*: the fast feedback loop vs the slower assurance gates.

**`tests.yml` — 🧪 the fast loop (does it work?).**
- **PHP job:** `composer install` → `php -l` → PHPUnit unit suite (→ `tests/results/junit.xml`)
  → upload JUnit artifact → `EnricoMi/publish-unit-test-result-action@v2` (sticky PR comment +
  annotations + summary; `if: always()` so failures still report).
- **JS job:** `npm ci` → `npm run build` → build summary → upload `js-bundle` artifact.

**`quality.yml` — 🛡️ the assurance gates (is it sound/secure?).**
- **PHP job:** `composer audit` (→ summary) → php-cs-fixer (`cs:check`) → Psalm
  (`--output-format=github` annotations **+** `--report=psalm.sarif`) →
  `codeql-action/upload-sarif` (PHP findings to the Security tab) → upload SARIF artifact.
- **JS job:** `npm audit --omit=dev --audit-level=high` (→ summary) → CodeQL
  (`init` `languages: javascript-typescript`, `queries: security-and-quality` → `analyze`).

> **Why CodeQL is JS-only:** CodeQL has **no PHP extractor** — PHP is the only top-10 GitHub
> language without one (listing `php` errors `Did not recognize…`). **Psalm is the PHP code
> scanner** in its place, and its SARIF lands in the same Security dashboard as CodeQL's JS
> results — so both languages are covered (§13).

**Action versions (verified current — re-check before editing, see §5.2):**
`actions/checkout@v7`, `actions/setup-node@v6`, `actions/upload-artifact@v7`,
`shivammathur/setup-php@v2`, `github/codeql-action@v4`,
`EnricoMi/publish-unit-test-result-action@v2`.

This is the **"Tests" vs "Quality" split** the brief asked for; the Quality flow is also the
backbone of the GitHub Security work in **§13**.

### 11. PR flow ⚠️ (documented + workflow-enforced, branch protection still ☐)

The path from "I have a change" to "it's on main" is now documented in `CONTRIBUTING.md`
and partially enforced by the PR housekeeping workflow (§13.1, `.github/workflows/pr.yml`):

- **Documented + spirit-enforced (✅):** issue→PR flow with `Closes #N` linkage, PR
  against `main`, CI (Tests + Quality + PR housekeeping) must pass, 1 maintainer approval,
  squash-merge, manual release via `publish.yml` with `push: true`.
- **Auto-assign + changelog enforcement (✅):** `kentaro-m/auto-assign-action@v2.0.2`
  assigns the PR to its author; `tarides/changelog-check-action@v3` fails the PR if
  there is no fresh entry under `[Unreleased]`.
- **GitHub branch-protection rules (☐):** required reviewers, required status checks,
  no force-push, no direct push to `main` — still need to be turned on in repo settings
  so the flow isn't just convention.

### 12. Refactor pass ☐

Once the test suite is in place and green, do one deliberate pass over the codebase with
fresh eyes. Goals: naming consistency (wording that has drifted between spec and code),
simplification of anything that grew organically during the phased build, and any
efficiency improvements that are now safe to make because tests will catch regressions.

This is explicitly post-testing — the tests are what make it safe to move things around.
Scope and approach are left to the implementor; the test suite is the arbiter of correctness.

**The refactor is ongoing, not a one-shot.** The CI quality gates are its standing edge:
php-cs-fixer (coding standard), Psalm (with `tests/psalm-baseline.xml`), and the composer/npm
audits don't just run once — they continuously surface the next thing to pay down. The Psalm
baseline is the explicit ledger of deferred cleanup: shrink it over time
(`--set-baseline=tests/psalm-baseline.xml` to regenerate) and the codebase tightens with it.
So "refactor pass" reads as "keep the gates green and the baseline shrinking," not a single
event gated on the test suite being finished.

#### 12.1 Code-scanning paydown — DONE (239 → 14)

The Psalm SARIF Security-tab count went **239 → 14** across four focused PRs:
- **#15 (239 → ~73):** root cause — Psalm wasn't loading `nextcloud/ocp`, so ~166 references
  to real OCP classes were false `UndefinedClass`/`MissingDependency`. Fixed with `<extraFiles>`
  → `vendor/nextcloud/ocp` (type inference 89% → 97%). Plus 43 `final`, 47 `#[\Override]`, and
  `issueHandlers` suppressions of not-our-bug refs (`OC`/`OC_Util`, other-app event classes,
  the `IRootFolder`→`oc\hooks\emitter` OCP-stub artifact).
- **#17 (~73 → 65):** batch A — suppress 2 `InvalidTemplateParam` false positives; `mixed`
  param types on the background `run()` methods; type a closure; drop a redundant `array_values`.
- **#18 (65 → 41):** batch B — real type bugs. The big one: `WorkflowMetadata::read()/write()`
  docblocks declared 3 of 6 keys; correcting them to the full shape cascaded out the
  `InvalidArrayOffset`/`InvalidArgument`/`InvalidReturn*` cluster. `JSON_THROW_ON_ERROR` killed
  the falsable `json_encode` returns; fixed a possibly-undefined var in `MappingService::update`.
- **#19 (41 → 14):** batch C — migrate `IConfig::getAppValue/setAppValue` →
  `IAppConfig::getValueString/setValueString` (identical defaults; return type is `string`, so
  the wrapping `(string)` casts became truly redundant and were removed). `IServerContainer` →
  PSR `ContainerInterface`. The one `IAppContainer` deprecation is core's API (no non-deprecated
  `IBootContext` accessor) — documented, rides the baseline.

**The residual 14 are essentially noise** and a fine stopping point: ~7 low-value defensive
`(string)` casts on non-config values, 4 OCP-gap false positives (`DocblockTypeContradiction`/
`ImplementedParamTypeMismatch` on the other-app event listeners — Psalm can't see those
classes), the 1 `IAppContainer` core deprecation, and 1 each `RedundantCondition` /
`InvalidArgument` ($body in N8nClient). An optional "batch D" could chase these to ~5 but it's
diminishing returns (stripping defensive casts). The Psalm gate **baseline is down to 1 entry**.

**Hard-won lessons (keep):**
- The cluster's Nextcloud **pod cannot run Psalm** — it hangs on the analysis phase even fresh/
  idle, while CI does it in ~2.4s. **Run Psalm in CI.** Regenerate the baseline via a CI
  `--ignore-baseline --set-baseline` step that uploads it as an artifact to commit back. (Pod is
  fine for `php -l` + composer.)
- **Psalm 6 schema gotcha:** `<referencedClass>` is only valid under `UndefinedClass` /
  `UndefinedDocblockClass` — `MissingDependency` (and similar) must be suppressed as a whole type.
- The GitHub Advanced Security bot posts these inline on PRs — confirmed they are **our own
  Psalm findings re-surfaced**, not a second scanner. The security-review loop works.

### 13. GitHub Security — get all the green checkmarks ⚠️ (partly done via §10)

A distinct, larger track from the Tests/Quality CI work, though it **reuses** it. The goal is
concrete: **the GitHub "Security" tab reports a healthy, fully-configured project** — every
feature GitHub offers turned on and green. Some of this already exists as a side-effect of the
Quality flow (§10); the rest is GitHub-repo configuration (mostly `.github/` files + repo
settings), not app code.

**The Security tab features and where each stands:**

| Security feature | What it is | Status |
|---|---|---|
| **Code scanning — JS** | GitHub **CodeQL default setup** (Settings → Code security), runs its own synthesised workflow at `dynamic/github-code-scanning/codeql` | ✅ done (default setup; our YAML CodeQL job was removed in PR #5 — it duplicated the scan) |
| **Code scanning — PHP** | **Psalm SARIF** uploaded via `codeql-action/upload-sarif@v4` (CodeQL has no PHP extractor) | ✅ done (§10 PHP job) |
| **JS linting in the gate** | ESLint 10 flat config (`eslint.config.js`) wired into the Quality JS job before `npm audit` | ✅ done (PR #5) |
| **Dependency review** | block PRs that introduce vulnerable deps (`actions/dependency-review-action`) | ☐ todo |
| **Dependabot — alerts** | GitHub flags vulnerable deps in the Security tab | ⚠️ 2 pending alerts on `main` (1 moderate, 1 low); enable in repo settings to surface |
| **Dependabot — security updates** | auto-PRs that bump vulnerable deps | ☐ enable in repo settings (clears the 2 pending alerts above) |
| **Dependabot — version updates** | scheduled `dependabot.yml` for `composer`, `npm`, **and `github-actions`** | ✅ done (PR #7) — weekly, grouped minor+patch, with cooldown; durable fix for §5.2's stale action majors |
| **Secret scanning + push protection** | block committed secrets (crown jewels per "Secrets hygiene") | ☐ enable in repo settings |
| **Security policy** | `SECURITY.md` (how to report a vuln) — GitHub shows a ✓ for it | ✅ done (PR #3) |
| **Dependency graph** | required substrate for Dependabot/dependency review | ✅ on (default for public repos; Dependabot wouldn't function otherwise) |
| **Audit gates (belt-and-suspenders)** | `composer audit` / `npm audit` in CI | ✅ done (§10), complements Dependabot |

**The Dependabot config (`.github/dependabot.yml`)** is the main new artifact — cover all
three package ecosystems the repo actually uses:
- `composer` (PHP dev deps), rooted at `/`
- `npm` (JS deps), rooted at `/`
- `github-actions` (workflow `uses:` pins) — **this is the durable fix** for shipping stale
  action majors (§5.2): Dependabot opens bump PRs automatically, so the human/agent stops
  being the version-tracker.

Keep the cadence sane (e.g. weekly), group minor/patch bumps to avoid PR spam, and let the
Tests + Quality workflows gate every Dependabot PR (they already trigger on PRs into `main`).

**Division of labour to keep clear:**
- **"Tests" (§10 `tests.yml`)** = *does it work* — build + lint + unit tests + result reporting.
- **"Quality"/"Security" (§10 `quality.yml` + this §13)** = *is it sound & secure* — audits,
  static analysis, code scanning (CodeQL + Psalm SARIF), and the Dependabot/secret-scanning/
  policy configuration that turns the Security tab fully green.

**Exit criterion for §13:** the repo's Security tab shows code scanning active for both
languages, Dependabot (alerts + security + version updates) enabled with a committed
`dependabot.yml`, secret scanning + push protection on, a `SECURITY.md` present, and no
outstanding "set this up" prompts.

**Reference links**
- [GitHub: securing your repository](https://docs.github.com/en/code-security/getting-started/securing-your-repository)
- [Dependabot version updates (`dependabot.yml`)](https://docs.github.com/en/code-security/dependabot/dependabot-version-updates/configuration-options-for-the-dependabot.yml-file)
- [Optimizing PR creation for version updates](https://docs.github.com/en/code-security/dependabot/dependabot-version-updates/optimizing-pr-creation-version-updates)
- [`actions/dependency-review-action`](https://github.com/actions/dependency-review-action)
- [Uploading SARIF (Psalm → code scanning)](https://docs.github.com/en/code-security/code-scanning/integrating-with-code-scanning/uploading-a-sarif-file-to-github)

#### 13.1 What was built (2026-06-19, contribution-infrastructure pass)

Three merged/open PRs, all following the issue→PR flow they document (each opened a
tracking issue first; each PR body uses `Closes #N` so the GraphQL Development link
resolves and auto-closes on merge).

**PR #3 — docs + PR housekeeping (`docs/contributing-agents-security`, MERGED)**
- `CONTRIBUTING.md` (§6), `AGENTS.md` (§7), `SECURITY.md` (private security advisories,
  supported versions, scope, secrets policy, disclosure timeline).
- `.github/workflows/pr.yml` — PR-only housekeeping (`kentaro-m/auto-assign-action@v2.0.2`
  + `tarides/changelog-check-action@v3`).
- `.github/assign.yml` — `addAssignees: author`.

**PR #5 — ESLint + drop duplicate CodeQL (`ci/eslint-js-quality`, OPEN at time of writing)**
- `eslint.config.js` (flat config, **required by ESLint 9+**) with `@eslint/js` recommended
  rules. Declares NC page-scoped globals (`t`, `n`, `OC`, `OCA`, `OCP`) as `readonly` so
  legacy admin scripts don't trip `no-undef`. `no-unused-vars` with
  `argsIgnorePattern: '^_'`, `no-console` allows `warn`/`error`/`info`.
- `npm run lint` / `npm run lint:fix` scripts wired into `package.json`; ESLint runs in
  the Quality JS job *before* `npm audit`.
- Two real lint findings **fixed**, not silenced: unused `catch` bindings in
  `js/mapping-settings.js` and `src/files.js`.
- Dropped the CodeQL init/analyze steps from `quality.yml` — GitHub's CodeQL **default
  setup** owns JS scanning and was duplicating our run (both wrote to the same Security
  category). Header comment in `quality.yml` updated to spell out the split.

**PR #7 — Dependabot config (`ci/dependabot`, OPEN at time of writing)**
- `.github/dependabot.yml` covering the three active ecosystems:
  - `github-actions` — keeps every workflow `uses:` pin current (the durable fix for §5.2).
  - `npm` — Vite, ESLint, `@eslint/js`, `globals`, `@nextcloud/*`.
  - `composer` — Psalm, PHPUnit, `nextcloud/ocp`, `nextcloud/coding-standard`.
- **Weekly** schedule (Mondays); **grouped** minor + patch per ecosystem so dev deps
  don't fan out into one PR per package; **majors stay separate** for deliberate review.
- **Cooldown** of 3 days patch / 7 days minor / 14 days major (where applicable) so
  yanked releases don't reach us before they're pulled.
- **Distinct commit-message prefixes** per ecosystem (`ci(deps)`, `deps(js)`,
  `deps(php)`) so the merge queue reads cleanly.
- Ecosystem labels (`dependencies`, `javascript`, `php`, `github-actions`) pre-created
  via `gh label create` so Dependabot PRs land properly tagged (custom labels are
  silently dropped by Dependabot if they don't exist yet).

#### 13.2 Lessons learned (don't relearn these)

- **CodeQL default setup vs YAML mode duplicate each other.** GitHub's default setup
  (Settings → Code security) synthesises its own workflow run that appears in the Actions
  tab as `dynamic/github-code-scanning/codeql`. If you also have a CodeQL job in your own
  YAML, **both** scans run and both upload to the same Security category. Pick one. We
  removed CodeQL from `quality.yml` and kept the default setup checkbox enabled.
- **ESLint 9+ requires flat config (`eslint.config.js`).** The `eslintConfig` key in
  `package.json` only worked in EOL ESLint 8. There is no "both can coexist" — a fresh
  install of ESLint 10 will refuse to run on the old layout. Just create the flat config
  and move on.
- **Don't silence real lint findings; fix them.** Two unused `catch` bindings flagged by
  ESLint were genuine cruft, not noise. The lint config only silences truly page-scoped
  globals (`t`, `OC`, `OCA`, `OCP`) which *are* the NC contract.
- **`Closes #N` in a PR body creates the official Development link.** Confirmed via
  `gh pr view N --json closingIssuesReferences` — the link resolves in GraphQL and the
  issue auto-closes on merge. Cross-repo works with `Closes owner/repo#N`. Valid keywords:
  closes/closed/fixes/fixed/fix/resolves/resolved/resolve.
- **Dependabot drops custom labels silently if they don't exist.** Only `dependencies`
  is auto-created. Pre-create any ecosystem labels (`javascript`, `php`, `github-actions`)
  with `gh label create` *before* the first Dependabot run, or PRs come out unlabelled.
- **Verify external docs from the source, not from memory.** Jina API was 402-gated, so
  we fell back to the `r.jina.ai` proxy via `curl` to pull the live GitHub Dependabot
  options reference + optimization guide. The config above (cooldown, grouped
  minor+patch, `applies-to` defaults, per-prefix commit message keys) is built directly
  off those pages — not LLM memory, which would have invented a syntax half of the time.

---

### 14. The mode-model & motion refactor ☐ (the payoff)

This is what all the testing + devops was *for*: a safety net thick enough to refactor the
core data model and finally build the deferred file-motion lifecycle without fear. Two linked
bodies of work — **(1)** collapse the muddled `mode`+`writeback` encoding into one clean,
descriptive `mode`; **(2)** build the **motion** lifecycle (move-out / move-back / copy /
merge) that Chapter 1 left as a "planned end state." The specs (`features/*.feature` + the
README) were written first, as the end-state requirements; the code follows under this item.

> **Status (2026-06-22):** specs authored (PR #27) — every feature file + the README describe
> the target as if shipped, new behaviour `@todo` so the live suite stays green. Code is
> Phase 1 (model collapse + migration) then Phase 2 (motion).

#### 14.1 The model — one `mode`, three values

| `mode` | What the file is | Pushes to n8n? | n8n tag |
|---|---|---|---|
| **`sync`** | Full workflow JSON, NC-authoritative | Yes (two-way) | `n8n:sync` |
| **`link`** | Tiny pointer (id, name, URL) | No — click opens n8n | `n8n:link` |
| **`unmapped`** | A workflow file living outside any mapping | No | *(none)* |

Decisions (locked):

- **Drop `writeback`** — the concept + the `nc:metadata-n8n_writeback` DAV property. Fully
  inferable from `mode` (`two-way` *was* just `sync`).
- **Drop `backup` mode.** Redundant with `sync` (sync already keeps the full JSON, so it *is* a
  backup); its read-only promise was **never enforced** (verified — no edit-disabling anywhere,
  backup just didn't push); `link` is the implicit read-only option; an `unmapped` file is a
  fine archive. Less surface, less redundancy. Migration: any `backup` → `sync`.
- **`link` everywhere** (code, UI, docs, tag). The single exception is the DAV property *value*:
  a stored value equal to the global `link()` function makes `is_callable()` true and crashes
  core PROPFIND, so `n8n_mode` for a link stores **`reference`** — isolated to one translation
  point in `WorkflowMetadata` with a note that `reference` ≡ `link`. `sync`/`unmapped` store as-is.
- **Index `n8n_mode`** — one descriptive field makes "every sync" / "every unmapped" a real query.
- **`unmapped` is an explicit, stored `mode`**, not derived — only `sync` files can ever become
  unmapped (so no info lost), and a single indexed `mode=unmapped` beats a compound query. Only
  files *ejected from a mapping* get stamped; a never-mapped `.n8n.json` stays untouched
  (that's "untracked", not "unmapped").

Metadata shape: `n8n_id`, `n8n_versionId`, `n8n_syncedHash` on all; `n8n_mapping` cleared when
unmapped; `n8n_mode` (indexed) = `sync` | `reference`(=link) | `unmapped`; `n8n_writeback` removed.

#### 14.2 Motion — move, copy, restore, merge

**Move OUT** (sync only; link move-out stays blocked): archive the workflow in n8n
(`archiveWorkflow`), keep `n8n_id`+`n8n_versionId`, clear `n8n_mapping`, set `mode=unmapped`. NC
keeps the full JSON, so nothing is lost.

**Move BACK IN** (re-attach / restore / merge):
- id+versionId present → **unarchive/restore** in n8n (`unarchiveWorkflow`), re-stamp mapping +
  `mode=sync` (not a fresh create).
- **Merge on collision** — if the mapping *already* holds a file with that `n8n_id` (e.g. an
  admin restored it in n8n and it synced back while the unmapped copy still existed), the synced
  file is source of truth: **delete the incoming unmapped copy**, keep the existing one. Feels
  like a merge; no n8n call.
- no id → create-on-land makes a new workflow.

**Copy** (`features/copy.feature`): **always a brand-new instance — strip metadata on every
copy, everywhere.** Copy within a mapped folder → a *new* workflow in n8n; copy outside → plain
untracked file; copy of an unmapped file → stripped wherever it lands. This is what makes *move*
"the same workflow" and *copy* "a new thing."

Move scenario matrix (now in `features/move.feature`): within-mapping (no n8n change) · sync
out→unmapped+archive · unmapped in→restore · plain in→create · link out→blocked · unmapped
relocation→no-op · hard-deleted→create · merge-on-collision.

The duplicate state (one unmapped + one mapped, same id) is **fine and intentional** — it
resolves only at move-in (merge), never by a sync. The manual **Sync from/to n8n** buttons are
**mapping-scoped** and ignore unmapped files entirely (`features/reconcile.feature`).

Decision cases still open (need a call before they get live scenarios — `move.feature` comments):
**a** sync moved mapping→mapping (re-tag vs eject+reattach vs block); **b** nested mappings;
**c** link rename within its mapping; **d** deleting an unmapped file (trash no-op? purge
hard-delete the archived workflow?).

#### 14.3 Attack (two PRs)

- **Phase 1 — model collapse + migration.** `Mapping`/`MappingService` (single `mode`; legacy
  `{mode,writeback}`/`reference`/`backup` back-compat), `WorkflowMetadata` (drop `KEY_WRITEBACK`,
  index `KEY_MODE`, link↔reference translation), `OwnershipTags` (drop `n8n:backup`), every
  `(mode,writeback)` check → `mode`, admin UI + occ + controllers, a migration `RepairStep`
  (rewrite mappings config + re-stamp files), **+ run the migration on the live `cloud/nextcloud`
  pod**, and update `FeatureContext::modeToModel` so the model-only `@todo` specs flip live. Bump
  version. Clean, shippable.
- **Phase 2 — motion.** Move-out/in (archive/restore), merge-on-collision, copy-strips, the
  manual per-mapping sync + within-mapping prune. Flip each `@todo` scenario live as code lands
  (the create/rename/delete rhythm from §5.3).

Verify: re-grep for `writeback`/`backup` after Phase 1; live-smoke that n8n `unarchive` truly
restores a workflow our move-out archived (the restore path is load-bearing).

## Things not on the original list worth noting

A few items that naturally belong in this chapter:

- **LICENSE file** — ✅ done: canonical AGPL-3.0 text from gnu.org at the repo root (§9).
- **Packaging-quality audit (2026-06-22, post v0.1.2)** — verified the published artifact
  against the live store conventions. Findings: version propagation is correct across all
  surfaces (`info.xml`, `package.json`, `package-lock.json`, the dated `CHANGELOG` section);
  the tarball is lean (140K, top-level folder = app id) and a file-for-file diff confirmed it
  carries **all 67 runtime files and nothing else** (`config/` mimetype JSONs included, `src/`
  + `.map` + tests/CI/dev tooling correctly excluded, bundle minified). The publish step uses
  an **allowlist** (`appinfo lib css js img templates config CHANGELOG.md README.md` + the
  built bundle) rather than a `.nextcloudignore` denylist — safer (can't leak new dev files).
  - **Task — ship `LICENSE` in the tarball (DONE, this PR):** the one gap vs the flagship apps
    (deck/notes/integration_openai all ship their license text). Added `LICENSE` to the
    publish allowlist. `CHANGELOG.md` is already shipped (store **strongly recommends** it; it
    also feeds the GitHub release notes). No separate `COPYING` — that's just the old GNU name
    for the same full-license-text file; our `LICENSE` already is the canonical AGPL-3.0 text.
- **`info.xml` cleanup** — ✅ `bugs` URL fixed + `<repository>` added; `<licence>agpl</licence>`
  confirmed correct (the official apps use the short form, not SPDX). Still pending for the
  store: real `description` copy + at least one `screenshot` (Chapter 3 §3.1).
- **`.gitignore` audit** — `dist/` and `node_modules/` are already gitignored; verify nothing
  sensitive (keys, `.env`) could accidentally slip in as the repo matures.
- **Secrets hygiene** — the GitHub App private key and (eventually) the NC app signing key
  are the sensitive crown jewels. Document where they live and that they never go in the repo.

---

## What "done" looks like for this chapter

Chapter 2 is complete when:

1. A contributor can open the devcontainer and have a working build environment
2. There is a documented path from clone to a running test Nextcloud with the app installed — ⚠️ **documented in CONTRIBUTING.md (§6);** CI integration stack proves it end-to-end (§5.3); the merged build is also installed on the live instance (§5.4); devcontainer run still untested
3. A test suite exists and runs in CI on every PR — ✅ **done** — unit (§5.1) **and** integration (§5.3, 17 scenarios green on real NC + n8n); e2e/UI still manual
4. Code scanning runs on every PR and push to main — ✅ **done** (CodeQL default setup for JS + Psalm SARIF for PHP, §10 / §13.1)
5. CONTRIBUTING.md and AGENTS.md exist and are accurate — ✅ **done** (§6, §7, PR #3)
6. Branch protection + PR rules are enforced on GitHub — ⚠️ **PR rules documented + workflow-enforced** (§11, §13.1 PR housekeeping); branch protection toggles still ☐
7. The publish workflow has been run at least once with `push: true` and produced a
   real GitHub Release with a valid tarball
8. The **GitHub Security tab is fully green** — ⚠️ code scanning JS + PHP ✅, `SECURITY.md`
   ✅, Dependabot version updates ✅; Dependabot alerts/security updates, secret scanning
   + push protection, dependency review still ☐ (§13 table)

There is no fixed "done" line here — this chapter closes when Kelly judges the app fully
functional and usable for the market (the §14 refactor + edge cases covered to his
satisfaction). The moment he does, the work turns from *function* to *identity*: **branding**
(Chapter 3 §3.1) is the transition, and it's fine for it to start on the tail end of this
chapter. After branding, Chapter 3's store submission is just execution.
