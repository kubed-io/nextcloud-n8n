# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One line per entry, written for a user — never a
  paragraph. Length tracks impact: functional changes get the most words (still
  one line); refactors/types/tests stay short; CI/devops are shortest. Only
  **BREAKING:** may stretch. Deeper detail lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->

## [Unreleased]

### Added

- Two manual per-mapping sync controls — **Sync from n8n** (pull the mapping's tagged workflows into its folder, updating files in place by id and pruning files whose workflow lost the tag) and **Sync to n8n** (push the mapping's `sync` files up); both ignore files outside the mapping. Also available as `occ n8n_sync:sync <pull|push> --mapping=<tag>`.
- Flip a managed file between **sync** and **link** by retagging it `n8n:sync` / `n8n:link` — the body is rewritten to fit the new mode (link collapses to a pointer, sync pulls the full JSON down), the other mode tag is stripped so exactly one remains, and the workflow's `n8n_id` is preserved.
- The release tarball now ships `LICENSE` (full AGPL-3.0 text), matching how the official Nextcloud apps package their releases.
- Move a synced workflow file *out* of its mapped folder and it becomes **unmapped** — Nextcloud keeps the full JSON while the workflow is archived in n8n; move it back into any mapping and the same workflow is restored (unarchived), not re-created. Moving a `link` out is refused (it's only a pointer).
- Copying a workflow file always makes a **brand-new instance** — the copy never inherits the original's `n8n_id`; its metadata and ownership tag are stripped, and a copy that lands in a mapped folder is registered as a fresh workflow in n8n (a copy outside any mapping stays a plain file).
- Workflow files now offer mode-aware openers — **Open in n8n** (shown only when a live workflow exists, i.e. `sync`/`link`) and **Open with text editor** (always available); a plain click defaults to n8n for `sync`/`link` and to the text editor otherwise.
- Optional, per-workflow **reserved n8n tags** read at pull time — `n8n:sync` / `n8n:link` override a mapping's default mode for one workflow, and `n8n:ignore` excludes one (never pulled). Hand-tagging a managed file `n8n:ignore` in Nextcloud gives it the new **`ignored`** mode: the file stays put and keeps its id while the workflow is archived in n8n and every sync skips it. The app only ever reads these tags off workflows — it never writes them.
- Workflow files are a **first-class file type** over WebDAV — they carry the custom `application/n8n+json` mimetype (and the n8n icon), and a desktop client's PROPFIND sees the four `nc:metadata-*` properties (`n8n_id`, `n8n_mode`, `n8n_mapping`, `n8n_managed`). Those properties are **read-only** (a PROPPATCH against them is rejected — the sync engine owns them), and `n8n_mode` carries the descriptive value (e.g. `sync`, `unmapped`).
- Folder mappings **nest** — a mapping on a subfolder of an already-mapped folder takes precedence for files inside it (the nearest-enclosing mapping wins), so a workflow's membership is always the closest mapped folder above it.

### Changed

- Folder mappings collapse to a single mode — `sync` or `link` (the `backup` mode and the separate `writeback` setting are gone; `sync` is the former two-way). Existing mappings auto-migrate the first time they're read.
- README + feature specs rewritten to the target model: modes are `sync` / `link` / `unmapped` (no `backup`, no `writeback`), with the move (same-workflow / restore), copy (always-new), and reconcile/prune lifecycle. Spec-only; behaviour change tracked in saga Chapter 3.
- The Files context-menu **Toggle n8n mode** action now flips a file `sync` ⇄ `link` in one click (it assigns the opposite `n8n:sync` / `n8n:link` tag and the listener re-modes it), instead of pointing at the Tags sidebar; shown only for `sync`/`link` files.

### Tests

- Unit tests for the mode model: `Mapping` legacy-shape migration, `OwnershipTags::tagFor`, and the `DeleteService` sync/link rule table; integration suite updated to the single-mode mappings.
- Unit tests for the move lifecycle (`MotionService`: archive-on-move-out, restore-on-move-in, hard-deleted create-fallback, 404-idempotency) and live `move.feature` scenarios over WebDAV; Gherkin Backgrounds DRYed to a single `the app is connected to n8n` step.
- Unit tests for the copy lifecycle (`CopyService`: strip-then-create in a mapping, strip-only outside one) and live `copy.feature` scenarios over WebDAV.
- Integration `FeatureContext` split from one 1300-line class into per-concern step traits (`bootstrap/Steps/`) + transport/setup traits (`bootstrap/Support/`); behaviour-identical, dry-run clean.
- Integration suite now proves the `ignored` mode end-to-end — the mode-aware openers (Open in n8n hidden, text editor the default) and the read-only `n8n_mode` DAV value for an ignored file.

## [0.1.2] - 2026-06-22

### Added

- `occ n8n_sync:add-mapping` / `list-mappings` / `remove-mapping` — manage folder mappings from the CLI.
- `occ n8n_sync:test-connection` — verify the n8n connection headlessly (same as the admin button).
- `occ n8n_sync:set-api-key` — store the n8n API key (encrypted) from the CLI.
- Integration tests now run on Behat (JUnit reported like the unit suite).
- Integration test: create-on-land — a `.n8n.json` written over WebDAV into a mapped folder creates + tags the workflow in n8n and stamps `n8n_id`.
- Integration test: rename — file rename and JSON-`name` edit propagate three ways (file ⇄ JSON ⇄ n8n) via `ReconcileNameJob`; the `n8n_id` link is unchanged.
- Integration test: delete — trashing a sync workflow archives it and restoring unarchives it; backup/link only strip the mapping tag; unmapped deletes don't touch n8n. (Purge → permanent-delete deferred — doesn't fire over the trashbin DAV endpoint in CI.)
- `CONTRIBUTING.md`, `AGENTS.md`, and `SECURITY.md`.
- `LICENSE` (AGPL-3.0).
- PHP unit tests (PHPUnit) and frontend unit tests (Vitest).
- Integration test scaffolding (Nextcloud + n8n).
- CI: test, quality, integration, and PR-housekeeping workflows.
- CI: Dependabot and Copilot agent setup.

### Changed

- Integration tests mint an n8n API key as a CI prerequisite.
- Gherkin `.feature` files moved to top-level `features/`.
- Migrated deprecated `IConfig` app-config calls to `IAppConfig`.
- Cleared most static-analysis findings (Psalm).

### Fixed

- Security: bumped dompurify and Vite, clearing both Dependabot alerts.

## [0.1.1] - 2026-06-19

### Added

- GitHub Actions release pipeline: `test`, `package`, and `publish` workflows
  that build the app tarball and attach it to GitHub Releases as the artifact
  registry.
- `build/package.sh` — produces a Nextcloud-conformant `n8n_sync-<version>.tar.gz`
  with the app folder at the archive root.
- `build/bump-version.sh` — keeps `package.json` and `appinfo/info.xml` in lock
  step before `duplocloud/version-bump` writes the changelog and tag.
- Devcontainer (PHP 8.3 + Node 22) with the toolchain needed to build the
  bundle and run `occ` against a Nextcloud test container.
- Copilot setup steps workflow and a project agent (`.github/agents/n8n-sync.agent.md`).

## [0.1.0] - 2026-06-18

### Added

- Initial Nextcloud app skeleton (`OCA\N8nSync`).
- Phase 0–5 working: admin section, mappings UI, dual-channel writeback,
  custom mimetype/icon, row-click "Open in n8n", "Edit as text" modal,
  managed-folder move-out veto.
- Manual sync (async for "all", inline per-mapping).
- Vite build of `dist/n8n_sync-files.js` for the Files row script.
