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
     testing, CI, security work. Tracks the devops to the marker where the integration
     suite runs green in the pipeline.
   - [Chapter_3_An_Audition.md](saga/Chapter_3_An_Audition.md) — the second round of
     coding the safety net made safe: the mode-model + motion refactor and edge-case
     features. **§14 is the current feature backlog/ledger.**
   - [Chapter_4_Showtime.md](saga/Chapter_4_Showtime.md) — branding, quality stamps,
     Nextcloud app store submission (CSR + pipeline).
   - [Chapter_5_The_Marquee_and_the_Meal.md](saga/Chapter_5_The_Marquee_and_the_Meal.md)
     — **on the store now**; post-release polish (connection UX + the dead-401 lesson),
     the tuned Copilot review bot, and the `nextcloud-grafana` cameo. **Currently open.**

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
- **The file extension is the compound `.n8n.json` — locked, don't "simplify" it.**
  The file *is* real JSON, so the `.json` tail means that **outside** Nextcloud (desktop
  sync, download) the OS opens it in a JSON editor with no extra setup; the `.n8n.` segment
  is the hook NC keys the custom mimetype / icon / file-actions off **inside** the UI.
  Plain `.json` → no custom icon/actions. Bare `.n8n` → off-Nextcloud the OS has no handler
  and nothing opens it. Both are worse; keep `.n8n.json`. (saga Ch4 R4)

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
- **A sensitive settings field always renders blank**, even when a value is stored
  (core never echoes it). So an admin can't tell "not set" from "already saved"
  from the field alone. Drive the card's copy from whether a value is stored (read
  it in `getSchema()`), and make the connection *test* distinguish a **missing**
  credential from a **rejected** one — different problems, and the error must say
  which. NB: `N8nApiException` is a `RuntimeException` subclass and stows the status
  in `httpStatus` (Exception code stays 0), so a `catch (RuntimeException)` before
  the 401 branch — or reading `getCode()` — silently hides the auth case. See
  `AdminSettings` + `N8nClient::describeConnectionError`.
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
- **Provision first, act second.** Group all install/setup steps up front, then a readiness
  gate, then the work. Don't stagger "install A → use A → install B → use B".
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

### After the PR is open — close the review loop

An automated reviewer (**GitHub Copilot code review**, driven by our
`.github/copilot-instructions.md` + `.github/instructions/*`) comments on every PR.
Close the loop before asking a human to review — don't leave a wall of open threads.

1. **Read the bot's threads back.** List them with `gh api graphql`
   (`repository.pullRequest.reviewThreads`) or `gh api repos/<owner>/<repo>/pulls/<n>/comments`.
   `review_on_push` re-reviews on every push, so expect duplicate / `isOutdated`
   re-posts of the same point — the push that fixed the code usually leaves its
   thread `isOutdated: true`.
2. **Triage each — worth it vs fluff.** Real correctness / security / nativeness
   issues are worth it. **Verify a claim against the framework before acting** (e.g.
   check whether a helper already escapes — `Util::sanitizeHTML` does `ENT_QUOTES`).
   The recurring fluff is: framework-internal ignorance, un-scoped old-browser
   paranoia, speculative *unreachable* edge cases, and low-value wording/docblock nits.
3. **Handled → resolve the thread.** After the fix lands, resolve it via the GraphQL
   mutation (thread id from step 1):
   `gh api graphql -f query='mutation { resolveReviewThread(input:{threadId:"<id>"}){ thread { isResolved } } }'`.
   Optionally reply `Fixed in <sha>` first.
4. **Not handled (fluff / declined) → reply, don't resolve.** Post a short reply
   prefixed **`[declined — safe to resolve]`** with the reason, and leave the thread
   open so the human can scan it and resolve in the UI if they agree. **Never silently
   resolve a thread you didn't address.**
5. Post a one-paragraph triage summary as a PR comment, and tell the human what you
   fixed vs declined.

If the fluff shows a *pattern*, fix it at the source — add the false-positive to the
"what not to flag" list in `.github/copilot-instructions.md` so the bot stops
re-raising it (that file, not the PR, is where you tune the reviewer).

### Shape of a feature change

Features here follow one repeatable shape — see **[CONTRIBUTING.md → Anatomy of a
feature change](CONTRIBUTING.md#anatomy-of-a-feature-change)** for the full version.
In short, a feature PR touches:

- a **feature file** in [`features/`](features/) — Gherkin first; flip its `@todo`
  scenario live in the same PR as the code (keep it DRY, `behat --dry-run` clean);
- the **code** in [`lib/`](lib/) — `Service` (logic) + thin `Listener`/`Controller`,
  wired in `AppInfo/Application.php`;
- **tests** — a unit test in [`tests/unit/`](tests/unit/) + step defs in a per-concern
  trait under [`tests/integration/bootstrap/Steps/`](tests/integration/bootstrap/Steps/)
  (add/grow a `*Steps` trait; the thin `FeatureContext` `use`s them all — don't bloat one
  file);
- **README** updates when user-facing behaviour changes;
- a **`## [Unreleased]`** changelog entry.

Two artifacts split by who's driving: **humans open an issue** to track the work;
**agents update the [saga](saga/)** (the durable "why" + lessons + remaining `@todo`
for the next session). Do the saga update — it's your memory across sessions.

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
