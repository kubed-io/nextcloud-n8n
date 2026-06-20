# AGENTS.md

> Cold-start orientation for AI coding agents. Keep it open in another tab.
> **Goal of this file:** get you productive in 60 seconds, then point you to the
> right deeper doc for whatever task you were given.

---

## What this repo is

**n8n Sync** — a Nextcloud app (PHP backend + small JS frontend) that maps n8n
workflows into Nextcloud folders as `.n8n.json` files with bidirectional sync.
Lives under the [kubed-io](https://github.com/kubed-io) GitHub org. Licensed
AGPL-3.0-or-later. Target: the official Nextcloud app store.

For the user-facing "what does it do?" → [README.md](README.md).

## What this repo is **not**

- Not an `External Storage` backend (rejected, see saga §0).
- Not a generic file plugin.
- Not a fork of any upstream Nextcloud app.

---

## First moves on any task

1. **Read [README.md](README.md)** if you don't already know what the app does.
2. **Read [CONTRIBUTING.md](CONTRIBUTING.md)** for the process — issue-first flow,
   PR rules, what CI expects, testing policy, release flow. **Don't re-derive any
   of this; the contributing doc is the source of truth.**
3. **Skim the relevant chapter of [`saga/`](saga/)** for the "why" behind the code:
   - [Chapter_1_The_Vibe.md](saga/Chapter_1_The_Vibe.md) — original design narrative
     and locked architectural decisions. **§15 is the authoritative current-state
     record — read it first.**
   - [Chapter_2_Pretty_Package.md](saga/Chapter_2_Pretty_Package.md) — packaging,
     testing, CI, security work. Tracks remaining work to a clean release.
   - [Chapter_3_Showtime.md](saga/Chapter_3_Showtime.md) — Nextcloud app store
     submission. Mostly future work.

If the task is about **how a thing works**, the README + the saga chapter are
where to look. If the task is about **the process of getting a change in**,
CONTRIBUTING.md owns that — don't ask the human to re-explain the PR flow.

---

## Repo map (where stuff lives)

| Path | What's there |
|---|---|
| [appinfo/](appinfo/) | NC app metadata (`info.xml`, routes). |
| [lib/](lib/) | PHP backend (`OCA\N8nSync`). Subdirs: `Controller/`, `Service/`, `Listener/`, `BackgroundJob/`, `Migration/`, `Command/`, `Settings/`, `Notification/`, `Exception/`, `AppInfo/`. |
| [src/](src/) | JS frontend source (Files row script). Vite builds `dist/n8n_sync-files.js`. |
| [tests/unit/](tests/unit/) | PHPUnit unit suite. Mirrors `lib/` tree. Standalone — no NC server needed. |
| [templates/](templates/) [css/](css/) [img/](img/) | Twig templates, styles, icons. |
| [config/](config/) | App-level static config (if any). |
| [.github/workflows/](.github/workflows/) | `tests.yml`, `quality.yml`, `publish.yml`. |
| [.devcontainer/](.devcontainer/) | PHP 8.3 + Node + GH CLI dev environment. |
| [saga/](saga/) | The long-form design narrative. **Read before refactoring anything non-trivial.** |
| [composer.json](composer.json) [package.json](package.json) | Dep manifests + script entrypoints. |
| [psalm.xml](psalm.xml) [.php-cs-fixer.dist.php](.php-cs-fixer.dist.php) [tests/psalm-baseline.xml](tests/psalm-baseline.xml) | Static analysis + style config. |
| [CHANGELOG.md](CHANGELOG.md) | Every PR adds a line under `## [Unreleased]`. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Process. **Read it.** |
| [SECURITY.md](SECURITY.md) | Vuln reporting policy. |

Out-of-repo but relevant: the [kubed-io](https://github.com/kubed-io) org has
shared workflow plumbing (issue templates, reusable actions) that this repo
inherits. The agent-task issue template lives at
[`kubed-io/.github`](https://github.com/kubed-io/.github).

---

## Core commands

```sh
# PHP
composer install
composer run test:unit       # PHPUnit unit suite
composer run cs:check        # php-cs-fixer dry-run
composer run cs:fix          # auto-fix style
composer run psalm           # static analysis
composer run lint            # php -l across lib/

# JS
npm ci
npm run build                # produces dist/n8n_sync-files.js
npm run watch                # rebuild on save
```

CI runs all of the above (see [CONTRIBUTING.md §What CI expects](CONTRIBUTING.md#what-ci-expects)).

---

## Architectural non-negotiables

These were decided once and **must not be relitigated** without a real reason
documented in the saga:

- **No `External Storage` / `OCP\Files\Storage` backend.** Wrong tool for "API
  ⇆ JSON files." (saga §0)
- **Files-Metadata API is the source of truth for the file↔workflow link**, not
  filenames. The stable link is the workflow ID embedded as `n8n_id`. Renames must
  preserve it. (saga §1, §3)
- **Steady-state inbound (n8n → NC) is the user's own n8n workflow** writing via
  WebDAV PROPPATCH + PUT. Our "Sync now ← n8n" button is the bulk / drift-repair
  fallback, not the steady path. (saga §1)
- **The metadata key is `n8n_mode = reference|sync`** (the saga sometimes still
  says `link` — treat them as synonyms).
- **Loop prevention is the `SyncGuard` request-scoped counter.** Pulls must not
  trigger pushes. (saga §3, lessons learned)
- **Custom mimetype `application/n8n+json`** drives the icon and the row click.
  Don't switch to extension-only detection.

---

## Hard-won gotchas

Things that have bitten contributors (human and AI) and shouldn't bite again:

- **Never bump `appinfo/info.xml` `<version>` in a feature PR.** It triggers a
  Nextcloud upgrade flow and can crash-loop the pod. The release workflow owns
  version bumps. See `/memories/repo/nextcloud-crash-loops.md` for the recovery.
- **Deploy to a running NC by copying files, not the whole dir.** `kubectl cp` of
  a directory clobbers permissions. Copy the changed files only. (saga §15)
- **CI PHP must match the prod pod's PHP** (currently 8.4). `php-cs-fixer` applies
  version-specific rules; a 8.3 CI job will disagree with an 8.4 pod. (Chapter 2 §5.2)
- **PSR-4 paths are case-sensitive** and must mirror namespaces segment-for-segment.
  A mismatch is a silent composer warning, not an error.
- **CodeQL has no PHP extractor.** PHP is scanned by Psalm SARIF, JS by CodeQL.
  Don't list `php` as a CodeQL language.
- **The Psalm baseline is the deferred-cleanup ledger.** Don't regenerate it on a
  feature branch. New findings should be fixed, not baselined.
- **LLMs ship stale action majors.** Verify with `gh api repos/<o>/<r>/releases/latest`
  before pinning anything in a workflow.
- **Never weave `${{ }}` into `run:` bash.** It's interpolated before the shell runs
  (injection risk + mixes templating with logic). Bind it to an `env:` entry and read the
  clean `$VAR` in bash. Prefer `env:` for static/derivable values too. Invoke scripts with
  `bash path/x.sh`, not the exec bit. (CONTRIBUTING.md → Workflow authoring conventions.)
- **`@nextcloud/files` major must match NC major.** v4 for NC 33+. Mismatched
  versions silently break the Files row script. (saga §11/§12)
- **Don't run heavy tools (Psalm) repeatedly in the shared prod pod.** Stacked
  processes thrash it. Let CI be the authoritative runner.

---

## Process — short version

Long version in [CONTRIBUTING.md](CONTRIBUTING.md). Short version:

1. **Issue first is preferred, not strictly required.** For non-trivial work, open an
   issue and let a maintainer weigh in on scope before you write code. Small obvious
   fixes can go straight to a PR.
2. **PR targets `main`.** Link the issue if there is one. Must pass CI and get one
   maintainer approval (hard gates).
3. **Tests on every PR** that touches `lib/`, when reasonable. Skip with a note if not.
4. **Changelog entry** under `## [Unreleased]`. **The changelog IS the release
   notes** — write for a user reading the release. One line per entry, never a
   paragraph. Length tracks user impact:
   - **Functional change** (a feature/behavior users notice, e.g. "Publish a
     workflow to n8n from the file action") → the most detail you get, but still
     one line. This is where words are warranted.
   - **Non-functional** (refactor, types, tests, lint) → short, often half a line.
   - **DevOps/CI/tooling not touching app code** → shortest, e.g. "CI: add
     integration workflow." No rationale, no file lists, no method names.
   - Only `**BREAKING:**` entries may stretch. The why / file lists / design go in
     the **saga** or PR description — never the changelog.
   - **Only ever edit `## [Unreleased]`.** Every section below it has a version
     number and is **immutable** — those notes already shipped; never reword,
     reorder, or delete them. New work always goes under `[Unreleased]`.
5. **Human validation on a real Nextcloud** is required before review — agents
   cannot skip this. State what was tested in the PR description.
6. **Release is manual** via `publish.yml`. Don't bump versions in feature PRs.

If you're working on behalf of a human, **point them at CONTRIBUTING.md** rather
than re-explaining the flow each session.

---

## Principles for AI work in this repo

- **Nextcloud-native first.** If there's a Nextcloud primitive, use it. Don't
  reinvent `IAppConfig`, `IClientService`, BackgroundJob, etc.
- **Validate hard.** Nitpick the diff. Read surrounding code before trusting your
  own edits. Re-derive test assertions from the spec, not from what the code
  happens to do today.
- **Verify external references.** Action versions, package versions, API endpoints —
  all of it. Use `gh api` / package registries to confirm.
- **A change is not done until a human has tried it on a real Nextcloud instance.**
  CI green is necessary, not sufficient. Make this easy for the human reviewer by
  saying exactly what to test.

---

## When stuck

- **"How does X work?"** → grep `lib/` + skim the relevant saga chapter.
- **"What's the convention for Y?"** → [CONTRIBUTING.md](CONTRIBUTING.md).
- **"Why was this decided?"** → [saga/](saga/), most likely Chapter 1 §15.
- **"Is this a vulnerability?"** → [SECURITY.md](SECURITY.md).
- **"Can I just refactor this?"** → only if tests cover it and the saga doesn't lock it.

That's the whole map. Now go read [CONTRIBUTING.md](CONTRIBUTING.md) before
opening anything.
