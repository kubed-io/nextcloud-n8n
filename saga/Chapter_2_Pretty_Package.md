# Chapter 2 — Pretty Package

> The app works (Chapter 1). Now make it something a contributor can pick up cold,
> a CI pipeline can ship, and eventually the Nextcloud app store can accept (Chapter 3).
> This chapter is the bridge.

---

## Where we are

Phase 0–5 are live in production on the homelab cluster. The code lives in a public
GitHub repo. A working release pipeline exists but is rough around the edges. The
development story is undocumented. Tests don't exist yet. That's what this chapter fixes.

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
| Security | CodeQL (§10) | every PR + push to main |

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

### 5. Tests ☐

Implement the test suite in the two layers defined by the §4 boundary. The layers ship in
order — **unit first** (no infrastructure), **integration second** (on the §4a stack).

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

### 10. Code quality — CodeQL ✅ (JS only — CodeQL has no PHP support)

GitHub's CodeQL (via `github/codeql-action@v4`) runs on push to main and on PRs. **Correction
to the original assumption:** CodeQL does **not** support PHP — PHP is the only top-10 GitHub
language without a CodeQL extractor. So CodeQL covers **`javascript-typescript` only**, and
**Psalm is the PHP static-analysis gate** in its place (§4 / §5). No custom queries; the
`security-and-quality` suite catches the JS classes that matter (injection, XSS, prototype
pollution).

**Implemented:** lives in `quality.yml` (not a separate `codeql.yml`) as the last step of the
**JS** job — checkout → npm audit → `codeql-action/init` (`languages: javascript-typescript`,
`queries: security-and-quality`) → `analyze`. The PHP job has no CodeQL step by necessity.

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
3. A test suite exists and runs in CI on every PR
4. CodeQL runs on every PR and push to main
5. CONTRIBUTING.md and AGENTS.md exist and are accurate
6. Branch protection + PR rules are enforced on GitHub
7. The publish workflow has been run at least once with `push: true` and produced a
   real GitHub Release with a valid tarball

At that point the repo is in a state where Chapter 3 (store submission) is just execution.
