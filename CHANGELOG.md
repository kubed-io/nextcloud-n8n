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

- The release tarball now ships `LICENSE` (full AGPL-3.0 text), matching how the official Nextcloud apps package their releases.

### Changed

- README + feature specs rewritten to the target model: modes are `sync` / `link` / `unmapped` (no `backup`, no `writeback`), with the move (same-workflow / restore), copy (always-new), and reconcile/prune lifecycle. Spec-only; behaviour change tracked in saga Chapter 4.

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
