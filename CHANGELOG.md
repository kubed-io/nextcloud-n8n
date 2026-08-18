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

The first release since `0.1.5`, and the one where the mirror became properly two-way. Tags are now a single set shared across n8n, the file, and Nextcloud's own searchable pills — change them wherever you happen to be. Workflow files carry n8n's real timestamps, so Recent and Popular files finally tell the truth about your automation. The trash means the same thing on both sides, in a Team Folder as much as at home. And three things you may have been using are gone — read **Upgrading** first.

### ⚠️ Upgrading

- **Workflow files are now named `.n8n`, not `.n8n.json`.** Every existing workflow file is renamed for you on upgrade, wherever it lives — including files sitting outside a mapped folder. Your upgrade will not fail over one it cannot rename; a file left behind simply looks like an ordinary document the app ignores, so **rename it to `.n8n` yourself and it reconnects to its workflow**, with nothing lost. Outside Nextcloud, a `.n8n` file needs telling once which editor opens it.
- **Nextcloud 32–34.** The minimum is now 32, and 34 is supported.
- **Three features were removed** — the mode pills, the `n8n:ignore` tag, and the purge button. Each has a replacement or is handled for you on upgrade; see **Removed**.

### Added

- **Tags are one set across n8n, the Nextcloud pills, and the file's own `tags` array** — change any of the three and the other two follow, with no "Sync to n8n" click. Add one by name alone and n8n fills in the id for you.
- Tags keep up outside a mapped folder too, so they survive a move or a copy — and a file that arrives already tagged keeps those tags when it becomes a workflow.
- A `link` mapping mirrors tags one way, so a pointer is searchable too.
- A workflow file now carries the workflow's own created and modified dates, not the sync's — so Recent, Popular files, and sorting a folder by date all mean something.
- **The Nextcloud trash now mirrors n8n's archive in both directions**: archiving a workflow trashes its file, unarchiving brings it back out, and deleting it for good clears the trashed file. An archived workflow used to sit in your folder as though it were live.
- Archiving a workflow in n8n removes its **link** file outright, with no trash entry — there is nothing to restore a pointer to, because the workflow itself is fine.
- Restoring a workflow file from a Team Folder's trash now unarchives its workflow in n8n; only the personal trash ever did, so the file came back while the workflow stayed hidden.
- Moving a workflow file straight from one mapped folder to another now rebinds it to the folder it landed in, Team Folders included; it used to be refused, telling you to move it out and back in by hand.
- A copy is named once: the filename, the JSON name and the n8n name all say `Fleet Health (1)`. Workflows in one mapping may share a name, and their files take a numbered suffix and keep it.
- A bulk "Sync from n8n" now says how many files it removed.
- `occ n8n_sync:set-groups` changes the groups a mapped folder is shared with.

### Changed

- A release-readiness refactor pass: dead code from retired features removed across the app and its test suite, duplicated logic merged, and every stale docblock corrected — no behaviour changed.
- **BREAKING:** workflow files are named `.n8n`, not `.n8n.json` — see **Upgrading**.
- **BREAKING:** requires Nextcloud **32** (was 30) and supports up to **34**.
- **BREAKING:** deleting a link file is refused with a message instead of quietly stripping the workflow's mapping tag in n8n. Deleting one file for yourself should not un-map a workflow for everybody.
- **BREAKING:** moving or copying a link, or moving or copying anything into a link-mode folder, is refused with a message. A link could previously be moved into another mapping, which silently changed its mode.
- **Removing the tag that maps a workflow now takes it out of the mapping.** The file leaves Nextcloud and the workflow stays in n8n with only that tag removed — nothing is deleted and nothing is archived. Previously the tag was silently put back.
- **BREAKING:** a mapping is immutable except for its groups. Remove it and add it again to change one.
- A new mapping defaults to an admin-owned folder, not a Team Folder.
- A mapping's mode defaults to `link` when you don't set one, instead of the mapping being refused.
- A mapped folder appears as soon as you save the mapping, and one that cannot be provisioned is not saved at all.
- The groups a mapped folder is shared with are read from the folder, so sharing it anywhere shows up here.
- A `link` mapping's folder is no longer read-only.
- The REST API card shows whether an API key is already stored, and Test connection tells a missing key apart from a rejected one.
- Syncing a workflow's tags is faster.
- The admin settings say the same things in about a quarter fewer words.

### Removed

- **The "when you save a workflow file" timing option is gone.** Nextcloud → n8n writeback now runs in the background where that works and during the save where it does not, decided per instance. Nothing to configure, and no setting to get wrong.
- **BREAKING:** the `n8n:sync` / `n8n:link` / `n8n:unmapped` pills are gone. The mapping decides a file's mode and the file still shows you what it is, so the pills were a second copy nobody could edit. They are deleted on upgrade and disappear from the tag picker.
- **BREAKING:** the `n8n:ignore` tag is gone. Move a file out of its mapped folder to keep it in Nextcloud only, or drop the mapping tag to keep it in n8n only. Files currently marked ignored become ordinary workflow files again on the next sync.
- **BREAKING:** the admin "Purge Nextcloud files" button is gone, along with `occ n8n_sync:purge`. It deleted every mirrored file in one click on the promise that a sync would bring them back — which was only true for files that were faithful mirrors, and the ones that were not are exactly the ones you would miss. Removing a mapping still cleans up its own files.
- **BREAKING:** writeback via webhook is gone — the Webhook settings card, the webhook path and token, and the "Test webhook" button. It was never finished and never tested, but the form still asked for a path and a token, so it read like a feature that worked. Saving a workflow file writes it back over the REST API, which is what it always actually did. The idea is worth having and is recorded for a later version.
- **BREAKING:** the "Write back via the REST API" checkbox is gone. It existed to let you turn one of two channels off; with one channel, turning it off just meant saving a file silently did nothing.
- A link file can no longer be opened in the text editor.

### Fixed

- Saving an edit to a workflow file reaches n8n again.
- Copying a workflow file in a mapped folder makes the new workflow in n8n again; any workflow with a node was left as an untracked file beside the original.
- A copy made beside its source is no longer invisible to the app — it had no workflow in n8n and did nothing on click.
- The scheduled sync now keeps to its interval; a mapped folder could take twenty minutes to see a change.
- Scheduled n8n → Nextcloud sync can be turned on again — the checkbox now saves.
- A sync no longer marks every workflow file as modified.
- Emptying the trash now really deletes the workflow in n8n.
- Emptying a Team Folder's trash now deletes the workflow in n8n, instead of leaving it archived for good.
- Restoring a file whose workflow was deleted in n8n while it sat in the trash now creates the workflow again.
- The mapping Mode help no longer offers a "Backup" mode — there has not been one since 0.1.3.
- The Sync Actions panel no longer prints an internal note next to a run that succeeded, where a stale one could sit for months.
- The admin "Test connection" button is CSRF-protected.
- Saving a workflow file now reaches n8n on instances where background jobs only run when someone visits a page — the Nextcloud default. The push was queued and could sit unrun indefinitely.
- Answering "keep the new version" when a workflow file replaces one already in a mapped folder no longer archives the workflow you kept, and no longer leaves a second workflow behind in n8n — the file that lands keeps the workflow that was already there and simply gives it new contents.
- Moving a workflow file back into a mapped folder now sends up any changes made to it while it was outside; edits made outside a mapping were quietly overwritten by the next scheduled sync.

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
