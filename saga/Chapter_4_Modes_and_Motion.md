# Chapter 4 — Modes & Motion

> **Status:** PLANNING. This chapter captures a redesign requested after Chapter 2
> shipped (post `v0.1.2`). It is the working spec + TODO ledger for two linked
> bodies of work: **(1)** collapsing the muddled `mode`+`writeback` data model into
> one clean, descriptive `mode`, and **(2)** finally building the file **motion**
> lifecycle (move-out / move-back / copy / merge) that Chapter 1 deliberately
> deferred. Nothing here is built yet — this is the plan we attack from.

The model for "what a managed file *is*" was only half-formed by the end of Chapter 1:
two fields (`mode` ∈ {sync, reference}, `writeback` ∈ {two-way, readonly, null}) that
together encode three user-facing states, plus a fourth (a `.n8n.json` that isn't in a
mapping) that has no representation at all. This chapter makes the model say what it means.

---

## 4.1 The new mental model — one `mode`, three (real) values

Collapse the two-field encoding into a **single descriptive `mode`**. `writeback` goes away
entirely — it was always inferable from the mode.

| `mode` | What the file is | Pushes to n8n? | n8n tag |
|---|---|---|---|
| **`sync`** | Full workflow JSON, NC-authoritative | Yes (two-way) | `n8n:sync` |
| **`link`** | Tiny pointer (id, name, URL) | No — click opens n8n | `n8n:link` |
| **`unmapped`** | A workflow file living outside any mapping | No | *(none)* |

### Decisions (locked)

- **Drop `writeback`** — the concept and the `nc:metadata-n8n_writeback` DAV property.
  Fully inferable from `mode`. (`two-way` *was* just `sync`.)
- **Drop `backup` mode.** Rationale: it is redundant with `sync` (sync already keeps the
  full workflow JSON in Nextcloud, so it *is* a backup), its "read-only" promise was
  **never actually enforced** (verified 2026-06-22: no edit-disabling anywhere — backup
  simply didn't push), `link` already serves as the implicit read-only option (you click
  through to n8n to edit), and an **`unmapped`** file (a copy or move-out) is a perfectly
  good "archive of sorts." Less surface, less redundancy.
- **`link` is the word everywhere** — code, config, UI, docs, system tag (`n8n:link`).
  The **single** exception is the DAV property *value*: a stored metadata value equal to
  the global function name `link` makes `is_callable($value)` true and **crashes core's
  PROPFIND** (it calls the value as a callback). So the `n8n_mode` DAV value for a link
  file is stored as **`reference`** — isolated to one translation point in
  `WorkflowMetadata`, with a note that `reference` ≡ `link` and is used *only* here and
  *only* because of that callable-string crash. `sync` and `unmapped` are not callable, so
  they store as-is.
- **Index `n8n_mode`.** With one descriptive field, marking it indexed makes "give me every
  sync file" / "every link" a real indexed metadata query — the "querying by mode much
  easier" win.

### Decision (confirmed 2026-06-22)

- **`unmapped` is an explicit, stored `mode` value, not a derived state.** A file that was
  moved out of a mapped folder gets `mode = unmapped` stamped on it. Rationale: querying a
  single indexed `mode = unmapped` is trivial; the alternative ("`sync` with an empty
  `n8n_mapping`") is a compound query the metadata layer makes awkward. No information is
  lost by overwriting the old `sync`, because **only `sync` files can ever become unmapped**
  (see §4.2) — so `unmapped` always implies "was sync."
  - **Not invasive** (this addresses an earlier worry): we only stamp `unmapped` on files
    that were **already managed and are transitioning out** of a mapping — i.e. during a
    move-out we are *already* rewriting metadata. A `.n8n.json` that was **never** in a
    mapping stays completely untouched (no metadata at all) — it is not "unmapped," it is
    just a plain file. `unmapped` is specifically the "ejected from a mapping" state.

### Metadata shape after this chapter

| key | sync | link | unmapped | notes |
|---|---|---|---|---|
| `n8n_id` | ✓ | ✓ | ✓ (kept) | stable workflow id |
| `n8n_versionId` | ✓ | ✓ | ✓ (kept) | enables restore on move-back |
| `n8n_mapping` | ✓ | ✓ | **cleared** | absence is part of "unmapped" |
| `n8n_mode` (indexed) | `sync` | `reference`¹ | `unmapped` | ¹ on-the-wire only; ≡ `link` |
| `n8n_syncedHash` | ✓ | ✓ | ✓ | loop guard |
| ~~`n8n_writeback`~~ | — | — | — | **removed** |

---

## 4.2 Motion — move, copy, restore, merge

The heart of this chapter. Nextcloud distinguishes **move** from **copy**, and that
distinction drives everything.

### Move OUT of a mapped folder

- **Only `sync` files may move out.** `link` move-out stays **blocked** (it makes no sense
  to eject a pointer). (`backup` is gone.)
- On a sync move-out:
  - **Archive the workflow in n8n** (soft — n8n archives rather than hard-deletes; and NC
    still holds the full JSON, so nothing is lost). Use `N8nClient::archiveWorkflow`.
  - **Keep** `n8n_id` + `n8n_versionId` in NC metadata.
  - **Clear** `n8n_mapping`.
  - **Set** `n8n_mode = unmapped`.

### Move BACK INTO any mapping (re-attach / restore)

- On move-in, if the file already carries an `n8n_id` + `n8n_versionId` →
  **unarchive/restore** that workflow in n8n (`N8nClient::unarchiveWorkflow`) instead of
  creating a brand-new one. Re-stamp `n8n_mapping` + `mode = sync`.
- If there is no id (a truly fresh file) → the existing create-on-land path makes a new
  workflow.

### Copy (NEW — `features/copy.feature`)

Principle: **a copy is always a brand-new instance with no metadata.** Copy is the safest
moment to strip metadata, full stop. Scenarios:

- **Copy within the same mapped folder** → strip metadata on the copy; create-on-land then
  registers it as a **new** workflow in n8n (new name, new id). Two files, two workflows.
- **Copy to outside any mapped folder** → strip metadata; it becomes a plain, untracked
  `.n8n.json` (normal "copy makes a new file" behaviour).
- **Copy of an `unmapped` file** (one that still carries id/versionId from a prior move-out)
  → strip metadata wherever it lands. The copy is a new instance; it must not inherit the
  original's n8n identity.
- Net rule: **strip metadata on every copy**, everywhere. Even a copy inside a mapped folder
  has no metadata until it is submitted to n8n and the new metadata comes back.

This is what makes **move** feel natural as "the same workflow leaving n8n and coming back"
(restore), while **copy** is unambiguously "a new thing."

### Move scenario matrix (for the rewritten `features/move.feature`)

The clear-behaviour cases:

| # | Move | Result |
|---|---|---|
| 1 | sync, **within** its own mapping (rename / into a subfolder) | stays `sync`/managed; no n8n change |
| 2 | **sync, out** to an unmapped location | → `unmapped`; **archive** in n8n; keep id+versionId; clear mapping |
| 3 | **unmapped (has id), into** any mapping | **unarchive/restore** in n8n; → `sync`; re-stamp mapping |
| 4 | plain `.n8n.json` (never in n8n), into a mapping | create-on-land → new workflow |
| 5 | **link, out** of its mapping | **blocked** with a message (ejecting a pointer is meaningless) |
| 6 | link / unmapped, within / to another unmapped spot | relocation only; no n8n change; metadata unchanged |
| 7 | unmapped (has id), into a mapping, **but the n8n workflow was hard-deleted** | unarchive 404 → fall back to create-on-land |

Cases that **need a decision** before they get a scenario (flagging, not deciding here):

- **`a` — sync moved directly mapping→mapping (different tag).** Re-tag in place
  (swap mapping A's tag for B's, keep the same workflow)? Or treat as eject-from-A +
  reattach-to-B? Or block? The old model blocked all mapped→mapped moves.
- **`b` — nested mappings.** Moving a file into a *subfolder* that belongs to a different
  mapping (mapping-membership says "nearest enclosing wins"). How does that interact with
  case `a`?
- **`c` — link rename within its mapping.** Does the link file's name matter / sync, or is
  a link's filename purely cosmetic (the n8n workflow name is authoritative)?
- **`d` — deleting an `unmapped` file** (it still has an id, and its workflow is archived in
  n8n). Trash → no-op? Purge → hard-delete the archived workflow? (Ties into delete.feature.)

### Merge / prune (NEW — reconcile duplicates)

A race the new model creates and must resolve:

1. A sync file is moved out → `unmapped` in NC, archived in n8n.
2. Meanwhile, someone **restores/unarchives that workflow directly in n8n**.
3. The mapping's scheduled pull re-syncs it back into NC as a fresh **mapped** file.
4. Now NC has **two** copies of the same workflow: the `unmapped` move-out copy **and** the
   freshly-pulled mapped copy.

**Prune rule:** on detecting an `unmapped` file whose `n8n_id` matches a workflow that is now
present (mapped) again, **delete the redundant `unmapped` NC copy** — the n8n-sourced/mapped
one is authoritative and newer. A good place to run this is the pull/reconcile pass: spotting
a duplicate `n8n_id` (one mapped, one unmapped) is the signal.

---

## 4.3 TODO ledger

### Model refactor (Phase 1 — the cleanup)

- [ ] `Mapping` / `MappingService`: single `mode` ∈ {`sync`, `link`}; drop `writeback`;
      `fromArray` keeps reading legacy `{mode, writeback}` + `link`/`reference` shapes and
      maps `backup`(=sync+readonly) → `sync`, `reference` → `link`.
- [ ] `WorkflowMetadata`: remove `KEY_WRITEBACK`; index `KEY_MODE`; isolate the
      `link`↔`reference` DAV-value translation here with the note. Handle clearing the now-
      defunct `n8n_writeback` from already-stamped files.
- [ ] `OwnershipTags`: drop `n8n:backup`; `tagFor(mode)` becomes single-arg
      (`sync`→`n8n:sync`, `link`→`n8n:link`); strip `n8n:backup` as a legacy tag on re-tag.
- [ ] Replace every `(mode, writeback)` check with `mode` across listeners + services:
      `DeleteToN8nListener`, `DeleteService`, `NameSyncListener`, `CreateInN8nListener`,
      `NodeWrittenListener`, `RestoreFromTrashListener`, `PushService`, `CreateService`,
      `SyncService`, `ReconcileNameJob`, `PushWorkflowJob`.
- [ ] Admin UI: `mapping-settings.js` + `templates/mapping_settings.php` — drop the
      backup/writeback controls; mode picker = sync | link. `occ AddMapping` + the mapping
      controllers (`ConfigController`, `MappingController`) likewise.
- [ ] Migration `RepairStep`: rewrite the `mappings` app-config (collapse to `mode`, drop
      `writeback`, backup→sync) and re-stamp managed files (new `n8n_mode`, drop
      `n8n_writeback`). Idempotent; runs on upgrade.
- [ ] **Live-data migration:** run the migration against the `cloud/nextcloud` pod (exec in).
      Current live mappings are both `sync+two-way` → become `sync`; no backup mappings exist.
- [ ] Docs: README mode tables (drop Backup row, drop Writeback column, drop the
      `n8n_writeback` DAV row); `AGENTS.md` / `CONTRIBUTING.md` if they mention the model.

### Motion (Phase 2 — new behaviour)

- [ ] Move-out (sync → unmapped): archive in n8n, keep id+versionId, clear mapping, set
      `mode=unmapped`. (`MoveGuardListener` grows from "veto all" into the real handler.)
- [ ] Move-out of a `link` stays blocked (with a message).
- [ ] Move-in restore: id+versionId present → `unarchiveWorkflow` + re-stamp sync/mapping;
      else create-on-land.
- [ ] Copy strips metadata everywhere (needs copy-vs-move detection at the NC event layer).
- [ ] Merge/prune: pull/reconcile detects an `unmapped` file duplicating a now-mapped
      `n8n_id` and deletes the redundant unmapped copy.

### Specs to author / update (the `features/` ask — done first; they ARE the spec)

> **Spec campaign complete (2026-06-22).** All feature files + the README now describe the
> target model as if implemented. New-behaviour scenarios are `@todo` (CI skips them) until
> the code lands; the live suite stayed green. `FeatureContext` step defs remain on the old
> model until Phase 1 (one binding renamed: `untracked` file ≠ `unmapped` mode).

- [x] **NEW `features/copy.feature`** — copy within mapped (→ new workflow), copy outside
      (→ plain file), copy of an unmapped file (→ strip). The "always strips" principle. `@todo`.
- [x] **NEW `features/reconcile.feature`** — the merge/prune (§4.2): a pull prunes the
      redundant unmapped copy when its workflow returns to the mapping. `@todo`.
- [x] **Rewrote `features/move.feature`** — the §4.2 matrix (within-mapping, sync move-out →
      unmapped+archive, move-in → restore, hard-deleted → create, brand-new → create, link
      move-out blocked, unmapped relocation no-op) + the a–d decision cases as comments. `@todo`.
- [x] **`features/delete.feature`** — dropped backup; the tag-strip outline is now link-only
      (live); renamed the plain-file case to **untracked**; added `unmapped`-mode trash/purge/
      restore as `@todo`. (Purge of sync stays `@todo` per Chapter 2 §5.3.)
- [x] **`features/file-type.feature`** — dropped the `nc:metadata-n8n_writeback` DAV row;
      noted mode values `sync | reference(=link) | unmapped`. `@todo`.
- [x] **`features/admin-mapping.feature`** — dropped backup + the writeback-invariant
      scenarios; full storage × {sync,link} matrix (live); new-model "mode must be sync|link"
      invariant as `@todo`.
- [x] **`features/mapping-membership.feature`** — added the "outside every mapping →
      untracked or unmapped" case.
- [x] **`features/create-workflow.feature` / `rename.feature`** — swept; already `sync`-clean,
      no change needed.
- [x] **`README.md`** — rewritten as the end-state advertisement (Modes = sync/link/unmapped;
      Move = same-workflow/restore; Copy = always-new; Reconcile & prune; no backup/writeback).
- [ ] Integration `FeatureContext::modeToModel()` and step defs — collapse to the new model
      (Phase 1 code work; this flips the `@todo` model scenarios live).

### Audit / verify

- [ ] Confirm nothing relies on `writeback`/`backup` outside the catalogued files (re-grep
      after Phase 1; the pre-work grep found ~330 hits across ~30 files).
- [ ] Confirm n8n's REST `unarchive` actually restores a workflow archived by our move-out
      (live smoke test) — the restore path is load-bearing for the motion design.

---

## 4.4 Proposed attack

1. **Specs first.** Author `copy.feature` + rewrite `move.feature` + sweep the other
      `.feature` files to the new model. These are the BDD source of truth; write them now,
      mark the not-yet-built behaviour `@todo` (CI skips it) so they document intent.
2. **Phase 1 (model refactor)** as one PR: the data-model collapse + migration + live-data
      migration + docs/UI + updating the *already-live* specs (admin-mapping, file-type,
      delete, create, rename) to the new model so the green suite stays green. Bump version.
3. **Phase 2 (motion)** as a second PR (or a short series): build move-out/move-in/copy/merge,
      flipping each `@todo` scenario live as its step defs + code land — exactly the rhythm
      that worked for create/rename/delete in Chapter 2 §5.3.

### Resolved (2026-06-22)

- ✅ **Explicit `unmapped` mode** (§4.1).
- ✅ **Two PRs.** Phase 1 = the cleanup **and** the new-metadata migration, so the live data
      is converted to the new shape up front. Phase 2 = the motion features (move/copy/
      restore/merge) as an isolated, sizeable body of work across **code + tests + docs**,
      built on the Phase 1 foundation.
- ✅ **backup → sync** on migration (keep full content). Live has none; correctness only.

### Still open (decide when we reach them)

- The **move decision cases `a`–`d`** in §4.2 (mapped→mapped, nested mappings, link rename,
      deleting an unmapped file).
- Additional `move.feature` scenarios beyond the matrix above — none more from Kelly right
      now; the matrix + decision-cases is the working set.
