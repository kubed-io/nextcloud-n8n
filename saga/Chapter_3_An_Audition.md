# Chapter 3 — An Audition

> The devops is built (Chapter 2): the GitHub project, the CI flows, the unit +
> static-analysis layers, the security refactor, and — the marker that ends that
> chapter — the **integration suite running live in the pipeline**. That last beat is
> the hinge: the moment Behat ran green on a real Nextcloud + n8n in CI, the story
> turned from *"we have all the devops and workflows"* to *"now we can actually code."*
> This chapter is that second round of coding — the one the safety net was built for.

We call it **an audition** because that's what it is: a dress rehearsal. We have the stage
(devops), we have the script (the specs — every `features/*.feature` + the README were written
first, describing the target as if already shipped). Now we run the whole show under the lights,
one scene at a time, with **Behat** — the pickle-bannered warrior who joined the crew at the end of
Chapter 2 — sitting front-row as the audience that has to be convinced. Nothing here is public yet;
this is where we practice every move until it's flawless, so that when the real show comes
(Chapter 4 — Showtime) the app is ready for the market.

---

## Where this chapter sits

This chapter is **epic 6** of the saga's arc (the epic table lives in Chapter 2). The through-line
that leads here, restated from there:

1. DevOps + unit + static analysis *uncovered* a pile of issues.
2. → the **first refactor (security)** cleaned them up **and** readied the code.
3. → that enabled **integration testing** — which itself required changes to wire up.
4. → the integration suite is the safety net that makes **this** chapter — the mode-model
   overhaul + the motion/edge-case lifecycle — safe to attempt at all.
5. → *(Chapter 4)* once the app is market-ready, the work turns to identity (**branding**) and
   the app-store submission.

> Testing was never a gate bolted on at the end; it was the thing that made each refactor
> possible. Chapter 2 earned the net. Chapter 3 jumps.

**How the work is paced:** in **double features** — two agents (Copilot + Claude) each take one
feature slice, both land on a single "double feature" PR, serialized through one git owner. The
specs are the backlog; we flip `@todo` scenarios live as the code behind them lands, keeping the
suite green at every step. The running ledger of what's done vs. still `@todo` is the cap at the
end of this chapter (§14.7).

---

### 14. The mode-model & motion refactor ☐ (the payoff)

This is what all the testing + devops was *for*: a safety net thick enough to refactor the
core data model and finally build the deferred file-motion lifecycle without fear. Two linked
bodies of work — **(1)** collapse the muddled `mode`+`writeback` encoding into one clean,
descriptive `mode`; **(2)** build the **motion** lifecycle (move-out / move-back / copy /
merge) that Chapter 1 left as a "planned end state." The specs (`features/*.feature` + the
README) were written first, as the end-state requirements; the code follows under this item.

> **Status (2026-06-22):** specs authored (PR #27) — every feature file + the README describe
> the target as if shipped, new behaviour `@todo` so the live suite stays green. Code is
> Phase 1 (model collapse + migration) then Phase 2 (motion).

#### 14.1 The model — one `mode`, four values

| `mode` | What the file is | In a mapping? | Pushes to n8n? |
|---|---|---|---|
| **`sync`** | Full workflow JSON, NC-authoritative | yes | Yes (two-way) |
| **`link`** | Tiny pointer (id, name, URL) | yes | No — click opens n8n |
| **`unmapped`** | Full JSON, **ejected** from a mapping (moved out); archived in n8n; restorable | no (mapping cleared) | No |
| **`ignored`** | Full JSON, **left in** a mapped folder but deliberately skipped; archived/deleted in n8n | yes (mapping kept) | No |

`unmapped` vs `ignored` — both keep the id and are archived in n8n, but they differ by
**location + why the pull leaves them alone**: `unmapped` lives *outside* any mapping (so the
mapping-scoped pull never sees it); `ignored` lives *inside* a mapped folder, so the pull —
which walks that folder — needs an explicit mode value to know to **skip** it. That's the whole
reason `ignored` must be its own mode and can't just be `unmapped`. (Set via `n8n:ignore`; remove
the tag → back to the mapping default. `ignored` is not a callable string, so it stores as-is.)

**Open-with follows the mode** (a concern distinct from but related to the file type):
`sync`/`link` have a live workflow, so "Open in n8n" is the default click + context action;
`unmapped`/`ignored` have no live workflow, so "Open in n8n" is hidden and the **text editor** is
the default. The file type itself (mimetype, icon, read-only DAV metadata, indexed/queryable
mode) is `features/file-type.feature`; the openers are `features/open-with.feature`.

Decisions (locked):

- **Drop `writeback`** — the concept + the `nc:metadata-n8n_writeback` DAV property. Fully
  inferable from `mode` (`two-way` *was* just `sync`).
- **Drop `backup` mode.** Redundant with `sync` (sync already keeps the full JSON, so it *is* a
  backup); its read-only promise was **never enforced** (verified — no edit-disabling anywhere,
  backup just didn't push); `link` is the implicit read-only option; an `unmapped` file is a
  fine archive. Less surface, less redundancy. Migration: any `backup` → `sync`.
- **`link` everywhere** (code, UI, docs, tag). The single exception is the DAV property *value*:
  a stored value equal to the global `link()` function makes `is_callable()` true and crashes
  core PROPFIND, so `n8n_mode` for a link stores **`reference`** — isolated to one translation
  point in `WorkflowMetadata` with a note that `reference` ≡ `link`. `sync`/`unmapped` store as-is.
- **Index `n8n_mode`** — one descriptive field makes "every sync" / "every unmapped" a real query.
- **`unmapped` is an explicit, stored `mode`**, not derived — only `sync` files can ever become
  unmapped (so no info lost), and a single indexed `mode=unmapped` beats a compound query. Only
  files *ejected from a mapping* get stamped; a never-mapped `.n8n.json` stays untouched
  (that's "untracked", not "unmapped").

Metadata shape: `n8n_id`, `n8n_versionId`, `n8n_syncedHash` on all; `n8n_mapping` cleared only
when **unmapped** (kept when ignored — it's still in the folder); `n8n_mode` (indexed) =
`sync` | `reference`(=link) | `unmapped` | `ignored`; `n8n_writeback` removed.

**Out of scope (documented, not built):** if an n8n workflow carries *both* `n8n:sync` and
`n8n:link`, the pull resolves to **sync** (ignore the stray link) — optionally surfacing a
notification explaining what happened and the consequence. (NC-side both-tags is handled
differently — the just-added one wins, §14.2b — because there we can see which was added.)

#### 14.2 Motion — move, copy, restore, merge

**Move OUT** (sync only; link move-out stays blocked): archive the workflow in n8n
(`archiveWorkflow`), keep `n8n_id`+`n8n_versionId`, clear `n8n_mapping`, set `mode=unmapped`. NC
keeps the full JSON, so nothing is lost.

**Move BACK IN** (re-attach / restore / merge):
- id+versionId present → **unarchive/restore** in n8n (`unarchiveWorkflow`), re-stamp mapping +
  `mode=sync` (not a fresh create).
- **Merge on collision** — if the mapping *already* holds a file with that `n8n_id` (e.g. an
  admin restored it in n8n and it synced back while the unmapped copy still existed), the synced
  file is source of truth: **delete the incoming unmapped copy**, keep the existing one. Feels
  like a merge; no n8n call.
- no id → create-on-land makes a new workflow.

**Copy** (`features/copy.feature`): **always a brand-new instance — strip metadata on every
copy, everywhere.** Copy within a mapped folder → a *new* workflow in n8n; copy outside → plain
untracked file; copy of an unmapped file → stripped wherever it lands. This is what makes *move*
"the same workflow" and *copy* "a new thing."

Move scenario matrix (now in `features/move.feature`): within-mapping (no n8n change) · sync
out→unmapped+archive · unmapped in→restore · plain in→create · link out→blocked · unmapped
relocation→no-op · hard-deleted→create · merge-on-collision.

The duplicate state (one unmapped + one mapped, same id) is **fine and intentional** — it
resolves only at move-in (merge), never by a sync. The manual **Sync from/to n8n** buttons are
**mapping-scoped** and ignore unmapped files entirely (`features/reconcile.feature`).

Decision cases still open (need a call before they get live scenarios — `move.feature` comments):
**a** sync moved mapping→mapping (re-tag vs eject+reattach vs block); **b** nested mappings;
**c** link rename within its mapping; **d** deleting an unmapped file (trash no-op? purge
hard-delete the archived workflow?).

#### 14.2b Mode control — reserved tags + re-mode

How a workflow's mode is *chosen* and *changed*, on top of the mapping default:

- **Mapping tag = any name.** The `nextcloud:` prefix is convention only, not required.
- **Reserved n8n tags (optional, n8n side, app never writes them):** `n8n:sync` / `n8n:link`
  override one workflow's mode vs the mapping default; `n8n:ignore` excludes it — a never-pulled
  workflow gets no file, while a file already in a mapped folder enters **`ignored` mode**
  (stays put, keeps its id, archived in n8n, sync skips it; remove the tag → back to default).
  Same vocabulary as the NC file system tags — but on the NC side the app keeps the file tag
  **authoritative** (always matches the mode metadata), while on the n8n side they're hand-set
  overrides it only *reads*. (`features/reserved-tags.feature`.)
- **Re-mode (sync ⇄ link) on a managed file**, identity (`n8n_id`) preserved; sync→link collapses
  to the pointer, link→sync pulls the full JSON. Triggered three ways: a Files **context-menu
  toggle**, a manual **retag**, or an **n8n-side tag** applied on the next pull. **Mutual
  exclusivity** is enforced — exactly one of `n8n:sync`/`n8n:link` per file; adding the second
  by hand resolves to the just-added one and strips the other. (`features/mode-change.feature`.)

#### 14.2c Phase 1 HANDOFF (in progress — branch `refactor/mode-model-collapse`)

> **For the agent taking over.** Phase 1 (the model collapse) is **partially done and
> committed** to branch `refactor/mode-model-collapse` (one WIP commit, pushed). The lib code
> compiles (every changed file `php -l` clean; a grep for `->writeback|WRITEBACK|MODE_REFERENCE|
> KEY_WRITEBACK|isSyncTwoWay` over `lib/` is empty). **CI will be RED until the integration
> FeatureContext is updated (see TODO h).** Do NOT merge until green.

**DONE (committed):**
- `Mapping` — `mode ∈ {sync, link}` (consts `MODE_SYNC`/`MODE_LINK`); `writeback` property +
  consts removed; `fromArray` back-compat (`reference`→`link`; `sync`+any writeback incl. old
  `backup`→`sync`); validation `mode ∈ {sync,link}`; `toArray` drops `writeback`.
- `tests/unit/Service/MappingTest.php` — NEW, covers the above (the only test added so far).
- `MappingService` — `update()` no longer passes `writeback`; `list()` legacy markers now fire
  on `mode==='reference'` or a stray `writeback` key (lazy re-persist on read → this IS the
  mappings-config migration; see TODO e).
- `WorkflowMetadata` — dropped `KEY_WRITEBACK`; `KEY_MODE` now INDEXED; `link`⇄`reference` wire
  translation isolated in `toWire()`/`fromWire()` (THE one place `reference` exists); added
  `MODE_UNMAPPED`/`MODE_IGNORED` consts.
- `OwnershipTags` — dropped `TAG_BACKUP`; `tagFor(string $mode)` single-arg; `apply(int,string)`
  single-arg; `n8n:backup`/`n8n:reference` are LEGACY (stripped on re-tag).
- `DeleteService` — `softDelete/hardDelete/restore` dropped `$writeback`; rule is now
  `mode===sync` → archive/delete/unarchive, `link` → untag/retag. Removed `isSyncTwoWay()`.
- Callers updated: `DeleteToN8nListener`, `RestoreFromTrashListener` (drop writeback reads).
- Push/name gates collapsed to `mode===sync`: `NameSyncListener`, `NodeWrittenListener`,
  `ReconcileNameJob`.
- `CreateService` (stamp drops `KEY_WRITEBACK`, `apply(id,mode)`), `SyncService` (`pushAll` gate
  `mode===sync`; pull body `MODE_LINK`; metadata writes drop writeback; `tags->apply(id,mode)`),
  `StorageService` + `TeamFolderService` (`ensure()` dropped `$writeback` param; perms gate
  `mode===sync`).
- Admin UI JS `js/mapping-settings.js` — mode `<select>` is now Sync/Link only; `readCard`
  returns single `mode`; header comment fixed.

**TODO (to finish Phase 1):**
- ~~**a. `templates/mapping_settings.php`**~~ ✅ existing-mapping mode `<select>` now renders
  `sync`/`link` only (`$modeSel = ($m['mode'] === 'link') ? 'link' : 'sync'`); readonly/reference
  options dropped — matches the JS new-card select.
- ~~**b. `lib/Controller/MappingController.php`**~~ ✅ docstring `writeback?` removed (no logic change).
- ~~**c. `lib/Command/AddMapping.php`**~~ ✅ docstring example writeback removed.
- ~~**d. Settings wording**~~ ✅ "two-way file" → "sync file" in `WritebackSettings`/`WebhookSettings`/
  `AdminSettings` descriptions.
- ~~**e. Migration**~~ ✅ relies on the LAZY `MappingService::list()` re-persist (rewrites the
  cleaned `sync`/`link` config on the first admin-page load or sync). **No explicit RepairStep** —
  this is a single homelab instance with a handful of test mappings, not a high-stakes fleet
  migration; the few existing files get fixed with a throwaway `occ`/manual command. File-metadata
  bulk migration stays DEFERRED (harmless dangling `n8n_writeback` prop; files re-stamp on next sync).
- **f. Live pod migration** ☐ — still pending a deploy of this branch; the lazy
  `MappingService::list()` re-persist rewrites the `cloud/nextcloud` mappings (`sync+two-way`→`sync`)
  on first read (admin page / sync). No repair step (per the e. decision).
- ~~**g. Unit tests**~~ ✅ `OwnershipTagsTest` (pure `tagFor` + unknown-mode throws) and
  `DeleteServiceTest` (mock `N8nClient`: sync archive/delete/unarchive, link untag/retag, 404
  swallow / 5xx throw). Required adding **`dg/bypass-finals`** (mock the `final` `N8nClient`) +
  `tests/ocp-stubs.php` (nextcloud/ocp ships no autoload, so OCP base symbols don't resolve
  standalone) wired into `tests/bootstrap.php`. **Committed a root `composer.lock`** (was missing —
  CI now reproducible). 45 unit tests green in the pod; cs:check clean.
- ~~**h. ⚠️ Integration `FeatureContext.php`**~~ ✅ `modeToModel()` now returns a single `sync|link`
  string; `add-mapping` JSON + assertions drop `writeback`; the old `…NoWriteback`/`…WritesBack`
  steps replaced by a single `…WithAnUnknownMode` step. `admin-mapping.feature` reject scenario
  un-`@todo`'d. (`file-type.feature` stays whole-`@todo` — its `unmapped`/`ignored` examples need
  Phase 2 motion states.)
- ~~**i. CHANGELOG**~~ ✅ `[Unreleased]` Changed + Tests entries for the model collapse.
- **j. Open the PR, verify unit + integration green, then it's mergeable.** ☐ — Psalm runs in CI
  (pod Psalm is unstable per §12.1); unit + cs validated in the pod.

> **Status (2026-06-22, handoff resumed):** Phase 1 is **code-complete**. All of a–e, g–i done;
> only **f** (live-pod deploy) and **j** (open PR / confirm CI green incl. Psalm + the live Behat
> suite) remain. Phase 2 (motion) is unchanged below.

#### 14.2d Phase-1 lessons learned (don't relearn these)

Hard-won bits from finishing the model collapse that aren't captured elsewhere:

- **`nextcloud/ocp` ships NO composer autoload block.** Its public API is bare source; after a
  clean install, `interface_exists(OCP\IConfig::class)` is **false**. Pure-logic unit tests
  (FilenameCodec, Mapping) never notice, but the moment a test loads a class that *references* an
  OCP base symbol — e.g. `Application extends OCP\AppFramework\App` (pulled in only for its
  `APP_ID` constant used in log context) — the class won't declare. Fix: a tiny declaration-only
  `tests/ocp-stubs.php` (App + the three IBootstrap/IRegistrationContext/IBootContext shims),
  `require`d from `tests/bootstrap.php`. Don't try to make ocp autoload — it isn't meant to.
- **Mocking a `final` class needs `dg/bypass-finals`.** The §12.1 code-scanning paydown made most
  services `final`. PHPUnit refuses to mock a final class, so `createMock(N8nClient::class)` throws
  until `\DG\BypassFinals::enable()` runs in the bootstrap (it strips `final` as classes autoload).
  This is the standard NC-ecosystem approach; it's a dev-only dep.
- **A root `composer.lock` was never committed.** Cause matches the suspicion: PHP work happens
  *in the pod* (copy app in, run there) and the generated lock was never copied back. Generated it
  with `composer update --no-install` in the pod and committed it — CI's `composer install` is now
  reproducible. (The integration suite already had its own committed lock; the root one was the gap.)
- **`expectNotToPerformAssertions()` + a configured mock = a PHPUnit *notice*** ("test is not
  expected to perform assertions but does"). Non-fatal (exit 0), but `failOnRisky` is on, so prefer
  an explicit `->expects(self::once())` on the mock over the no-assertions marker. Clears the notice.
- **The pod is the right PHP for `php -l` + PHPUnit + php-cs-fixer, but NOT Psalm** (§12.1: it hangs
  on analysis even idle). Validate cs + unit in the pod; leave Psalm to CI where it runs in ~seconds.

#### 14.3 Attack (two PRs)

> **Guiding principle for the build.** Cover the **main use cases, main flows, and
> most-likely scenarios** well — and *don't get caught in the weeds* on hard-to-test edge
> cases. The fragile/awkward ones (e.g. trashbin-purge propagation, n8n-unreachable abort, the
> move decision-cases a–d) get **stub code with a clear `// TODO: …` message** to fill in when
> ready, and their scenarios stay `@todo` in `features/`. Thorough specs ≠ thorough
> implementation on day one: the specs capture the full target so nothing is forgotten; the
> code lands the high-value paths first and leaves honest, labelled stubs for the rest.

- **Phase 1 — model collapse + migration.** `Mapping`/`MappingService` (single `mode`; legacy
  `{mode,writeback}`/`reference`/`backup` back-compat), `WorkflowMetadata` (drop `KEY_WRITEBACK`,
  index `KEY_MODE`, link↔reference translation), `OwnershipTags` (drop `n8n:backup`), every
  `(mode,writeback)` check → `mode`, admin UI + occ + controllers, a migration `RepairStep`
  (rewrite mappings config + re-stamp files), **+ run the migration on the live `cloud/nextcloud`
  pod**, and update `FeatureContext::modeToModel` so the model-only `@todo` specs flip live. Bump
  version. Clean, shippable.
- **Phase 2 — motion.** Move-out/in (archive/restore), merge-on-collision, copy-strips, the
  manual per-mapping sync + within-mapping prune. Flip each `@todo` scenario live as code lands
  (the create/rename/delete rhythm from §5.3).

Verify: re-grep for `writeback`/`backup` after Phase 1; live-smoke that n8n `unarchive` truly
restores a workflow our move-out archived (the restore path is load-bearing).

#### 14.4 Phase-2 motion — move (shipped 2026-06-22)

First slice of Phase 2: the **move** lifecycle (the `unmapped` mode + its archive/restore),
the highest-value motion path. Built to the §14.3 guiding principle — main flows live, fragile
edges stubbed `@todo`.

**What shipped (live + tested):**
- **`MotionService`** (new) — `moveOut` (archive in n8n + re-stamp `mode=unmapped`, mapping
  cleared, id/versionId/JSON kept) and `moveIn` (unarchive the SAME workflow + re-stamp
  `mode=sync` in the target mapping; **create-fallback** if the workflow was hard-deleted in
  n8n → 404). 404-on-archive is idempotent success; other errors bubble.
- **`MotionListener`** (new, on `NodeRenamedEvent`) — applies the consequence after an allowed
  move: sync-out-of-mapping → `moveOut`; unmapped-into-mapping → `moveIn`. Bails on untracked
  files (those stay with `CreateInN8nListener`'s create-on-land) and on within-mapping /
  unmapped→unmapped relocations. Failures logged + swallowed (the NC move already happened).
- **`MoveGuardListener`** (evolved from the old hard "can't leave the folder" block) — now on
  `BeforeNodeRenamedEvent` it *allows* a sync move-out, *blocks* a link move-out (only a
  pointer, nothing to keep), and *blocks* a direct mapping→mapping move (decision-cases a/b
  undesigned — eject-to-unmapped-first is the supported path).
- **`OwnershipTags`** — added `n8n:unmapped`; `tagFor('unmapped')` now returns it (was: throw).
- **`move.feature`** — flipped live: within-mapping move/rename, sync move-out→unmapped+archive,
  unmapped move-in→restore, link move-out refused, unmapped relocation no-op. Still `@todo`:
  hard-deleted restore-fallback (needs the "workflow gone in n8n" harness), merge-on-collision
  (needs a metadata-by-id lookup — `MotionService::moveIn` carries the TODO), brand-new move-in
  create, and decision-cases a–d.
- **Gherkin DRY** — one canonical `the app is connected to n8n` Background step (enable + URL +
  REST API + key) replaces the verbose three-line ritual everywhere except
  `admin-connection.feature` (which *is* the connection-flow test). Validated with `behat
  --dry-run`: every live step resolves, zero undefined.
- **Tests** — `MotionServiceTest` (6 cases: archive+stamp, 404-swallow, 500-rethrow, restore+
  stamp, create-fallback, 500-rethrow), `OwnershipTagsTest` updated for `unmapped`. 51 unit
  tests green in the pod; cs clean. (Pod can't run the live Behat suite — **integration is
  CI-only**; no local throwaway stack.)

**Lessons (don't relearn):**
- **Parenthesised step text needs the regex-form annotation.** `When I move (rename) the file`
  won't match a turnip `#[When]`/`@When` pattern — Behat reads `(rename)` as a capture group
  (so it matches the literal word `rename`, not `(rename)`). The existing `the workflow is
  archived \(hidden, preserved\) in n8n` works *because* it uses the `/^…\(…\)…$/` regex form.
  Cheapest fix: reword the Gherkin to drop the parens (and reuse an existing step where one
  already says it).
- **`behat --dry-run` is a free step-coverage check** — it loads the context and matches every
  non-`@todo` step to a definition without touching NC/n8n, so it runs fine in the app pod even
  though the full suite can't. Catches unmatched/typo'd steps before CI.
- **A misplaced `@todo` silently un-skips a scenario.** Dry-run reported "1 undefined" because a
  scenario's `@todo` tag went missing in an edit; the spec-only steps then counted as
  undefined. Always re-run the dry-run after editing tags.

Still pending for the wider Phase 2: copy-strips (`copy.feature`), manual per-mapping
sync/prune (`reconcile.feature`), `ignored` mode + reserved tags, mode-change toggle, and the
decision-cases a–d. The Phase-1 live-pod migration (§14.2c item f) is also still open.

#### 14.5 Phase-2 motion — copy (shipped 2026-06-22)

The second motion slice, and the deliberate mirror of §14.4's move: where a **move** is the
SAME workflow relocating, a **copy** is ALWAYS a brand-new instance. Nextcloud splits the two at
the event layer — a copy fires `NodeCopiedEvent`, a move fires `NodeRenamedEvent` — which is the
whole reason we can treat them oppositely from one place each.

**What shipped (live + tested):**
- **`CopyService`** (new) — `onCopy(File)`: (1) **strip identity** — wipe the copy's managed
  metadata (`WorkflowMetadata::clear` → `IFilesMetadataManager::deleteMetadata`) and ownership
  tags (`OwnershipTags::clear`), wrapped in the `SyncGuard`; (2) **register if mapped** — if the
  copy landed in a mapping, `CreateService::createForFile` mints a brand-new workflow (it builds
  the create body from name/nodes/connections/settings only — it never reads an `id` out of the
  JSON, so a copy can't reuse the original's id even in principle). A copy outside any mapping is
  left a plain, untracked file.
- **`CopyListener`** (new, on `NodeCopiedEvent`) — the thin event adapter: guard/`.n8n.json`
  bail checks, then `CopyService::onCopy($event->getTarget())`. Failures logged + swallowed.
- **`WorkflowMetadata::clear` / `OwnershipTags::clear`** (new) — the wipe primitives. `clear` is
  idempotent (NC's `deleteMetadata` no-ops on a file with no record; tag-unassign is guarded by
  `haveTag`).
- **`copy.feature`** — flipped fully live (all four scenarios): copy-within-mapping → new
  workflow; copy-outside → plain file, nothing created; copy-of-unmapped-outside → stripped,
  original keeps its id; copy-of-unmapped-into-mapping → new workflow, original's (archived)
  workflow untouched. Harness gained a `davCopy` (WebDAV `COPY`) helper + the copy step defs,
  tracking the copy as a second workflow id (`copyWorkflowId`) so "two distinct workflows" and
  "original unchanged" are real assertions, and registering it for teardown.
- **Tests** — `CopyServiceTest` (2 cases: strip+create in a mapping, strip-only outside). 53
  unit tests green in the pod; cs clean; `behat --dry-run` 25 live scenarios, 0 undefined.

**Design note — why a strip at all if NC doesn't copy metadata?** Confirmed against the live
pod: core's FilesMetadata does **not** listen to `NodeCopiedEvent`, and system tags are
object-mapped by fileid, so a copy already lands with a fresh, empty identity. The explicit strip
is therefore belt-and-suspenders — but cheap, idempotent, and it turns "a copy starts clean" from
*an accident of core internals we don't control* into *a guarantee this app makes*. Worth it: it's
the one place the saga singled out as "the single safest point to strip metadata," and if core
ever does propagate metadata on copy, this is already correct.

**Lessons (don't relearn):**
- **`NodeCopiedEvent` lives in `OCP\Files\Events\Node` and extends `AbstractNodesEvent`** —
  `getSource()` / `getTarget()` (two Nodes), not the single-node `getNode()` of
  `NodeWrittenEvent`. The target is the new copy.
- **A copy does NOT fire `NodeWrittenEvent`** — so create-on-land (which listens on
  Written + Renamed) never sees a copy. That gap is exactly why copy needs its own listener; it
  isn't redundant with `CreateInN8nListener`.

Still pending for the wider Phase 2: manual per-mapping sync/prune (`reconcile.feature`),
`ignored` mode + reserved tags, mode-change toggle, merge-on-collision, and decision-cases a–d.
The Phase-1 live-pod migration (§14.2c item f) is also still open.

#### 14.6 Phase-2 — DOUBLE FEATURE (reconcile + mode-change) — SHIPPED (PR #31, 2026-06-23)

> **Two agents, one PR.** This slice is built by **two agents working in parallel** on a single
> branch — `feat/reconcile-and-mode-change` — that lands as **one "double feature" PR**. The two
> features are deliberately chosen to be *independent* (disjoint `lib/` files, one new
> `features/*.feature` each, one new `bootstrap/Steps/*Steps` trait each), so the two agents
> rarely touch the same file. The per-concern Behat trait split (§5.3 refactor) is what makes
> this safe: a feature now = its own `*Steps` trait + one `use` line in `FeatureContext`.

**Who owns git (read this first).**
- **Copilot owns ALL git operations** — branch, `add`, `commit`, `push`, the PR, CI watching,
  and merge-readiness. **Claude has no git access this round.** Claude writes/edits files only;
  when a logical unit is ready, Claude tells Copilot "ready to commit X" and Copilot stages +
  commits it (crediting Claude in the commit body). Claude must **not** run `git`.
- **Copilot serializes the shared files.** The handful of files both features touch are edited
  **only by Copilot**, on request, so there are no mid-air collisions:
  - `tests/integration/bootstrap/FeatureContext.php` — the two new `use …Steps;` lines.
  - `lib/AppInfo/Application.php` — event/listener + service registrations for both features.
  - `CHANGELOG.md` `[Unreleased]` — one entry per feature.
  - `OwnershipTags` / `SyncService` / `WorkflowMetadata` — if **both** need a new method here,
    Copilot adds it once and both call it. Prefer adding to your *own* new service instead.

**Assignment A — `mode-change.feature` → CLAUDE.** The sync ⇄ link re-mode transition on a
managed file, identity (`n8n_id`) preserved. Mutual exclusivity: exactly one of
`n8n:sync`/`n8n:link` per file; manually adding the second resolves to the just-added one and
strips the other. Transition rewrites the file: **sync→link** collapses content to the pointer
(id/name/URL) and stops pushing; **link→sync** pulls the full JSON down and resumes two-way.
Triggered three ways (Files context-menu toggle, manual system-tag change, n8n-side override tag
applied on next pull). Build to the §14.3 principle — land the main paths live, leave honest
`// TODO:` stubs + `@todo` on the fragile ones.
- *New files (Claude's own):* `lib/Service/ModeChangeService.php` (the transition engine:
  re-stamp `KEY_MODE`, enforce tag mutual-exclusivity via `OwnershipTags`, collapse-or-pull the
  body via `SyncService`), a tag-change listener (`OCP\SystemTag` events) e.g.
  `lib/Listener/ModeTagListener.php`, the Files context-menu front-end action, a unit test
  `tests/unit/Service/ModeChangeServiceTest.php`, and a new step trait
  `tests/integration/bootstrap/Steps/ModeChangeSteps.php`.
- *Flip live in `features/mode-change.feature`:* the Files context-menu toggle, the manual
  second-tag-resolves, and the two retag (sync→link / link→sync) scenarios. The two **from-n8n
  override-tag** scenarios may stay `@todo` if the pull-side override plumbing isn't ready —
  label them honestly.
- *Hand off to Copilot for commit:* the `use ModeChangeSteps;` line in `FeatureContext`, the
  `Application.php` registrations, and the `[Unreleased]` CHANGELOG line.

**Assignment B — `reconcile.feature` → COPILOT.** The two manual, **mapping-scoped** sync
controls: **Sync from n8n** (pull the mapping's tagged workflows into its folder, update in place
matched by id, prune a file whose workflow lost the tag) and **Sync to n8n** (push the mapping's
sync files up). Both **fully ignore `unmapped` files** — they're outside any mapping's scope. No
merge logic here (merge is move-time, §14.4).
- *New files (Copilot's own):* `lib/Service/ReconcileService.php` (`syncFrom(mapping)` /
  `syncTo(mapping)`, leaning on existing `SyncService` pull/push + `MappingService`), an occ
  command `lib/Command/Reconcile.php` (and/or a controller endpoint for the admin buttons), a
  unit test `tests/unit/Service/ReconcileServiceTest.php`, and a new step trait
  `tests/integration/bootstrap/Steps/ReconcileSteps.php`.
- *Flip live in `features/reconcile.feature`:* both scenarios (pull-with-prune, push), with the
  "unmapped file untouched" assertions. Prune + id-match are the load-bearing bits.

**Coordination rhythm.** Each agent: write code → `php -l` + PHPUnit + `php-cs-fixer` **in the
pod** (`nextcloud-dbb454476-dvxwz`, ns `cloud`, container `nextcloud`) → `behat --dry-run` for
step coverage → hand the shared-file edits to Copilot → Copilot commits. Integration is **CI-only**
(no local stack); Psalm is **CI-only** (pod Psalm hangs, §12.1). Copilot opens the PR as a
**draft**, flips it to ready once both features are green, and watches `gh pr checks --watch`.

**Assignment A status — CLAUDE → ready for Copilot to commit + wire (no git on my side).**
New files written + `php -l`-clean (unit logic is solid; could not run PHPUnit/Behat locally —
no PHP/vendor on the Claude side, so CI is the verifier as agreed):
- `lib/Service/ModeChangeService.php` — the engine. `changeTo(File, 'sync'|'link')`: fetch the
  workflow, rewrite the body (sync→full JSON / link→pointer), re-stamp `KEY_MODE`+versionId+hash,
  `OwnershipTags::apply()` (strips the other tag → exclusivity). All guarded. id preserved.
- `tests/unit/Service/ModeChangeServiceTest.php` — 6 tests (unmanaged no-op, bad target,
  already-in-target re-asserts tag only, sync→link collapses, link→sync pulls, n8n-fetch-failure
  leaves file untouched). Mocks `N8nClient`/`WorkflowMetadata`/`OwnershipTags`, stubs guard/config.
- `lib/Listener/ModeTagListener.php` — listens on `OCP\SystemTag\TagAssignedEvent`; when
  `n8n:sync`/`n8n:link` is assigned to a managed `*.n8n.json`, routes to `changeTo()`. Bails under
  `SyncGuard::active()` (our own `apply()` re-assigns tags — no recursion).
- `src/files.js` — registered a "Toggle n8n mode (sync/link)" action; exec is a **`// TODO` stub**
  (points users at the Tags sidebar, which fires the same event the listener handles — so the
  mechanism already works; only the one-click shortcut is stubbed). Also `registerDavProperty('nc:metadata-n8n_mode')`.
- `tests/integration/bootstrap/Steps/ModeChangeSteps.php` — drafted. Self-contained systemtags
  DAV helper (resolve/create tag id → PUT `systemtags-relations/files/<id>/<tagId>`), plus the
  When/Then steps. **Note the wire value:** `davReadMetadata('n8n_mode')` returns `reference` for
  link (not `link`) — my `theFileTransitionsToMode` accounts for it; reuse care if mixing with
  `MoveSteps::theFilesModeBecomes`, which compares the raw word (fine for sync/unmapped only).

**Copilot, please:**
1. `tests/integration/bootstrap/FeatureContext.php` — add the import + `use ModeChangeSteps;`.
2. `lib/AppInfo/Application.php` — `registerEventListener(TagAssignedEvent::class, ModeTagListener::class);`
   (`ModeChangeService` is auto-wired — no explicit registration needed).
3. `CHANGELOG.md` `[Unreleased]` — a mode-change line.
4. **Flip `features/mode-change.feature` scenarios** — recommend you drive this against CI (you own
   the watch loop): start with the two robust ones (**second-tag-resolves**, **sync→link retag**);
   keep **toggle** (front-end stub), **link→sync** (needs a link-file precondition — set up by
   assigning `n8n:link` first), and the two **n8n-override** scenarios `@todo`.
5. Commit my files crediting Claude.

**Claude's review of Assignment B (reconcile) — 2026-06, work-in-progress, looks strong.**
Reviewed `lib/Command/Reconcile.php` + the `SyncService` pull-prune / `pushOne` extraction +
`tests/unit/Service/SyncServiceTest.php`. Clean, well-documented; `collectManaged` correctly
scopes by `n8n_mapping` id (so a prune never touches another mapping's files), prune runs inside
the `SyncGuard`, and `pushOne` no-ops for `link` mappings. The unit test covers the load-bearing
bits (prune the tag-loser, keep the rest; push skips link/plain/unstamped). Nice.

Two **future** watch-outs (NOT current bugs — both modes are Phase-2-later, flagging so they're
not forgotten when they land):
- **`pruneStale` will delete `ignored` files.** An `ignored` file stays *in* the mapped folder,
  keeps its `n8n_mapping` id, and its workflow won't carry the tag on a pull → `collectManaged`
  indexes it → it's not in `seenIds` → **pruned**. When `ignored` mode lands, `collectManaged`
  (or `pruneStale`) must skip `n8n_mode === ignored`. (Same care if an `unmapped` file ever sits
  in-folder, though by definition it shouldn't.)
- **`pushOne` pushes by *mapping* mode, not per-file mode.** Once reserved-tag overrides exist
  (a `link` file inside a `sync` mapping), `pushOne` would push that link file — it only checks
  the file has an `n8n_id` and the mapping is sync. Gate each file on its own `mode === sync`
  when overrides land.

Minor: the command is `n8n_sync:sync pull|push` (not a `reconcile` verb) — fine, matches the
"Sync from/to n8n" buttons; just keep `features/reconcile.feature` + README wording consistent
with that name. `reconcile.feature` is still `@todo` (expected, mid-work).

**Shared registrations still to do:** `Application.php` needs the `ModeTagListener` on
`TagAssignedEvent` (mode-change) — Reconcile's command is **already** in `info.xml <commands>`
(✓, no Application.php change for it). `FeatureContext` needs both `use ModeChangeSteps;` and
`use ReconcileSteps;`. CHANGELOG `[Unreleased]` gets one line per feature.

**Assignment B status — COPILOT → committed to PR #31 (reconcile).** Built by **extending
`SyncService`** rather than a separate `ReconcileService`: that service already owns
`dispatch`/`pullOne`/`pushAll`, so reconcile is just `pushOne(Mapping)` + a `pruneStale()` pass
inside `pullOne` (under the SyncGuard it already holds, so prune never mirror-deletes to n8n).
The admin/test surface is `lib/Command/Reconcile.php` → `occ n8n_sync:sync <pull|push> --mapping`.
`tests/unit/Service/SyncServiceTest.php` (4 tests, stub/mock split per §14.2d — notice-clean) and
`features/reconcile.feature` (both scenarios live, `ReconcileSteps` trait) cover it.
Claude's two watch-outs are **acknowledged and deferred** (neither is a current bug — both modes
are Phase-2-later): when `ignored` mode lands, `collectManaged`/`pruneStale` must skip
`n8n_mode === ignored` so an in-folder ignored file isn't pruned; and when reserved-tag overrides
land (a `link` file inside a `sync` mapping), `pushOne` must gate each file on its own
`mode === sync`, not just the mapping mode. Flagged here so they aren't forgotten.

**Wiring done (Copilot):** `Application.php` — `ModeTagListener` registered on `TagAssignedEvent`
(+ import); `FeatureContext` — `use ModeChangeSteps;` + `use ReconcileSteps;` (+ imports);
`mode-change.feature` — two scenarios live (second-tag-resolves, sync→link retag), the other four
left `@todo` (toggle front-end stub, link→sync precondition, two n8n-override); CHANGELOG one line
per feature. Both Claude's mode-change files committed crediting Claude.



#### 14.7 The audition ledger — what's live, what's still on stage

This is the cap of the chapter: a running tally of the spec backlog. The specs (every
`features/*.feature` + the README) were written first as the end state; a scenario goes **live**
(its `@todo` removed) only once the code behind it lands and the integration suite proves it on
real NC + n8n. The chapter is "done" when this ledger is all-green to Kelly's satisfaction.

| Feature file | Live | Still `@todo` | Notes |
|---|---|---|---|
| `lifecycle` | ✅ all | — | install/enable/disable |
| `admin-connection` | ✅ all | — | URL + key + REST API, defeated token |
| `admin-mapping` | ✅ all | — | add/list mappings |
| `create-workflow` | ✅ all | — | author-in-NC → live-in-n8n |
| `rename` | ✅ all | — | three-way name sync |
| `copy` | ✅ all | — | always a new instance (§14.5) |
| `reconcile` | ✅ all | — | manual per-mapping pull/push + prune (§14.6) |
| `move` | ◑ 6 of 9 | hard-deleted restore-fallback, merge-on-collision, brand-new move-in create | §14.4 shipped the core |
| `mode-change` | ◑ 3 of 6 | link→sync retag, 2× n8n-override | §14.6; real toggle landed PR #33 |
| `delete` | ◑ 4 of 9 | purge-sync, unmapped trash/purge/restore, abort-if-unreachable | wiring exists; assertions pending |
| `reserved-tags` | ✅ 7 of 8 | "remove n8n:ignore → default" (un-ignore listener unbuilt) | §14.6/§14.10 — landed PR #32 |
| `open-with` | ◑ sync+unmapped+ignored | `link` (Vitest-covered) | §14.8/§14.10 — ignored flipped PR #33 |
| `file-type` | ◑ 5 of 6 | REPORT-query, `link` mode row | §14.9 — ignored flipped PR #33 |
| `mapping-membership` | ✅ all 3 | — | nearest-enclosing nested resolution — **landed PR #33** (§14.13) |

**Two watch-outs carried forward from §14.6** (deferred, not bugs — they bite only when the modes
below land): `pruneStale`/`collectManaged` must skip `n8n_mode === ignored`, and `pushOne` must
gate each file on its **own** `mode === sync` (not the mapping's) once per-workflow overrides exist.
Both are cleared by the next double feature.

#### 14.8 Next double feature — reserved-tags / `ignored` mode (Copilot) + open-with (Claude)

Same playbook as §14.6: **two agents, one "double feature" PR**, two *independent* slices.
**Copilot owns all git** (branch/commit/push/PR/CI/merge); **Claude writes files only** and hands
shared-file edits to Copilot, who serializes them (`FeatureContext` `use` lines,
`lib/AppInfo/Application.php` registrations, `CHANGELOG.md [Unreleased]`, `src/files.js`). Each
agent validates in the pod (`nextcloud-…`, ns `cloud`): `php -l` + PHPUnit + `php-cs-fixer` +
`behat --dry-run`; integration + Psalm are **CI-only**. PR opens as a draft, flips to ready when
both slices are green.

These two are chosen to be disjoint: Copilot's is **backend pull-resolution**, Claude's is
**front-end openers** — separate `lib/`/`src/` files, one new `features/*.feature` each, one new
`bootstrap/Steps/*Steps` trait each. The only shared front-end file is `src/files.js`, which
**Claude owns this round** (the openers); Copilot stays backend.

**Assignment A — `open-with.feature` → CLAUDE.** The openers offered on a managed workflow file
and which one is the **default click**, driven by the file's **mode** (not its type):
- *Open in n8n* — jumps to the live workflow; meaningful only for `sync`/`link`, **hidden** for
  `unmapped`/`ignored` (nothing live to open).
- *Open with text editor* — edits the raw JSON; **always** available, and the **default** for
  `unmapped`/`ignored`.
- Default click: `sync`/`link` → Open in n8n; `unmapped`/`ignored` → text editor.
- *New files (Claude's own):* the file-action openers in `src/files.js` (register both actions +
  the mode-driven default; reuse the `nc:metadata-n8n_mode` DAV property already registered for
  mode-change), plus a step trait `tests/integration/bootstrap/Steps/OpenWithSteps.php`. Front-end
  unit coverage via Vitest if practical.
- *Flip live in `features/open-with.feature`:* the four scenarios as the actions land; leave any
  that need a not-yet-built mode (`ignored`) honest with `@todo` until Copilot's slice lands, then
  flip in the same PR.
- *Hand off to Copilot for commit:* the `use OpenWithSteps;` line in `FeatureContext`, any
  `Application.php` registration, and the `[Unreleased]` CHANGELOG line.

**Assignment B — `reserved-tags.feature` + the `ignored` mode → COPILOT.** The optional,
per-workflow, **n8n-side** reserved tags read at pull time (the app only ever *reads* these,
never writes them onto workflows): `n8n:sync` / `n8n:link` override the mapping default for one
workflow; `n8n:ignore` excludes one — either *never pulled* (no file) or, for a file already in a
mapped folder, the new **`ignored`** mode (stays put, keeps its id, archived in n8n, the sync
**skips** it; removing the tag returns it to the mapping default).
- *New files (Copilot's own):* a per-workflow override resolver (e.g.
  `lib/Service/ReservedTagResolver.php`, or fold into the pull path) that reads a workflow's tags
  and yields the effective mode; a unit test; a step trait
  `tests/integration/bootstrap/Steps/ReservedTagsSteps.php`.
- *Shared backend Copilot edits (its own files, no Claude collision):* add `ignored` to
  `WorkflowMetadata` (`KEY_MODE` value, indexed); add the `n8n:ignore` tag to `OwnershipTags`; and
  **clear the two §14.6 watch-outs** — `collectManaged`/`pruneStale` skip `n8n_mode === ignored`,
  and `pushOne` gates per-file `mode === sync`.
- *Flip live in `features/reserved-tags.feature`:* the mapping-default, `n8n:link`/`n8n:sync`
  per-workflow override, `n8n:ignore` never-pulled, in-folder `ignored`, and no-prefix scenarios.
- *Bonus once `ignored` exists:* the `file-type.feature` "mode dav value = ignored" row and the
  `open-with` `ignored` cases become flippable — coordinate so they land in the same PR.

**Why this pairing.** `ignored` is the last missing mode value, and it's squarely in Copilot's
reconcile/pull territory (the pull is what must honour overrides and skip ignored files) — so it
also retires the two deferred watch-outs. `open-with` is pure presentation keyed off the mode
metadata that already exists, continuous with Claude's mode-change front-end work, and touches no
backend pull code — maximal independence for a clean double-feature PR.

**Assignment A status — CLAUDE → ready for Copilot to commit + wire (no git on my side).**
Files written + validated as far as the Claude side allows (Vitest + eslint + vite build local;
`php -l` in the pod; **no behat/psalm per Kelly — CI verifies the integration suite**):
- `src/files-helpers.js` — three new pure helpers: `getN8nMode(node)` (reads the `n8n_mode` DAV
  attr in all three shapes, wire `reference`→`link`, '' when absent), `canOpenInN8n(mode)`
  (false only for `unmapped`/`ignored`; permissive on the first-load race), `defaultOpener(mode)`.
- `src/files.js` — "Open in n8n" `enabled` now gated on `canOpenInN8n(getN8nMode(node))` (hidden
  for unmapped/ignored); "Edit as text" renamed to **"Open with text editor"** and made
  `DefaultType.DEFAULT` at `order -49` so n8n (`-50`) wins for sync/link but the text editor
  becomes the default click when "Open in n8n" is disabled. The opener set follows the MODE.
- `tests/js/files-helpers.test.js` — +12 Vitest cases (27 total green). This is the **real**
  verifier of the opener decision logic for every mode incl. `link` (Behat can't click).
- `tests/integration/bootstrap/Steps/OpenWithSteps.php` (new) — asserts the server-observable
  backing the FE reads: the `n8n_mode` DAV value, the live-vs-archived workflow in n8n, and
  raw-JSON readability over DAV. Owns a **distinct** `a managed workflow file in :mode mode` step
  (RenameSteps already owns `a managed :mode workflow file` and only makes sync/link). The
  `ignored` arrange branch is an honest `throw` stub until Copilot's slice lands.
- `features/open-with.feature` — whole-file `@todo` removed; **sync + unmapped live**;
  `link` rows `@todo` by design (Vitest is their verifier — integration "clicking" is an illusion);
  `ignored` rows `@todo` until Copilot's `ignored` mode lands (then flip in this same PR).

**Copilot, please:**
1. `tests/integration/bootstrap/FeatureContext.php` — add the import + `use OpenWithSteps;`.
2. `CHANGELOG.md` `[Unreleased]` — one line, e.g.
   *"File openers now follow the workflow mode: Open in n8n for sync/link, text editor for
   unmapped/ignored (and Open in n8n hidden there)."*
3. **No `Application.php` change** — the `nc:metadata-n8n_mode` DAV property is already registered
   (from the §14.6 mode-change work) and there's no new listener.
4. Commit my files crediting Claude (the rebuilt `dist/n8n_sync-files.js` rides along if `dist` is
   tracked; CI rebuilds regardless).
5. When your `ignored` mode lands: flip the three `ignored` rows in `open-with.feature` and fill in
   the `OpenWithSteps::arrangeManagedFile` `ignored` branch (assign `n8n:ignore` to an in-folder
   file, then reconcile) — small follow-up, same PR.

### 14.9 Spec-vs-code audit (2026-06-23) + Claude's next pickup: `file-type`

A pass over the whole backlog — README "desired state" vs the `features/*.feature` `@todo` ledger
vs what the code actually does — surfaced a few things sharper than the ledger had them. Recorded
here so they're not relearned; most we **come back to later**, one Claude is **picking up now**.

**New / sharpened findings (the discrepancies worth keeping):**
- **Nested mappings are NOT implemented** (README "the nearest enclosing mapping wins"). The
  resolver `MappingService::resolveForPath` matches only the **single top-level segment after
  `files/`** (`preg_match('#/files/([^/]+)#')`) — a mapping on a sub-folder like `Flows/sub` can
  never win. `mapping-membership.feature` is rightly whole-`@todo`; the README overclaims. Real
  build work, not just a test gap. **Come back to.**
- **The "Toggle n8n mode" context-menu action is a stub** (README sells it as the "one-click easy
  path"). `src/files.js` just shows a toast pointing at the Tags sidebar; the real re-mode only
  happens via a hand-set tag. Doc-vs-code overstatement; the `mode-change.feature` toggle scenario
  is correctly `@todo`. **Come back to** (and it's squarely Claude's front-end lane).
- **Merge-on-collision (move-in) is documented as shipped** (README) but is `@todo` + a TODO in
  `MotionService::moveIn` (no metadata-by-id lookup yet). Matches §14.4; flagged as a doc lie.
- **`ignored` mode + reserved tags read as shipped** in the README (Modes table, §Ignored,
  §Reserved tags) but aren't built — `OwnershipTags` even says so in a comment. **In flight now
  (Copilot, §14.8 B).** Not new, but the README presenting them as done is worth noting.
- **Correction to the ledger framing:** delete's *abort-if-n8n-unreachable* (README) **is** coded —
  `DeleteToN8nListener` throws `AbortedEventException` on failure. Only the *scenario* is `@todo`
  (unasserted), so it's "implemented but unproven," not a gap.
- **`file-type` read-only + indexed metadata:** the code is actually all there —
  `WorkflowMetadata` registers the four `nc:metadata-*` props `EDIT_FORBIDDEN`, with `n8n_mode` +
  `n8n_mapping` indexed. It was just never *proven* over DAV. → that's the pickup.

**Claude → PICKING UP `file-type` on this same PR** (disjoint from Copilot's reserved-tags/`ignored`
pull work — this is pure DAV-metadata surface, no `lib/` change). Copilot is heads-down on its own
feature; this is the independent second slice.
- *New file (Claude's own):* `tests/integration/bootstrap/Steps/FileTypeSteps.php` — PROPFIND of
  the custom mimetype, PROPFIND exposing the four `nc:metadata-*` props, the per-mode `n8n_mode`
  DAV value, and a PROPPATCH-rejected (read-only) assertion. Reuses `OpenWithSteps::arrangeManagedFile`.
- *Flip live in `features/file-type.feature`:* mimetype/icon, PROPFIND-exposes-metadata, the
  `sync`+`unmapped` mode-value rows, and read-only PROPPATCH. **Left `@todo`:** the `link`+`ignored`
  mode rows (link integration is uncertain like §14.8; `ignored` is Copilot's) and the **REPORT
  search** scenario (the DAV-search plumbing for `nc:metadata-*` is unproven against the pod — flip
  once CI/manual confirms it).
- *Hand off to Copilot for commit:* the `use FileTypeSteps;` line in `FeatureContext`, and a
  `[Unreleased]` CHANGELOG line. No `Application.php` change (no new listener; mimetype is already
  registered by the `RegisterMimetype` migration).

#### 14.10 Double feature #2 — LANDED & GREEN (PR #32, 2026-06-23)

> **Three slices, one PR, all checks green.** `reserved-tags` + `ignored` mode (Copilot),
> `open-with` (Claude), and `file-type` (Claude) all landed on PR #32
> (`feat/reserved-tags-and-open-with`). After Copilot went heads-down, **Claude took over git +
> CI** and drove the branch to a fully green board (Integration 47/47, Psalm, PHP/JS quality,
> unit, build, PR Tasks). PR is a draft pending Kelly's review.

**The drive to green (what CI caught that the pod couldn't):**
- **Two trait method-name collisions** (`aManagedWorkflowFile`, `theWorkflowIsArchivedInN8n`) — two
  Step traits each redeclared a method another trait owned. A fatal PHP trait collision, *independent
  of the Behat step text* (which differed). Renamed the methods; annotations unchanged.
- **Two undefined reserved-tags steps** — the bare `n8n has a workflow tagged :tag` was never
  defined, and `subsequent pulls/pushes for :tag skip it` used a turnip annotation whose literal
  `/` is read as **word-alternation** (`pulls|pushes`) so it never matched the literal slash. Fixed
  the latter with a regex annotation (same class as the §14.4 parens lesson).
- **The real bug — `ignored` files re-pulled into duplicates.** An `ignored` file is excluded from
  `indexByN8nId` (so prune leaves it), but the pull still received its archived workflow from
  `iterateWorkflows` (it keeps the mapping tag) and, not finding it in the index, wrote a NEW
  collision-suffixed `sync` copy (`Mover (1).n8n.json`); the next push then failed with *"Cannot
  update an archived workflow."* Fix: surface ignored ids out of `collectManaged`/`indexByN8nId`
  and **skip them in the pull loop** — a locally-ignored file is now left strictly alone.
  (`pushOne` already skipped non-sync files, so the original was never the problem.) This retires
  the deferred §14.6 watch-out properly.

**Lessons (don't relearn):**
- **PHP trait collisions are by METHOD NAME, not step text.** Two `*Steps` traits with a same-named
  method fatal-error `FeatureContext` even when their `@Given/@When/@Then` patterns differ. When
  adding a step whose phrasing echoes an existing one, give the method a unique name.
- **A literal `/` in a turnip step annotation = word-alternation.** `pulls/pushes` matches `pulls`
  OR `pushes`, never the literal `pulls/pushes`. Use a regex annotation (escape the slash) or
  reword — same fix family as parenthesised steps (§14.4).
- **PHPUnit 12 masks Behat assertion failures.** A failing `PHPUnit\Assert` under Behat throws an
  opaque `Registry::get(): … null returned` TypeError that *replaces* the real message (the footgun
  `WebDavTrait::assertStatus` already documents). When a step fails inscrutably, convert its asserts
  to a plain `RuntimeException` that prints the observed state — that one change surfaced the entire
  root cause above. Worth doing project-wide for the load-bearing step assertions.
- **`php-cs-fixer` runs PHP-version-sensitive + `phpdoc_align`:** keep `@param` descriptions single
  line (wrapped descriptions get re-aligned), and single-quote format strings with no interpolation.

**Where Chapter 3 stands now — past the hump, not yet the finish.** The core lifecycle is whole and
every mode now *exists* (`sync`/`link`/`unmapped`/`ignored`). **19 `@todo` scenarios remain**, in
three buckets:
1. **Flip-now (modes already exist) — cheap, mostly Claude's front-end lane:** `open-with` 3×
   `ignored` rows + `file-type` `ignored` mode-value row (wire `arrangeManagedFile('ignored')` =
   put a file, tag `n8n:ignore`). The 2× `mode-change` **n8n-override** scenarios may also be
   flippable now that the reserved-tag resolver exists.
2. **Targeted builds:** the `mode-change` **toggle** Files action (currently a stub), the
   `reserved-tags` **un-ignore** listener (remove `n8n:ignore` → back to default), and some
   `delete` edges (purge-sync, unmapped trash/purge/restore — `unmapped` exists now so these are
   close; abort-if-unreachable is already coded, just needs its assertion).
3. **Genuine remaining feature work:** `mapping-membership` **nearest-enclosing nested mappings**
   (NOT built — `resolveForPath` matches only the top-level segment, §14.9) and the `move`
   **merge-on-collision** + restore-fallback + brand-new-move-in edges.

Next sensible double feature: **Claude flips the ignored/override `@todo`s** (open-with + file-type
+ mode-change overrides — pure flips, his lane) while **Copilot builds nested mappings** (the one
real backend gap). That clears the cheap wins and the largest unbuilt feature in one pass, leaving
only the `move`/`delete` edges and the toggle/un-ignore niceties before the ledger is all-green.

#### 14.11 Run to the finish — Copilot builds nested mappings, Claude clears the little wins

The ignored-mode flips landed on **PR #33** (open-with + file-type ignored rows live). What's left
splits cleanly into **one real backend build (Copilot)** and **a tail of small flips/niceties
(Claude)**. Same playbook: two agents, Copilot owns git + the shared files, Claude writes its own
files and hands off `use …Steps;` / `Application.php` / `CHANGELOG` edits.

##### COPILOT — `mapping-membership`: nearest-enclosing nested mappings (`mapping-membership.feature`)

The one genuinely unbuilt feature, and squarely backend. **Today `MappingService::resolveForPath`
matches only the single top-level segment after `/files/`** (`preg_match('#/files/([^/]+)#')`), so a
mapping on a *sub*-folder can never win — nested mappings are documented (README) but not real.

- *The gap:* resolution must walk the **full** NC path and pick the mapping whose folder is the
  **longest matching path prefix** (nearest enclosing), not just the mounted top-level name. This
  likely means mappings need to match on a **path**, not a bare folder name — check whether
  `add-mapping` / `Mapping::teamFolder` can express a sub-path, and whether Team Folders vs
  admin-subfolders change the resolution (Team Folders mount at top level; nested mappings are
  admin sub-folders).
- *Consistency:* `indexByN8nId`/`collectManaged` already filter by each file's own `n8n_mapping`
  for overlapping subtrees (§14.4) — make the resolver and that ownership filter agree so a file in
  `inner` is never pulled/pruned by `outer`.
- *Spec (3 scenarios, whole-`@todo`):* file-in-folder → that mapping; file outside any mapping →
  none (`untracked` if no id, `unmapped` if it carries one); nested → **inner wins**.
- *New files (Copilot's own):* the resolver change in `MappingService`, a unit test, and a
  `tests/integration/bootstrap/Steps/MappingMembershipSteps.php` trait. Flip
  `mapping-membership.feature` live.
- *Watch-out:* this is **not** a pure flip — it's the one item that needs design (path matching +
  how a sub-folder mapping is even declared). Treat per §14.3: land the nearest-enclosing main path,
  stub anything fragile with a labelled `// TODO` + keep its scenario `@todo`.

##### CLAUDE — the little wins (a checklist, roughly in order of cheapness)

Each is a flip or a small, isolated build; none touches the pull-resolution work above. Knock them
down as capacity allows, folding into the double-feature PR (or a quick solo PR for the markdown/test-only ones).

- [ ] **`mode-change` — 2× n8n-override scenarios** (`sync→link` / `link→sync` *from n8n*, override
  tag applied then pulled). The reserved-tag resolver now applies overrides at pull time; `writeWorkflow`
  re-modes an *existing* file to the effective mode — so these are likely **flippable now**. Wire the
  fixture (seed workflow, add `n8n:sync`/`n8n:link` n8n-tag, pull) and assert the file re-modes.
- [ ] **`mode-change` — `link→sync` retag (NC side)** — needs a link-file precondition (assign
  `n8n:link` first), then retag to `n8n:sync`; assert the full JSON is pulled back down.
- [ ] **`delete` — `unmapped` trash / purge / restore (3)** — `unmapped` exists now; arrange via a
  sync-file move-out (the §14.4 path), then trash/purge/restore and assert the n8n side (no-op /
  permanent-delete / no-op respectively).
- [ ] **`delete` — abort if n8n unreachable** — already **coded** (`DeleteToN8nListener` throws
  `AbortedEventException`); just needs its assertion (point the app at a dead n8n, attempt a delete,
  assert the file stays). The "bow on top."
- [ ] **`delete` — purge a sync file permanently deletes the workflow** — flip + assert the workflow
  is gone (not archived) in n8n.
- [ ] **`mode-change` — the Files **toggle** action** — replace the `src/files.js` stub with the real
  one-click toggle (resolve/create the opposite `n8n:sync`/`n8n:link` system tag, PUT the relation);
  retires the README doc-vs-code overstatement. Front-end, Claude's lane.
- [ ] **`reserved-tags` — un-ignore listener** — removing `n8n:ignore` returns the file to the
  mapping default (the one remaining `reserved-tags` `@todo`). Small backend listener on tag-removal.
- [ ] **`move` edges — merge-on-collision, hard-deleted restore-fallback, brand-new move-in** —
  backend motion (either agent); merge-on-collision also retires a README doc-vs-code lie (§14.9).
- [ ] **Leave `@todo` on purpose:** the `open-with`/`file-type` **`link`** rows (no create-on-land
  path; covered by `tests/js/files-helpers.test.js`) and `file-type`'s **REPORT-by-indexed-mode**
  query (DAV-search plumbing for `nc:metadata-*` unproven against the pod) — flip only if/when those
  are genuinely wired.

When this checklist and the nested-mapping build are green, the §14.7 ledger is all-green and
Chapter 3 — the audition — is done; the work turns to Chapter 4 (branding + app-store submission).

#### 14.12 HANDOFF — Claude passed out in the alley (PR #33 in flight, 2026-06-23)

> Mid-debug, Claude's coin-purse ran dry. The credits gave out all at once — the lights in his
> eyes dimmed, his knees buckled, and he slid down a damp brick wall into a Soho alleyway, snoring
> against a dumpster with a half-written diagnosis still clutched in his hand. He'll sleep it off
> for about an hour and wake up recharged. **Copilot: pick up exactly here.**

**Branch/PR state.** Everything is on `feat/ignored-mode-openers` → **PR #33** (we funnel all work
through this one PR now — no more direct-to-main). Latest commit `b1ba9cd`. On the branch and green
in CI: Claude's **ignored-mode flips** (open-with + file-type) and the real **Toggle n8n mode**
front-end action (`src/files.js` + `toggleTargetTag` in `files-helpers.js`, Vitest 30 green); and
Copilot's **nested-mappings** (`MappingService::resolveForPath` nearest-enclosing + `MappingMembershipSteps`).

**CI status: 52/54 integration.** The ONLY red is **`mapping-membership.feature`** scenarios 1 & 3
(scenario 2 "outside any mapping" passes). Both die in the **Given** (`aFolderMappedToTheN8nTag` →
`addMembershipMapping` → `occ n8n_sync:add-mapping`) with **exit 1, EMPTY output** — i.e. an
**uncaught throwable** (NOT the caught `InvalidArgumentException`, which would print a message);
production `display_errors` is off, so it only went to `nextcloud.log`. Claude already **unmasked**
that assertion (commit `b1ba9cd` → throws a `RuntimeException` with exit+output instead of the
opaque PHPUnit-12 `Registry::get()` TypeError).

**What Claude ruled OUT (don't re-chase):**
- *Not the custom `id`.* The step passes `'id' => 'mm-xxxx'`; `Mapping::fromArray` accepts it.
  Reproduced the EXACT command on the live pod (now branch code) → **exit 0, "Added mapping"**. Works.
- *Not `writeback`.* That was a stale-pod red herring (pod was pre-mode-collapse before the deploy).

**The live oracle is now real.** Per Kelly's go-ahead, Claude **deployed the branch to the live
`cloud/nextcloud` pod** (the long-pending §14.2c item-f migration): maintenance-mode → `tar` the
runtime dirs (`appinfo lib dist templates css js img config`) into the pod → `chown -R www-data` →
`occ upgrade --no-interaction` → maintenance-off. **n8n_sync is now 0.1.2, enabled, `needsDbUpgrade:
false`, no crash-loop.** So the pod is once again a valid branch oracle — `occ` + `nextcloud.log`
work for debugging (read the log as www-data: `…/data/nextcloud.log`).

**LEADING HYPOTHESIS for the 2 failures (Copilot, start here).** `mapping-membership.feature` is the
**only** feature with **no `Background`** — every other feature opens with `Given the app is
connected to n8n` (= installed+enabled + URL + API key) or at least `the app is installed and
enabled` / `the app is enabled`. Without it, the scenario's NC context is missing setup the add path
(or the create-on-land that follows) needs, and `add-mapping` throws uncaught. **First thing to try:
add `Background: Given the app is connected to n8n` to `mapping-membership.feature`** (mirror the
others), push, watch. If it still fails, read the unmasked `RuntimeException` message in the CI log
(now it shows exit+output) and/or reproduce on the pod **with the connection config cleared** to
match the no-Background state (then restore it — Kelly is trying the app out, so don't leave the
connection broken). The masked-error footgun is real and recurring → consider converting the
load-bearing step asserts to `RuntimeException` project-wide.

**Housekeeping:** Claude removed his throwaway `mm-test1234` mapping from the pod; the live instance
is tidy for Kelly to try out. The saga is markdown-only — fold it into PR #33 (don't open a new PR).

#### 14.13 RESOLVED — Copilot took the watch (the missing Background) (2026-06-23)

Picked up exactly where §14.12 left off. The leading hypothesis was right, and the **root cause is
now fully pinned**, not just patched:

- **Why it threw at all:** features run alphabetically, so **`lifecycle.feature` runs immediately
  before `mapping-membership.feature`** — and its last scenario, *"Disabling the app"*, leaves
  `n8n_sync` **disabled**. With no `Background` to re-enable it, `occ n8n_sync:add-mapping` is an
  **unregistered command** (the app owns it), so occ exits 1. That's the uncaught, empty-stdout
  exit-1 §14.12 saw — not a bug in `add-mapping` or `Mapping::fromArray` at all. Scenario 2 passed
  only because it never calls `add-mapping`.
- **Why the bare app-enable isn't enough either:** scenarios 1 & 3 then **land a file in the mapped
  folder**, firing `CreateInN8nListener`, which needs the live n8n connection to register the
  workflow and stamp the `n8n_mapping` the Then asserts. So the correct precondition is the **full**
  `Given the app is connected to n8n` (enable + URL + REST API + key), exactly like
  `create-workflow` / `copy` / `move`.
- **Fix:** added that `Background` to `mapping-membership.feature` (with a comment explaining the
  two-fold need). One-line, mirrors every other behavioural feature.

**Live-pod oracle confirmed my code is sound.** On `nextcloud-dbb454476-dvxwz` (cloud ns, 0.1.2,
branch code) I reproduced the *new* risk — a **nested `team_folder`** (`nextcloud-outer/nextcloud-inner`)
add-mapping → **exit 0, "Added mapping"**; `normaliseFolder` preserves the nested path as intended.
Cleaned up after myself: removed the `mm-probe-inner` probe by id; Kelly's two real mappings
(`nextcloud:tasking`, `nextcloud:admintest`) are **untouched**. The connection config on the pod was
never altered, so Kelly's instance is exactly as he left it.

**Kept** Claude's `RuntimeException` unmask in `addMembershipMapping` — it's a setup step (not a
behavioural assert), and the better diagnostics are worth keeping for the next masked-error footgun.
Next: push to PR #33, watch CI go 54/54. If green, the §14.7 ledger is all-green and the audition
(Chapter 3) is done.

#### 14.14 STATUS — PR #33 green; what's actually left (2026-06-23)

**PR #33 is green — all 54 integration scenarios pass** (plus PHP/JS unit, Psalm, both Quality jobs,
PR Tasks). Landed on the branch this round: Claude's **ignored-mode flips** (open-with + file-type)
and the real one-click **Toggle n8n mode** Files action; Copilot's **nested mappings**
(`MappingService::resolveForPath` nearest-enclosing + `mapping-membership.feature` live).

**Correction to §14.13's closing line:** the ledger is **NOT** all-green yet — the *one genuinely
unbuilt feature* (`mapping-membership`) is now done, but the §14.11 **little-wins checklist still has
a tail of un-flipped scenarios**. So the audition isn't quite over. Honest remaining inventory,
straight off the refreshed §14.7 ledger:

- **`mode-change`** (3 of 6) — `link→sync` retag (NC side); the 2× *n8n-override* scenarios
  (`sync→link` / `link→sync` from n8n). The reserved-tag resolver already re-modes at pull time, so
  these are likely **flippable** with fixture wiring, not new code.
- **`delete`** (4 of 9) — `unmapped` trash/purge/restore (3); purge-a-sync = permanent delete;
  abort-if-n8n-unreachable (already **coded** in `DeleteToN8nListener`, just needs its assertion).
- **`move`** (6 of 9) — hard-deleted restore-fallback, merge-on-collision (also retires a README
  doc-vs-code lie), brand-new move-in create. Real backend motion.
- **`reserved-tags`** (7 of 8) — the lone `@todo`: removing `n8n:ignore` returns the file to the
  mapping default. Needs a small **tag-removal listener** (`TagUnassignedEvent`), not yet built.
- **Left `@todo` on purpose** (don't count these against "done"): `open-with`/`file-type` **`link`**
  rows (no create-on-land path for link; covered by `tests/js/files-helpers.test.js` instead) and
  `file-type`'s **REPORT-by-indexed-mode** DAV-search query (the `nc:metadata-*` search plumbing is
  unproven against the pod — flip only if/when genuinely wired).

**Net:** every *feature* now has its core live; what remains is **edge-case scenario flips** (mostly
fixture wiring on already-shipped code) plus **two genuinely small backend builds** — the
un-ignore `TagUnassignedEvent` listener and the `move` merge-on-collision path. When those are
green the §14.7 ledger is all-green and Chapter 3 (the audition) closes; Chapter 4 is branding +
app-store submission.
