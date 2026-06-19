# Chapter 2 — Pretty Package

> The app works (Chapter 1). Now make it something a contributor can pick up cold,
> a CI pipeline can ship, and eventually the Nextcloud app store can accept (Chapter 3).
> This chapter is the bridge.

---

## Where we are

Phase 0–5 are live in production on the homelab cluster. The code lives in a public
GitHub repo. A working release pipeline exists but is rough around the edges. The
development story is undocumented. That's what this chapter fixes.

> **Progress (2026-06-19):** the **unit test suite + CI is live and green** (Tests + Quality
> workflows, results surfaced in the GitHub UI — §5.1, §10). Remaining: integration/e2e tests
> (§4a stack), the GitHub **Security** track (Dependabot et al. — §13), and the docs
> (CONTRIBUTING/AGENTS).

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
| `n8n` | `docker.n8n.io/n8nio/n8n` | the real writeback/pull target; started with `N8N_API_KEY` so the client can auth |

Keep it ultra-light: no Redis, no Collabora, no Talk — only what the integration tests
actually touch. A `compose.yaml` at the repo root with a small `.env.example` (NC admin
creds, n8n API key) is enough. A `make stack-up` / `make stack-down` wraps it.

**The three-way reuse (this is the whole point):**

```
                       ┌────────────────────────┐
                       │   compose.yaml (root)  │   ← single source of truth
                       │  nextcloud + db + n8n  │
                       └───────────┬────────────┘
            ┌──────────────────────┼──────────────────────┐
            ▼                      ▼                      ▼
   devcontainer.json        contributor laptop       GitHub Actions
   (docker-outside-      (`make stack-up`, edit/     (`services:` block or
    of-docker brings        build/verify loop)        `docker compose up` in
    the same stack up)                                 the integration job)
```

- **Devcontainer:** its existing docker-outside-of-docker is what brings this stack up; the
  devcontainer doesn't redefine services, it just runs `compose.yaml`. This finally gives the
  devcontainer the "NC + n8n reachable for `occ` and API calls" capability §3 flagged as
  untested.
- **GitHub Actions:** the integration job mirrors the same services. There are two idiomatic
  options and the compose file makes either cheap:
  - **`services:` block** — declare `postgres` (and optionally `n8n`) as job-level service
    containers; fastest for the DB, but service containers can't easily bind-mount the app
    dir, so NC itself is usually installed in-runner (clone server / `setup-php` + SQLite).
  - **`docker compose up` step** — run the *exact same* `compose.yaml` in the job, wait for
    health, run PHPUnit integration against it. Heaviest but highest fidelity and zero drift
    from local. **Recommended** precisely because it's the same file devs run.

**Either-direction note (per the decision in this chapter):** it does not matter whether the
compose file or the devcontainer/CI is authored first — they must end up identical, so write
`compose.yaml` once and point the other two at it. Modeling CI `services` *after* the compose
stack (not in parallel) is what prevents the classic "works in the devcontainer, fails in CI"
drift.

**Status:** not yet built. It is the prerequisite for the integration suite in §5 and for
closing the §3 devcontainer "untested" caveat. The unit suite (shipped first) does **not**
depend on it.

### 5. Tests ⚠️ (unit layer shipped + reported in CI; integration/e2e still ☐)

Implement the test suite in the two layers defined by the §4 boundary. The layers ship in
order — **unit first** (no infrastructure), **integration second** (on the §4a stack).

> **Status (2026-06-19):** the **unit layer is live on PR #1** (`chore/testing-scaffold-ci`)
> and **green in CI**, with results surfaced in the GitHub UI. Integration + e2e remain
> scaffolded-only (await §4a). See **§5.1 (what was built)** and **§5.2 (lessons learned)**
> below for the completed-work report and the hard-won gotchas.

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

#### Integration suite — `tests/integration/`, runs on the §4a stack *(scaffolded later)*

Requires a live NC + n8n (the docker-compose stack in §4a; CI brings up the same stack).
These are the tests whose entire value is the real wiring the unit suite mocks out:
- Pull sync: trigger `pullAll()` → files appear in NC with correct metadata and mimetype
- Writeback: save a file → n8n receives the PUT
- Delete: trash a file → n8n archives; purge → n8n hard-deletes; restore → unarchives
- Name sync: rename → JSON name and n8n name converge within one cron tick

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

### 6. CONTRIBUTING.md / developer setup doc ☐

A `CONTRIBUTING.md` that answers the question "I want to work on this — what do I do?"
Should cover:

- Prerequisites (Docker, VS Code + devcontainer extension, or equivalent)
- How to start the dev environment (devcontainer or manual steps)
- How to get a Nextcloud instance running and the app installed into it
- The build loop: edit → build bundle → deploy to test NC → verify
- How to run tests
- High-level architectural orientation (point to Chapter 1 for depth)
- PR expectations (see §11)

Keep it short. Link to deeper docs rather than duplicating them here.

### 7. AGENTS.md / AI context ☐

A file (or files) giving AI coding assistants enough context to be useful without
re-deriving the whole architecture every session. Should cover:

- What this repo is and what it is not
- Key architectural decisions that must not be relitigated (the locked forks from Chapter 1)
- The deploy loop and gotchas (never bump `info.xml` version, `kubectl cp` not whole-dir copy, etc.)
- Where the authoritative state lives (§15 of Chapter 1)
- Hard-won lessons (compound extension mime drift, lock-in-handler, cron-paced reconciliation)

Chapter 1 is the source of truth; AGENTS.md is the condensed orientation for a cold-start agent.

### 8. Repo standards / git rules ☐

Document the conventions so contributors (and CI) know what to expect:

- Branch naming and PR flow (see §11)
- Commit message style (conventional commits, or whatever is chosen)
- What goes in CHANGELOG.md and when
- Semver policy: what constitutes a patch vs minor vs major for a Nextcloud app
- Tag format (`v0.1.1`)

These can live in `CONTRIBUTING.md`, a `docs/` folder, or as a short section in the README.
Keep it minimal — only the rules that will actually be enforced.

### 9. License ✅ (chosen, not yet formalized)

AGPL-3.0-or-later — required for Nextcloud app store eligibility and consistent with
`package.json` (`"license": "AGPL-3.0-or-later"`). A `LICENSE` file should be added to
the repo root if not already present. SPDX headers in source files are a bonus.

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

### 11. PR flow ☐

Define and document the path from "I have a change" to "it's on main":

- Required: PR against `main`, at least one approval
- CI must pass (CodeQL, unit tests, build) before merge
- Conventional commits or equivalent for changelog generation
- The version bump / release is a separate manual step (`publish.yml` with `push: true`),
  not automatic on every merge — keeps the release cadence intentional

Branch protection rules on GitHub should enforce the above so it isn't just convention.

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

### 13. GitHub Security — get all the green checkmarks ⚠️ (partly done via §10)

A distinct, larger track from the Tests/Quality CI work, though it **reuses** it. The goal is
concrete: **the GitHub "Security" tab reports a healthy, fully-configured project** — every
feature GitHub offers turned on and green. Some of this already exists as a side-effect of the
Quality flow (§10); the rest is GitHub-repo configuration (mostly `.github/` files + repo
settings), not app code.

**The Security tab features and where each stands:**

| Security feature | What it is | Status |
|---|---|---|
| **Code scanning — JS** | CodeQL `javascript-typescript`, `security-and-quality` | ✅ done (§10 JS job) |
| **Code scanning — PHP** | **Psalm SARIF** uploaded via `codeql-action/upload-sarif` (CodeQL can't do PHP) | ✅ done (§10 PHP job) |
| **Dependency review** | block PRs that introduce vulnerable deps (`actions/dependency-review-action`) | ☐ todo |
| **Dependabot — alerts** | GitHub flags vulnerable deps in the Security tab | ☐ enable in repo settings |
| **Dependabot — security updates** | auto-PRs that bump vulnerable deps | ☐ enable in repo settings |
| **Dependabot — version updates** | scheduled `dependabot.yml` for `composer`, `npm`, **and `github-actions`** | ☐ todo (the actions ecosystem directly fixes §5.2's stale-version problem) |
| **Secret scanning + push protection** | block committed secrets (crown jewels per "Secrets hygiene") | ☐ enable in repo settings |
| **Security policy** | `SECURITY.md` (how to report a vuln) — GitHub shows a ✓ for it | ☐ todo |
| **Dependency graph** | required substrate for Dependabot/dependency review | ☐ verify on (default for public repos) |
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
- [`actions/dependency-review-action`](https://github.com/actions/dependency-review-action)
- [Uploading SARIF (Psalm → code scanning)](https://docs.github.com/en/code-security/code-scanning/integrating-with-code-scanning/uploading-a-sarif-file-to-github)

---

## Things not on the original list worth noting

A few items that naturally belong in this chapter:

- **LICENSE file** — AGPL-3.0-or-later is chosen; the actual file needs to exist in the repo root.
- **`info.xml` cleanup** — the `licence` field, `bugs` URL, and missing `repository` field
  need fixing before Chapter 3 is possible. Small change, high consequence for the store submission.
- **`.gitignore` audit** — `dist/` and `node_modules/` are already gitignored; verify nothing
  sensitive (keys, `.env`) could accidentally slip in as the repo matures.
- **Secrets hygiene** — the GitHub App private key and (eventually) the NC app signing key
  are the sensitive crown jewels. Document where they live and that they never go in the repo.

---

## What "done" looks like for this chapter

Chapter 2 is complete when:

1. A contributor can open the devcontainer and have a working build environment
2. There is a documented path from clone to a running test Nextcloud with the app installed
3. A test suite exists and runs in CI on every PR — ✅ **unit layer done** (§5.1); integration/e2e pending
4. Code scanning runs on every PR and push to main — ✅ **done** (CodeQL for JS + Psalm SARIF for PHP, §10)
5. CONTRIBUTING.md and AGENTS.md exist and are accurate
6. Branch protection + PR rules are enforced on GitHub
7. The publish workflow has been run at least once with `push: true` and produced a
   real GitHub Release with a valid tarball
8. The **GitHub Security tab is fully green** — Dependabot (alerts + security + version
   updates), secret scanning + push protection, `SECURITY.md`, dependency review (§13)

At that point the repo is in a state where Chapter 3 (store submission) is just execution.
