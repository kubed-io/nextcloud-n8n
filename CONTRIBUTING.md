# Contributing

Thanks for stopping by. This is **n8n Sync** — a Nextcloud app that surfaces n8n
workflows as native files. It lives under the [kubed-io](https://github.com/kubed-io)
GitHub org, shares some workflow plumbing with the rest of that org, and has a deliberate
process around getting changes in. Please read this whole doc before you push code —
most of the "why is my PR stuck?" questions are answered below.

If you only have time for one paragraph: **prefer opening an issue first so the work
can be scoped and approved, then open a PR with tests and a clear changelog entry, and
verify your change on a real Nextcloud instance before asking for review.**

---

## Repo tour

A quick map so you know where to look. Each entry has a one-liner; the file/folder
itself is the authoritative detail.

| Path | What lives here |
|---|---|
| [README.md](README.md) | User-facing docs: what the app does, modes, admin settings, CLI commands. **Start here for "how does it work?"** |
| [CHANGELOG.md](CHANGELOG.md) | Keep-a-Changelog format. Every PR adds a line under `## [Unreleased]`. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | This file — process, conventions, dev loop. |
| [SECURITY.md](SECURITY.md) | How to report vulnerabilities. Read before filing a "security" issue publicly. |
| [AGENTS.md](AGENTS.md) | Cold-start orientation for AI coding agents. |
| [saga/](saga/) | Long-form design narrative across chapters. Chapter 1 = how the app was built; Chapter 2 = packaging it for the world; Chapter 3 = the audition (the mode/motion refactor + edge-case features); Chapter 4 = app store submission. **The "why" behind the code.** |
| [appinfo/](appinfo/) | Nextcloud app metadata (`info.xml`, routes). The store validates against this. |
| [lib/](lib/) | PHP backend (`OCA\N8nSync`): controllers, services, listeners, migrations, background jobs. |
| [src/](src/) | JS frontend source (Files row icon + "Open in n8n" action). Built by Vite into `dist/`. |
| [templates/](templates/), [css/](css/), [img/](img/) | Twig templates, styles, icons. |
| [tests/](tests/) | PHPUnit unit suite (`tests/unit/`), bootstrap, phpunit config, Psalm baseline. |
| [composer.json](composer.json) | PHP deps + scripts (`test:unit`, `cs:check`, `cs:fix`, `lint`, `psalm`). |
| [package.json](package.json) | JS deps + scripts (`build`, `dev`, `watch`). Node version pinned in `.nvmrc`. |
| [psalm.xml](psalm.xml), [.php-cs-fixer.dist.php](.php-cs-fixer.dist.php) | Static analysis + coding standard config. |
| [.devcontainer/](.devcontainer/) | One-shot dev environment (PHP 8.3 + Node + GH CLI + docker-out-of-docker). |
| [.github/workflows/](.github/workflows/) | `pr.yml` (PR housekeeping), `tests.yml` (build + unit), `quality.yml` (audit + lint + Psalm), `publish.yml` (release tarball). |
| [vite.config.js](vite.config.js) | Frontend build config. |

Things that don't live here yet but are coming: the integration test suite, the
docker-compose stack that backs it, and the Nextcloud app store submission artifacts.
See [saga/Chapter_2_Pretty_Package.md](saga/Chapter_2_Pretty_Package.md) for the running
to-do list.

---

## Principles

Internalize these. They are the difference between a PR that merges and one that
spirals.

### Do things the Nextcloud way

This is a **Nextcloud app**, not "a PHP project that happens to run inside Nextcloud."
When you need to pick between a Nextcloud-native primitive and a generic one, pick the
Nextcloud one — every time. Examples:

- Background work → `OCP\BackgroundJob\*`, not raw cron.
- Config storage → `IAppConfig`, not files.
- HTTP out → `IClientService`, not `curl`.
- File metadata → the WebDAV/Files-Metadata API, not ad-hoc tables.
- Settings UI → the admin settings section pattern, not a bespoke route.
- Tags, mimetypes, notifications, activity, flow → use the real subsystems.

If a Nextcloud-native path isn't obvious, look at how a mature first-party app does it
(Deck, Files, integration_openai are good references) before inventing.

### Validate on a real Nextcloud instance

CI green is necessary, not sufficient. **Every change must be tried by a human on a
real Nextcloud instance with the change applied.** Spin up the devcontainer, deploy the
app into a running NC, click the thing, watch the logs. Until the integration suite in
[saga/Chapter_2_Pretty_Package.md §4a](saga/Chapter_2_Pretty_Package.md) lands, this is
the only check that catches real-world wiring bugs. State explicitly in your PR
description **what you tested, where, and how.**

### When AI writes code, validate harder

AI assistance is welcome — most of this app was built with it — but the quality bar does
not move. If an agent wrote it:

- **Nitpick everything.** Names, signatures, defaults, error paths, the lot.
- **Read the surrounding code before trusting the diff.** Agents will happily invent
  helpers that already exist or misuse APIs that are right next to the line they changed.
- **Re-derive the assertion before the test.** First-pass AI tests often assert what the
  code happens to do, not what the spec says. See `FilenameCodec` in
  [saga/Chapter_2_Pretty_Package.md §5.2](saga/Chapter_2_Pretty_Package.md) for a worked
  example.
- **Verify external references.** Action versions, package versions, API endpoints — all
  of it. LLMs reach for stale majors. Check `gh api repos/<o>/<r>/releases/latest`.

The human submitting the PR owns the diff. "An agent wrote it" is not a defense.

---

## The flow: issue → PR → merge

The steps below describe the happy path. Steps 1–2 are **strongly encouraged but not
hard-gated** — they exist so non-trivial work gets scoped before code is written, not to
bureaucratize a typo fix. Steps 3 onward are the actual gates.

1. **Prefer opening an issue first.** Use the [`🤖 Agent task` template](https://github.com/kubed-io/.github/blob/main/.github/ISSUE_TEMPLATE/agent-task.yml)
   from the org defaults, or a plain issue if it's a small fix. Describe the problem and
   what "done" looks like. For obvious small fixes (typo, dependency bump, one-line bug)
   you can skip straight to a PR.
2. **Let a maintainer weigh in on the issue** before writing code on anything non-trivial.
   This is where scope is agreed and dead-end PRs are avoided. A short comment or a label
   (e.g. `approved`, `enhancement`) is enough — there's no formal sign-off ceremony.
3. **Branch from `main`**, work, push, **open a PR** targeting `main`. Link the issue if
   there is one.
4. **Update [`CHANGELOG.md`](CHANGELOG.md)** with an entry under `## [Unreleased]` for
   any user-visible change. This is enforced in CI by
   [`tarides/changelog-check-action`](https://github.com/tarides/changelog-check-action)
   — a PR with no `[Unreleased]` diff fails the check. Internal-only refactors can use a
   one-line entry under `Changed` saying what was refactored.
5. **CI must pass.** All required workflows green (see [What CI expects](#what-ci-expects)).
   This is a hard gate.
6. **Get at least one approval** from a maintainer. This is a hard gate enforced by
   branch protection. Address review comments by pushing more commits — don't force-push
   over the review unless asked.
7. **Squash-merge** (default) once CI is green and approved. The PR title becomes the
   commit message — keep it clean.
8. **Release** is a separate, manual step (`publish.yml` workflow with `push: true`),
   not on every merge. The merge just lands the change in `## [Unreleased]`.

---

## Anatomy of a feature change

Most features here follow one repeatable shape — a spec, the code, docs, tests. Touch all
four and a feature PR practically reviews itself. Concretely, a feature PR should land:

- **A feature file** in [`features/`](features/) — the behaviour in Gherkin, *first*. It's
  the contract and the integration test at once. New behaviour starts life here, often as
  `@todo` scenarios that document the target before the code exists; you flip a scenario
  **live** (drop its `@todo`) in the same PR that lands its code. Keep the Gherkin DRY —
  reuse shared `Background` steps (e.g. `Given the app is connected to n8n`) rather than
  re-spelling setup. Validate with `behat --dry-run`: every live step must resolve.
- **The code** in [`lib/`](lib/) — a `Service` for the testable logic, a thin `Listener`
  (or `Controller`/`Command`) as the event adapter, wired in `lib/AppInfo/Application.php`.
- **A unit test** in [`tests/unit/`](tests/unit/) for the service's rules, plus the step
  definitions that make the feature file's live scenarios run. These live in per-concern
  traits under [`tests/integration/bootstrap/Steps/`](tests/integration/bootstrap/Steps/)
  (one trait per feature area — `CreateSteps`, `MoveSteps`, `CopySteps`, …), with shared
  transport/setup helpers in [`bootstrap/Support/`](tests/integration/bootstrap/Support/)
  (`OccTrait`, `WebDavTrait`, `N8nApiTrait`, `SetupTrait`). The thin
  [`FeatureContext`](tests/integration/bootstrap/FeatureContext.php) just owns the shared
  state + teardown and `use`s every trait. **Add a new `*Steps` trait (or grow the right
  existing one) — don't pile every feature's steps into one file.**
- **README updates** when the feature changes what a user can do — keep the user-facing
  prose and the spec/impl links accurate.
- **A `## [Unreleased]` changelog entry** (see [the flow](#the-flow-issue--pr--merge) above).

Two artifacts differ by who's driving:

- **Humans:** open **an issue** first to track what's desired or broken — the place scope
  gets agreed before code is written (see steps 1–2 above).
- **Agents:** update the **[saga](saga/)** — the long-form "why" behind the change (the
  design narrative, the lessons learned, what's still `@todo`). The saga is the agent's
  durable memory across sessions; the changelog and README are for users, the saga is for
  whoever picks up the work next.

---

## Getting set up

The devcontainer is the supported path. Anything else, you're on your own.

### With the devcontainer (recommended)

1. Install Docker and the VS Code "Dev Containers" extension.
2. Open this repo in VS Code → "Reopen in Container."
3. Wait for `postCreateCommand` to finish (`nvm install && nvm use && npm install`).
4. You now have PHP 8.3, Node (per `.nvmrc`), `gh`, and docker-outside-of-docker.
5. Run `composer install` to pull PHP dev deps.

You still need a Nextcloud instance to deploy into — the integration stack
(docker-compose with NC + n8n + db) is described in
[saga/Chapter_2_Pretty_Package.md §4a](saga/Chapter_2_Pretty_Package.md). For a full
end-to-end smoke test, the homelab cluster's `cloud` namespace is the canonical target.
That instance runs the **marketplace stable** build, so overlaying a branch is done
through a guarded script rather than raw `kubectl cp` — see
["Live smoke test on the cluster"](#live-smoke-test-on-the-cluster) below.

### Without the devcontainer

You'll need PHP 8.4 (matches the prod pod and CI), Composer, Node from `.nvmrc`, and
docker. Then:

```sh
composer install
npm ci
npm run build
```

---

## The build loop

Repeat this loop until the thing works:

1. **Edit** PHP in `lib/` or JS in `src/`.
2. **Build the frontend bundle** (only when JS changed):
   ```sh
   npm run build      # one-shot
   npm run watch      # rebuild on save
   ```
   Output lands in `dist/n8n_sync-files.js`.
3. **Deploy into a running Nextcloud** (the app folder must appear under `custom_apps/`).
   For the cluster, use the guarded script — see
   ["Live smoke test on the cluster"](#live-smoke-test-on-the-cluster).
   **Do not bump `appinfo/info.xml` `<version>` during local dev** — NC will trigger an
   upgrade flow and you'll waste a pod restart (worse: a crash-loop against a stable install).
4. **Verify in the UI / via `occ`.** Watch `data/nextcloud.log` (or the pod logs).

The CLI commands documented in [README.md](README.md#cli-commands) are the fastest smoke
test for the n8n REST client.

### Live smoke test on the cluster

The `cloud` namespace runs the **App Store stable** `n8n_sync`, so a naive file overlay
of a branch (whose `info.xml` is a newer version) would trip Nextcloud's upgrade flow and
can crash-loop the pod. The cluster repo ships a guarded loop that backs up the pristine
build, **pins the staged `info.xml <version>` to the installed one**, overlays only the
code, and never touches the live URL/API key:

```sh
# in the cluster repo
bash apps/nextcloud/components/n8n/deploy-dev.sh            # pull branch, build, overlay
SKIP_PULL=1 bash apps/nextcloud/components/n8n/deploy-dev.sh   # deploy local working tree
bash apps/nextcloud/components/n8n/restore-stable.sh        # revert to the marketplace build
```

opcache auto-revalidates (~60s), so no pod restart. Then smoke-test in **Files** and
**Settings → Administration → n8n Sync**. A live-pod smoke test is a standing pre-approval
obligation (see `AGENTS.md`) — CI green is not a substitute.

---

## Testing

The unit suite is **required on every PR that touches `lib/`**. Integration and e2e
suites don't exist yet (see [Chapter 2 §5](saga/Chapter_2_Pretty_Package.md)); when they
do, the same rule applies.

### Run the unit suite

```sh
composer run test:unit
```

Tests live in `tests/unit/`, mirroring the `lib/` tree. The bootstrap is standalone — no
running Nextcloud needed. NC collaborators are mocked against `nextcloud/ocp` interfaces.

### What to write

- **Pure-logic classes** (`FilenameCodec`, `Mapping` validation, `SyncGuard`,
  `DeleteService` rule table, interval parsing): test the contract directly.
- **Classes with NC deps**: mock the collaborators (`IAppConfig`, `IClientService`,
  controllers, etc.).
- **HTTP / DB / real-NC behavior**: out of scope for the unit suite. These become
  integration tests once §4a lands.

### Policy

> **Every PR should have tests covering the change when it is reasonable to do so.**

"Reasonable" is judgement: a typo fix or a doc change doesn't need a test; a new
service method, a bug fix, or a behavior change does. If you choose not to add a test,
say so in the PR description and why. The default answer is "yes, add a test."

### Static analysis + coding standard

These also run in CI; run them locally before pushing to save a round trip:

```sh
# PHP
composer run cs:check    # php-cs-fixer dry-run
composer run cs:fix      # auto-fix
composer run psalm       # static analysis (uses tests/psalm-baseline.xml)
composer run lint        # php -l across lib/

# JS
npm run lint             # ESLint
npm run lint:fix         # ESLint auto-fix
```

Psalm has a committed baseline (`tests/psalm-baseline.xml`) — **don't regenerate it on
your branch** unless you're explicitly paying down the debt. New findings should be
fixed, not baselined.

The ESLint config ([`eslint.config.js`](eslint.config.js)) is intentionally minimal —
flat config + `@eslint/js` recommended rules, with the legitimate Nextcloud page-scoped
globals (`t`, `n`, `OC`, `OCA`, `OCP`) declared so the legacy `js/*.js` admin scripts
lint clean. Add an issue before broadening or narrowing the rules.

---

## What CI expects

Four workflows run on PRs into `main` (and where it makes sense, on push to `main`):

| Workflow | Trigger | Jobs | Must pass? |
|---|---|---|---|
| [`pr.yml`](.github/workflows/pr.yml) (🔀 PR) | PR only | Auto-assign author + changelog check | yes |
| [`tests.yml`](.github/workflows/tests.yml) (🧪 Tests) | PR + push to `main` | PHP unit (PHPUnit + JUnit report) + JS build | yes |
| [`quality.yml`](.github/workflows/quality.yml) (🛡️ Quality) | PR + push to `main` | composer audit + php-cs-fixer + Psalm (→ SARIF) + ESLint + npm audit | yes |
| `CodeQL` (default setup) | PR + push to `main` | GitHub-managed JS/TS code scanning | yes |
| [`publish.yml`](.github/workflows/publish.yml) (🧬 Publish) | manual `workflow_dispatch` | release tarball | n/a |

What the workflows look for from your PR:

- **`CHANGELOG.md` has a new entry** under `## [Unreleased]`
  (`tarides/changelog-check-action`). The PR author is auto-assigned
  (`kentaro-m/auto-assign-action`) per [`.github/assign.yml`](.github/assign.yml).
- **PHP unit suite green.** Reported as a sticky PR comment + inline annotations on
  failure (`EnricoMi/publish-unit-test-result-action`).
- **JS bundle builds** without errors and produces `dist/n8n_sync-files.js`.
- **ESLint clean** — `npm run lint` exits 0. Real issues are caught (unused
  vars, undefined references, etc.); legitimate NC page-scoped globals (`t`,
  `OC`, `OCA`, `OCP`, `n`) are declared in [`eslint.config.js`](eslint.config.js).
  Use `npm run lint:fix` for auto-fixes.
- **No new php-cs-fixer violations.** Run `composer run cs:fix` locally if in doubt.
- **No new Psalm findings** above the baseline. If your change touches a baselined
  line, fix it rather than re-baselining.
- **No new high-severity advisories** from `composer audit` or `npm audit --omit=dev
  --audit-level=high`.
- **No new CodeQL alerts** (JS) from the GitHub default-setup scanner or **Psalm SARIF alerts** (PHP) in the Security tab.

Action versions in workflows are kept current by Dependabot (when configured). If you're
editing a workflow, **verify the action's latest major** with `gh api
repos/<owner>/<repo>/releases/latest` — the stale-major footgun is documented in
[Chapter 2 §5.2](saga/Chapter_2_Pretty_Package.md).

### Workflow authoring conventions

- **Never put `${{ }}` expressions inside `run:` bash.** GitHub interpolates them into the
  script *before* the shell runs — a script-injection hole and a mix of templating with
  logic. Instead, bind the expression to an **`env:`** entry (step- or job-level) and let
  bash read the clean `$VAR`:
  ```yaml
  - name: Do the thing
    env:
      VERSION: ${{ steps.bump.outputs.version }}
    run: echo "shipping $VERSION"     # not: echo "${{ steps.bump.outputs.version }}"
  ```
  `${{ }}` is fine in `with:`, `if:`, `name:`, `env:` values — just **not** woven into `run:`.
- **Prefer `env:` for static or derivable values too** (job-level `env:` for repo-wide
  constants like `APP_ID`), so each `run:` step reads as its actual purpose, not plumbing.
- **Invoke scripts with `bash path/to/x.sh`** rather than relying on the executable bit.
- **Provision first, act second — don't stagger.** Group all setup/install steps up front
  (checkouts, language runtimes, dependency installs, service bring-up), then a readiness gate,
  then the steps that *do the work*. Avoid the "install A → use A → install B → use B" pattern;
  prefer "install A → install B → … → now run everything." It reads clearly, fails fast on a
  bad dep before any work starts, and keeps phases obvious.

---

## Commits, changelog, versions

- **Commits**: keep them focused and descriptive. The PR squash-merge title is what ends
  up in `main`'s history, so make that one count. Conventional Commits prefixes
  (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`) are encouraged for the PR
  title even though they're not strictly enforced yet.
- **Changelog**: every user-visible change adds an entry under `## [Unreleased]` in
  [CHANGELOG.md](CHANGELOG.md), grouped by `Added` / `Changed` / `Fixed` / `Removed` /
  `Deprecated` / `Security`. The `tarides/changelog-check-action` CI step enforces that
  the `[Unreleased]` section has new content on every PR — a PR with no changelog entry
  fails the check. Internal-only refactors should still add a one-liner under `Changed`.

  **The changelog is the release notes.** One line per entry — never a paragraph,
  nested bullet, or implementation detail. Write for an end user reading "what's
  new," not a maintainer reading git history. Entry length tracks user impact:

  - **Functional change** (a feature/behavior users notice, e.g.
    `- Publish a workflow to n8n from the file action.`) → the most detail you
    get, but still one line. This is the only place richer wording is warranted.
  - **Non-functional** (refactor, types, tests, lint) → short, often half a line.
  - **Tooling / CI / DevOps not touching app code** → shortest, three or four
    words (e.g. `- Dependabot enabled.`, `- Bumped Vite to v8.`).
  - **`**BREAKING:**` is the only thing that may stretch** — what breaks, how to
    migrate — under `Changed`.
  - The deeper why / file lists / design go in the **saga** or PR description.
  - When in doubt, write the line, then cut it in half.
  - **Only ever edit `## [Unreleased]`.** Every versioned section below it is
    **immutable** — those notes shipped with a release; never reword, reorder, or
    remove them. This can only be enforced by convention, so respect it.

### 🚨 Versioning: never by hand, in any PR, for any reason

**SemVer, and the release workflow owns it.** Bumping a version by hand is a
violation in this repo — not a style preference and not a maintainer's shortcut.
Nothing in a feature, fix, or docs PR ever edits:

- `"version"` in `package.json` or `package-lock.json`
- `<version>` in `appinfo/info.xml`
- the `## [Unreleased]` heading in `CHANGELOG.md`
- a `v*` git tag

[`publish.yml`](.github/workflows/publish.yml) does all of it, manually dispatched
from the Actions tab. It refuses to push from any branch but `main`, runs
`npm version <bump>` to rewrite `package.json` + the lock, mirrors that version into
`appinfo/info.xml` with `sed`, and hands the three files to `duplocloud/version-bump`
to commit, tag and push.

**That action also rewrites `CHANGELOG.md`.** It is what converts `## [Unreleased]`
into a numbered, dated section at release time. So *preparing the changelog for a
release* means writing good entries under `## [Unreleased]` — and nothing else.
Renaming the heading yourself doesn't prepare the release, it fights the tool that
is about to do it and corrupts the notes in the process.

If a release genuinely needs cutting, that is a maintainer running the workflow, not
a commit.

- **Tags**: `v<major>.<minor>.<patch>`, applied by the release workflow via
  `duplocloud/version-bump`.

---

## Releases

Manual, intentional, not on every merge.

1. Maintainer goes to the Actions tab → `🧬 Publish Version`.
2. Picks the bump type (`patch` / `minor` / `major` / `pre*`).
3. **First run with `push: false`** to verify the tarball builds correctly.
4. **Second run with `push: true`** to actually commit the bump, tag, and create the
   GitHub Release with `n8n_sync-<version>.tar.gz` attached.

The release tarball is the artifact eventually consumed by the Nextcloud app store
(see [saga/Chapter_4_Showtime.md](saga/Chapter_4_Showtime.md)).

---

## Security

If you've found a vulnerability, **do not open a public issue.** Follow
[SECURITY.md](SECURITY.md).

---

## Where to look next

- **"How does this app work?"** → [README.md](README.md)
- **"Why was it built this way?"** → [saga/Chapter_1_The_Vibe.md](saga/Chapter_1_The_Vibe.md)
- **"What's the roadmap to a clean release?"** → [saga/Chapter_2_Pretty_Package.md](saga/Chapter_2_Pretty_Package.md)
- **"How does it get on the app store?"** → [saga/Chapter_4_Showtime.md](saga/Chapter_4_Showtime.md)
- **"I'm an AI agent — where do I start?"** → [AGENTS.md](AGENTS.md)

Thanks for contributing. Be kind in reviews, validate on a real instance, and write a
test if you reasonably can.
