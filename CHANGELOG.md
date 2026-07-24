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

- **Bidirectional tag sync** — a workflow's n8n tags and its Nextcloud system-tag pills are now kept as one searchable set. A pull mirrors n8n's tags onto the file (and keeps any tag you added in Nextcloud); a push sends your Nextcloud tag edits back to n8n. A last-synced baseline tells an add apart from a remove, so removing a tag on either side removes it on the other; the reserved `n8n:` pills are never mixed into content; and a mapping's folder-binding tag is protected so removing its pill can never silently unbind the workflow (leaving a mapping is an explicit move-out or `n8n:ignore`). Specs in `features/tag-sync.feature`, saga §5.6.

### Changed

- REST API card now shows whether an API key is **currently stored** (the field itself always looks empty because the key is sensitive/encrypted), so you can tell "not set yet" from "already saved" at a glance.

### Fixed

- Test connection now tells a **missing** API key apart from a **rejected** one — an unset key says so, an invalid/expired one reports "n8n rejected the API key (HTTP 401)". Previously a rejected key surfaced n8n's raw error and looked the same as other failures. Same wording on the button and `occ n8n_sync:test-connection`.

## [0.1.5] - 2026-07-22

### Fixed

- Security: bumped `guzzlehttp/guzzle` (integration-test dependency) to 7.15.1 and `brace-expansion` (transitive lint dependency) to 2.1.2/5.0.7, clearing all open Dependabot alerts.
- Cleared two open Psalm findings in `N8nClient` (a redundant pagination-cursor check; the shared request helper's body type widened for `setWorkflowTags`'s list-shaped payload).

## [0.1.4] - 2026-07-20

### Added

- A **Purge Nextcloud files** admin button (in Sync Actions, also `occ n8n_sync:purge`) that removes the `sync`/`link` workflow files this app created across every mapping — n8n is never touched, and `unmapped`/`ignored`/standalone files are kept — so a later **Sync from n8n** brings them all back.
- Removing the app now **reverts its custom-mimetype registration** from the Nextcloud core tree (clean uninstall) while leaving your workflow files untouched, so a reinstall + sync reconnects them in place by id with no duplicates.
- The app now wears the real n8n logo: the Files-app filetype glyph, the "Open in n8n" / new-workflow actions, and a proper app icon (with dark variant) for the app list and settings sidebar.

### Changed

- Store listing copy is now real: the `info.xml` summary and description describe the app (Sync/Link modes, reconcile-by-id backup) instead of the old "Phase 0 skeleton" placeholder, plus a `files` category and project website for the app store.
- The README now carries status badges (Tests, Quality, Integration, License, Nextcloud, PHP) and release notes link the CI + license badges.
- Store listing now ships screenshots (Files view, context menu, create/edit, admin mappings + actions), wired into `info.xml`.

- Internal cleanup (no behaviour or settings change): one shared helper for the `.n8n.json` file check and one for the workflow metadata stamp, plus clearer class names (writeback → sync/push).
- Internal: a file's n8n metadata now reads back as a typed `ManagedFile` value object, replacing the repeated array-poking guard across ~16 lifecycle sites (no behaviour change).
- Internal: all n8n workflow body shaping (create/update request bodies + the sync/link file encodings) lives in one `N8nWorkflowBody` codec instead of four copies, so the n8n schema contract changes in one place (no behaviour change).
- Internal: the folder-mapping list is parsed once per request, and the legacy-row rewrite moved off the read path into a proper upgrade repair step (`MigrateMappings`) — `MappingService::list()` no longer re-decodes or sometimes re-writes config on every call (no behaviour change).
- Internal: n8n cursor pagination (workflows + tags) shares one bounded walk in `N8nClient` instead of two copies (no behaviour change).
- Docs: document the `occ n8n_sync:sync` command; corrected stale code comments (the removed per-file mode override) and saga chapter citations.
- Docs: SECURITY.md now documents the deliberate `allow_local_address` (SSRF) trade-off — the app opts out of Nextcloud's local-address guard so it can reach a self-hosted n8n at a private/in-cluster address (admin-trust boundary, single n8n target).
- CI: the publish workflow can now upload a release to apps.nextcloud.com (signed, secret-gated).

## [0.1.3] - 2026-06-25

### Added

- Two manual per-mapping sync controls — **Sync from n8n** (pull the mapping's tagged workflows into its folder, updating files in place by id and pruning files whose workflow lost the tag) and **Sync to n8n** (push the mapping's `sync` files up); both ignore files outside the mapping. Also available as `occ n8n_sync:sync <pull|push> --mapping=<tag>`.
- The release tarball now ships `LICENSE` (full AGPL-3.0 text), matching how the official Nextcloud apps package their releases.
- Move a synced workflow file *out* of its mapped folder and it becomes **unmapped** — Nextcloud keeps the full JSON while the workflow is archived in n8n; move it back into any mapping and the same workflow is restored (unarchived), not re-created. Moving a `link` out is refused (it's only a pointer).
- Copying a workflow file always makes a **brand-new instance** — the copy never inherits the original's `n8n_id`; its metadata and ownership tag are stripped, and a copy that lands in a mapped folder is registered as a fresh workflow in n8n (a copy outside any mapping stays a plain file).
- Workflow files now offer mode-aware openers — **Open in n8n** (shown only when a live workflow exists, i.e. `sync`/`link`) and **Open with text editor** (always available); a plain click defaults to n8n for `sync`/`link` and to the text editor otherwise.
- Optional, per-workflow **`n8n:ignore` reserved tag** read at pull time — a workflow carrying it is excluded from its mapping (never pulled). Hand-tagging a managed file `n8n:ignore` in Nextcloud gives it the new **`ignored`** mode: the file stays put and keeps its id while the workflow is archived in n8n and every sync skips it. The app only ever reads this tag off workflows — it never writes it.
- **Un-ignore restores a file** — removing the `n8n:ignore` tag from a managed file unarchives its workflow in n8n and returns the file to its mapping's mode (`sync` or `link`), the inverse of hand-tagging it ignored. Driven by a tag-removal listener (`TagUnassignedEvent`).
- **Moving a duplicate workflow file into a mapping that already syncs it follows Nextcloud's own rules** — a same-name move is refused (the existing synced file is the source of truth), and a differently-named one is minted as a brand-new workflow in n8n (copy semantics), leaving the existing file and its workflow untouched. Nothing is ever silently deleted.
- Workflow files are a **first-class file type** over WebDAV — they carry the custom `application/n8n+json` mimetype (and the n8n icon), and a desktop client's PROPFIND sees the four `nc:metadata-*` properties (`n8n_id`, `n8n_mode`, `n8n_mapping`, `n8n_managed`). Those properties are **read-only** (a PROPPATCH against them is rejected — the sync engine owns them), and `n8n_mode` carries the descriptive value (e.g. `sync`, `unmapped`).
- Folder mappings **nest** — a mapping on a subfolder of an already-mapped folder takes precedence for files inside it (the nearest-enclosing mapping wins), so a workflow's membership is always the closest mapped folder above it.

### Changed

- Folder mappings collapse to a single mode — `sync` or `link` (the `backup` mode and the separate `writeback` setting are gone; `sync` is the former two-way). Existing mappings auto-migrate the first time they're read.
- README + feature specs rewritten to the target model: modes are `sync` / `link` / `unmapped` (no `backup`, no `writeback`), with the move (same-workflow / restore), copy (always-new), and reconcile/prune lifecycle. Spec-only; behaviour change tracked in saga Chapter 3.
- A folder mapping's mode (`sync` / `link`) is now the **single source of truth** for how its workflows are held — the per-file **Toggle n8n mode** context action and the per-workflow `n8n:sync` / `n8n:link` reserved overrides are gone. To change a folder's mode, change its mapping; `n8n:ignore` remains the only per-workflow exception (exclude), and the `unmapped` move-out lifecycle is unchanged.

### Fixed

- A **link** workflow file is now read-only on disk — editing its bytes from a WebDAV client, the desktop client, or `curl` is refused with a native 403 (a link is only a pointer to a workflow in n8n; there is nothing to edit, and a write would just corrupt the pointer). The block is logged and surfaced as a notification explaining how to switch the file to sync. The Files context-menu **Open with text editor** is likewise hidden for links — it now appears only on files that hold the full JSON (`sync` / `unmapped` / `ignored`), making “switch to sync to edit” the clear sync-vs-link distinction.
- An **unmapped** workflow can no longer be turned into a `link` — there is no link outside a mapping. Hand-setting `n8n:sync` / `n8n:link` on an unmapped file is refused server-side: the full JSON is kept and the `n8n:unmapped` pill is re-asserted, closing a path that would have collapsed the body to a pointer at an archived workflow (silent data loss). Move the file back into a mapping to revive it.

### Tests

- Unit tests for the mode model: `Mapping` legacy-shape migration, `OwnershipTags::tagFor`, and the `DeleteService` sync/link rule table; integration suite updated to the single-mode mappings.
- Unit tests for the move lifecycle (`MotionService`: archive-on-move-out, restore-on-move-in, hard-deleted create-fallback, 404-idempotency) and live `move.feature` scenarios over WebDAV; Gherkin Backgrounds DRYed to a single `the app is connected to n8n` step.
- Unit tests for the copy lifecycle (`CopyService`: strip-then-create in a mapping, strip-only outside one) and live `copy.feature` scenarios over WebDAV.
- Integration `FeatureContext` split from one 1300-line class into per-concern step traits (`bootstrap/Steps/`) + transport/setup traits (`bootstrap/Support/`); behaviour-identical, dry-run clean.
- Integration suite now proves the `ignored` mode end-to-end — the mode-aware openers (Open in n8n hidden, text editor the default) and the read-only `n8n_mode` DAV value for an ignored file.
- Unit + live integration coverage for **nested folder mappings** — `MappingService::resolveForPath` nearest-enclosing resolution (deepest mapped folder wins; siblings sharing a name prefix are not swallowed) and the `mapping-membership.feature` scenarios proving membership over WebDAV.
- Live `delete.feature` coverage for the **unmapped** no-op legs — trashing or restoring a moved-out file (mapping cleared, workflow already archived) leaves n8n untouched: the workflow stays present and archived. (Purge stays deferred — a trashbin-DAV purge doesn't fire the delete event in CI.)
- Live `move.feature` coverage for the two **move-in mint** paths — moving a brand-new untracked `.n8n.json` into a mapping **creates** the workflow (create-on-land on the move event), and moving an unmapped file back in when its workflow was **hard-deleted** in n8n falls back to creating it fresh. (Merge-on-collision stays the lone `@todo` — it needs a metadata-by-id lookup.)
- Unit (`ModeChangeService::unignore`) + live `reserved-tags.feature` coverage for **un-ignore** — removing `n8n:ignore` unarchives the workflow and re-modes the file to its mapping default (or `sync` outside any mapping); no-ops on a non-ignored file and leaves the file untouched if the unarchive fails.
- Unit (`MotionService::moveIn` sibling-by-id scan) + live `move.feature` coverage for a **move-in duplicate** — a same-name duplicate move is refused by Nextcloud (Overwrite:F → 412), and a differently-named one is minted as a brand-new workflow (copy semantics) while the existing synced file is left untouched; a sibling carrying a *different* id still takes the normal unarchive path. This closes the last `move.feature` `@todo`.
- Unit (`ModeChangeService`) coverage for the **unmapped link-guard** — re-moding an unmapped file to `sync` or `link` is refused (no body rewrite, no n8n call, no metadata write) and re-asserts the `n8n:unmapped` pill instead.
- Unit (`LinkWriteGuardPlugin`) + helper (`canEditAsText`) coverage for the **link read-only guard** — a WebDAV overwrite of a link file throws Sabre `Forbidden` (→ 403) and notifies, while sync/unmapped/ignored/unmanaged files stay writable; the text editor is offered for every mode except `link`. (The guard is a Sabre `beforeWriteContent` plugin, not a `BeforeNodeWrittenEvent` listener — core only emits that pre-write event on the non-part-file branch of `File::put()`, so a normal PUT slips past it.)
- Removed the per-file mode-toggle tests with the feature — `mode-change.feature` and the `n8n:sync` / `n8n:link` override scenarios in `reserved-tags.feature` are gone, `ReservedTagResolverTest` trimmed to ignore-only. New `tests/external-stubs.php` declares the Sabre/DAV symbols `nextcloud/ocp` doesn't ship, shared by the unit bootstrap (so the plugin's collaborators can be doubled) and `psalm.xml` `<stubs>` (so static analysis resolves them).

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
