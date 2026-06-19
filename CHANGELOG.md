# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `CONTRIBUTING.md` — process for human contributors: issue→PR flow, dev setup, build
  loop, test policy, CI expectations, changelog/versioning, release flow.
- `AGENTS.md` — cold-start orientation for AI coding agents: repo map, locked
  architectural decisions, hard-won gotchas, and pointers to deeper docs.
- `SECURITY.md` — vulnerability reporting policy via GitHub private security advisories,
  supported-versions, scope, secrets policy, disclosure timeline.
- `.github/workflows/pr.yml` — PR-only housekeeping workflow: auto-assigns the PR
  author (`kentaro-m/auto-assign-action`) and enforces a `CHANGELOG.md` entry under
  `[Unreleased]` (`tarides/changelog-check-action`).

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
