# Chapter 4 — Showtime

> **Prerequisite:** Chapter 3 (An Audition) is functionally complete *to Kelly's
> satisfaction*. The encore (§15) settled the mode model — the folder mapping is the law,
> a `link` is read-only everywhere, the per-file toggle is gone. A clean, signed, versioned
> release tarball comes out of the pipeline. There is no fixed checklist for "done"; it's done
> when it's ready for the market.

The app works. It packages. Chapters 2–3 were about *function* — making it do the right thing and
making it safe to change. Chapter 4 is about *presence*: turning a working app into a **product**
with a face, a name, and a quality story, and then (the finale, if we get there) getting it onto
[apps.nextcloud.com](https://apps.nextcloud.com) so anyone can install it with one click.

But Chapter 4 is not *only* the store. There are three acts before the curtain call, and they are
the substance of this chapter:

1. **A frozen-feature refactor** — features locked, no behaviour changes, purely *"now that we can
   see the whole thing, make the code as good as the product."* Less code, same verifiable result,
   plus the tests that pin the behaviour so the polish can't regress it.
2. **Branding** — name, icon system, copy, screenshots. The pivot from *what it does* to *what it is*.
3. **Quality stamps** — badges that tell a visitor, in one glance at the README, that this thing is
   tested, licensed, and cared for.

Only after those does the store mechanic make sense — because the store *consumes* their outputs
(the icon ships in the tarball, the copy becomes the listing, the badges back the trust). Brand and
polish first; submit last. **The store is the finale, and the finale may not happen this chapter** —
that's fine. The first three acts stand on their own.

---

## The epics (this chapter's arc)

A chapter is a large arc; these are the epic-sized units inside it (the numbered §-items below are
the detailed backlog). They're in *causal* order — each clears the ground for the next.

**The through-line: polish the code → dress the product → stamp the quality → (maybe) take the stage.**

0. **Transition *out of* Chapter 3.** The encore (§15.3) ended on a *negative diff* — deleting the
   toggle. Chapter 4 opens in that same spirit: the first real work is more deletion and tightening
   (the frozen refactor), not new features. A good transition makes a good story, and this one is
   "the band plays fewer notes."
1. **Frozen refactor** (§4.1) *reveals the final shape* — and the test net it adds is what makes
   every later cosmetic change safe.
2. → *transition* → with the code in its final shape, **branding** (§4.2) can dress a target that
   has stopped moving.
3. → *transition* → a branded, polished repo earns its **quality stamps** (§4.3) — badges only mean
   something once the thing behind them is real.
4. → *transition: **showtime*** → the assets from 1–3 (icon, copy, screenshots, green badges) are
   exactly the inputs the **store submission** (§4.4–§4.8) consumes.

| # | Epic | Status | Detail |
|---|---|---|---|
| 1 | **Frozen refactor** — features-locked DRY/naming/test pass; less code, same result | ◑ | §4.1 (Phase A landed in PR #40; Phase B = the from-scratch pass below) |
| 2 | **Branding** — name & tagline, icon system, store copy, screenshots, repo face | ☐ | §4.2 |
| 3 | **Quality stamps** — README badges mapped to real CI + REUSE/license | ☐ | §4.3 |
| 4 | **Store: info.xml** — schema-valid metadata with the branding assets dropped in | ☐ | §4.4 |
| 5 | **Store: signing** — CSR → countersigned cert → app-id registration | ☐ | §4.5 |
| 6 | **Store: pipeline** — `signature.json` + tarball signature + upload step in `publish.yml` | ☐ | §4.6–§4.7 |
| 7 | **Finale: first store release** *(may not happen this chapter)* | ☐ | §4.8 |

Epics 1–3 are entirely in our control and stand alone. Epics 4–7 are the store run; the **CSR
countersign wait (5)** is the only external dependency in the whole chapter — start it early so it
overlaps the polish work.

---

## Code Review — top potential areas of improvement (the brief for Epic 1)

> A full read of `lib/` (≈7.3k PHP LOC) and `src/`/`js/` as of the close of Chapter 3. This is the
> *menu* for the frozen refactor: **no behaviour changes**, features frozen, every item verifiable
> against the existing `.feature` specs + unit suite. Ordered by value. Nothing here is a known bug
> — the app is correct; this is about making the code as good as the product.
>
> **Status (PR #40):** A2 (`stampSynced`) and A3 (`isWorkflowName`/`isWorkflowFile`) are **done**;
> B1/B3 partially (stale §15.3 comments + the writeback→Sync/AutoSync renames). The rest — and a
> deeper, gloves-off *from-scratch* pass — is laid out in **Round 2** below.

### A. DRY — the same logic living in two places

**A1. The n8n workflow-body normalization is duplicated across `CreateService` and `PushService`
(highest-value extraction).**
Both hold a byte-identical copy of: the writable-field whitelist `['name','nodes','connections',
'settings','staticData']`, the 8-element `settings` allowlist, and the `[]→{}` coercion loop over
`['connections','settings','staticData']`. This is the **n8n schema contract** — an *external,
moving* target — and it lives in two files that must be kept in lock-step by hand.
→ Extract one `N8nWorkflowBody` (value-service) with `toCreateBody(\stdClass, $stem)` and
`toUpdateBody(string $json)`. Single source of truth for n8n's quirks; when n8n changes its schema,
one file changes. Files: [`lib/Service/CreateService.php`](../lib/Service/CreateService.php#L47-L173),
[`lib/Service/PushService.php`](../lib/Service/PushService.php#L119-L174). *Bonus:* it becomes
trivially unit-testable (see C2).

**A2. The 5-key metadata stamp is written out three times.**
The `metadata->write([KEY_ID, KEY_MODE, KEY_VERSION_ID, KEY_SYNCED_HASH => sha1($body), KEY_MAPPING])`
+ `tags->apply()` block appears twice in `SyncService::writeWorkflow` (update branch + fresh-write
branch) and again in `CreateService::stampFile`.
→ Add `WorkflowMetadata::stampSynced($fileId, $id, $mode, $versionId, $body, $mappingId)` that hashes
the body internally and writes all five. Three call sites collapse to one line each. Files:
[`lib/Service/SyncService.php`](../lib/Service/SyncService.php#L516-L550),
[`lib/Service/CreateService.php`](../lib/Service/CreateService.php#L228-L234).

**A3. The "is this a managed `.n8n.json` file?" guard is open-coded ~6+ places.**
`$node instanceof File && str_ends_with($node->getName(), FilenameCodec::EXT)` is repeated in
`SyncService` (pushOne, collectManaged), `ModeTagListener`, and the lifecycle listeners.
→ One predicate `FilenameCodec::isWorkflowFile(Node): bool` (or `isManagedName(string)`). Centralizes
the rule and reads better at each call site.

**A4. The 20-page cursor-pagination loop is duplicated** between `SyncService::iterateWorkflows` and
`N8nClient::listTags` (the `MAX_PAGES` comment even cross-references the other). → A single
`N8nClient::paginate(string $path, array $query): iterable` both can consume.

**A5. The JSON-encode flag set**
`JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` is
repeated verbatim (SyncService encodeReference/encodeSync; near-variants in N8nClient/CreateService).
→ A shared `const` or tiny `Json::encodePretty()` helper. Low effort, removes a footgun (one place
forgets `UNESCAPED_UNICODE` and bytes drift).

**A6. The sync result-shape is hand-built five times.** `pullAll`/`pushAll`/`pullOne`/`pushOne`/
`runInline` each assemble `['processed'=>, 'succeeded'=>, 'failed'=>, 'status'=>, 'message'=>]` and
re-derive `status = failed === 0 ? 'ok' : 'error'`. → A small `SyncResult` value object (or named
constructor) removes the repetition *and* gives Psalm a real type instead of `array<string,mixed>`.

### B. Naming & stale comments (Kelly explicitly flagged this)

**B1. Stale registration comments in `Application.php` that contradict the §15.3 encore.**
Lines ~143–151 still say *"assigning n8n:sync / n8n:link to a managed file is a request to change its
mode (sync ⇄ link)… rewrites the body, re-stamps the mode, and enforces one-mode-tag exclusivity."*
That capability was **deleted** in §15.3 — `ModeTagListener` now acts only on `n8n:ignore`. Line ~67
also references an `n8n:reference` *system tag* that doesn't exist (the pills are `n8n:sync` /
`n8n:link`). These comments now actively mislead. Fix to match reality.
File: [`lib/AppInfo/Application.php`](../lib/AppInfo/Application.php#L62-L151).

**B2. The Settings/ class names don't map to what the panels are.** Eight classes —
`AdminSection`, `AdminSettings`, `AdminTest`, `InstanceSettings`, `MappingSettings`, `SyncSettings`,
`WebhookSettings`, `WritebackSettings` — and the names tell you almost nothing. `AdminTest` is, per
its own info.xml comment, *"no longer rendered as its own panel"* (it survives only as the auth
target for the test endpoints) yet still lives in `Settings/`. `AdminSettings` is actually the REST-API
panel. → Audit what each one renders (info.xml declares only `MappingSettings`/`SyncSettings`/
`AdminSection`; `Application.php` registers `Instance`/`Admin`/`Writeback`/`Webhook` declaratively),
rename to intent (e.g. `RestApiSettings`, `PushTimingSettings`), and move `AdminTest` out of
`Settings/` if it's really a controller now.

**B3. "Writeback" is a retired word still wearing two class names.** The model dropped writeback as a
*setting* — mode is the single source of truth, and `WorkflowMetadata`'s docblock literally says
*"n8n_writeback was removed."* Yet `WritebackSettings` and `WritebackNotifier` keep the term, while
the service that does the actual NC→n8n send is called `PushService`. Pick **one** verb for the
direction (recommend **push**, to match `PushService`/`PushWorkflowJob`) and apply it. ~17 files
mention "writeback"; many are legitimate prose about the direction, so this is a *naming* decision +
two renames, not a blanket find-replace.

**B4. `dispatch()` means two different things.** `SyncService::dispatch()` routes a pull/push
direction; `N8nClient::dispatch()` is an HTTP-verb switch. Both sit in the sync domain. Rename the
HTTP one to `send()`/`execute()` to kill the conceptual collision.

**B5. (Defer / note-only) The `link` ⇄ `reference` synonym tax.** The whole codebase pays a constant
translation cost (`toWire`/`fromWire`, the README footnote, `MODE_LINK` vs `WIRE_LINK`) because the
string `link` collides with PHP's `link()` in core's PROPFIND callback path. It's correct and
*beautifully* documented — but it's a permanent readability drag. A truly clean fix (store a
non-callable canonical value like `pointer` everywhere, no translation) is a **metadata migration**,
which brushes against "features frozen." Flag it, weigh it, probably **defer** — but it's the one
naming wart worth a conscious decision rather than silent acceptance.

### C. Tests to add (so the refactor can't regress the features)

The refactor's safety net is the existing Behat suite + unit suite — but there are gaps right where
the frozen refactor will be cutting. Close these *first*, then refactor against them.

**C1. The §14.21 leftovers, re-homed at the right layer.** `delete` purge-sync & unmapped-purge are
blocked by a *test-harness* wall (a DAV purge doesn't fire the listener in CI), and abort-if-n8n-
unreachable *is coded* but unproven. All three are a better fit for **PHPUnit on `DeleteService` /
`DeleteToN8nListener`** than for Behat. Cheap, and they pin the delete contract.

**C2. `CreateService`, `PushService`, `N8nClient`, `WorkflowMetadata` have no unit tests.** These hold
the **highest-risk external-contract logic** in the app (the settings allowlist, the `[]→{}`
coercion, the encrypt/decrypt + error-shaping). They're covered only by integration. After A1 extracts
`N8nWorkflowBody`, add a unit test pinning: *empty settings → `{}` not `[]`*, *unknown settings key
dropped*, *writable-field whitelist honoured*. This is the literal regression net for A1's extraction.

**C3. `SyncService` reconcile invariants are integration-only.** `pruneStale`, the collision-suffix
counter, and the `ignored`-skip path are the heart of pull and have no focused unit test. A small one
locks "stale → pruned, seen → kept, ignored → never pruned and never re-pulled."

> **The refactor's contract:** every `.feature` file stays green and unedited; the unit suite only
> *grows*; `git diff --stat` for Epic 1 should be net-negative on `lib/` LOC while net-positive on
> `tests/`. If a change needs a spec edit, it isn't a frozen refactor — it's a feature, and it waits.

---

## Code Review, Round 2 — the from-scratch pass (gloves off)

> Round 1 (above) was *"protect what's there, dedupe the obvious."* Round 2 asks a harder question,
> the one Kelly posed: **if none of this were written yet and I designed it today, what would be
> different — and where is the *significant* win for security, performance, or maintainability?**
> This is deliberately less protective. But "gloves off" is not "rampage": each item below is sized
> and risk-rated, and the rule from Round 1 still holds — **the integration net comes first, and a
> `.feature` edit means it stopped being a refactor.** Some of these are bigger than a frozen
> refactor and are flagged as **design decisions for Kelly**, not silent rewrites.

The single biggest observation from reading the whole tree: **the app is a pile of thin event
listeners that each independently re-derive the same context.** On one save of a sync file,
`NodeWrittenEvent` fans out to **three** listeners (`NodeWrittenListener`, `CreateInN8nListener`,
`NameSyncListener`); a rename fans `NodeRenamedEvent` to **four** (`+ MotionListener`,
`MimeRestampListener`). Every one of them, on every event, repeats the same preamble: *guard-check →
is-it-a-`.n8n.json`-File → read Files-Metadata → pull `id`/`mode` out of an `array<string,mixed>` →
maybe `resolveForPath`.* That preamble is the real duplication — bigger than any single A-item — and
it drives most of what follows.

### R1 — One coordinator per file event *(maintainability; the headline)*

**Today:** 3 listeners on write, 4 on rename, each doing its own metadata read (so up to **3 reads +
2 `getContent()` per save**), its own guard check, its own mapping resolve, in *registration order*
with the cross-listener ordering dependencies (create-on-land must precede push; name-sync interplays
with both) left **implicit**.

**From scratch:** one `FileEventRouter` per event that runs the cheap gate once, builds a
`ManagedFileContext` **once** (node + typed metadata + resolved source/target mapping + content hash),
and dispatches to ordered handlers (`create-on-land → name-sync → push`). The handlers become pure
functions of a context object instead of seven classes each re-reading the world.

- **Win:** one readable place for *"what happens when a workflow file is saved/renamed"*; one
  metadata read instead of three; explicit, testable ordering.
- **Risk: HIGH.** This is the most delicate code in the app — §15.1 is a monument to how many NC
  gotchas live here (the part-file branch, `LockedException` during rename forcing the
  `ReconcileNameJob` deferral, the mime re-stamp timing). The current design's *one virtue* is that
  each listener is independently, defensively safe. Collapsing them trades that for clarity.
- **Recommendation:** **worth it, but incremental and behind the net.** Don't big-bang it. First land
  R2 (the context object) and let the listeners *consume* it while staying separate; only then
  consider merging the dispatch. If the integration suite isn't materially expanded first (C1–C3 plus
  new name-sync/push coverage), **don't start.**

### R2 — A `ManagedFile` value object to kill the `array<string,mixed>` dance *(maintainability; do this first)*

`$meta = $this->metadata->read($id); $wfId = $meta[KEY_ID] ?? null; if (!is_string($wfId) || $wfId
=== '') return; if (($meta[KEY_MODE] ?? '') !== MODE_SYNC) return;` — this exact shape appears in
**~10 places** (`NodeWrittenListener`, `NameSyncListener`, `MotionListener`, `ModeChangeService`,
`ModeTagListener`, `DeleteToN8nListener`, `SyncService`, …).

→ `WorkflowMetadata::read()` returns a typed `ManagedFile` (or `null`): `->workflowId`, `->mode`,
`->mappingId`, `->syncedHash`, `->isManaged()`, `->isSync()`, `->isUnmapped()`. The ten guard-ladders
collapse to `$mf = $this->metadata->read($id); if (!$mf?->isManaged()) return;`.

- **Win:** deletes the most-repeated boilerplate in the codebase, gives Psalm real types instead of
  `array{...}|null`, and is the natural building block for R1.
- **Risk: LOW–MEDIUM** (touches many files but each change is mechanical and unit-coverable).
- **Recommendation:** **the first move of Phase B.** It pays for itself immediately and de-risks R1.

### R3 — Unify *all* workflow-body shaping in one codec *(maintainability + correctness; high value)*

This is Round-1 **A1** plus a second duplication I missed then: `encodeReference()` and `encodeSync()`
are **copied verbatim** between `SyncService` and `ModeChangeService` (the latter's docblock even
says *"Mirrors `SyncService::encodeReference` — replicated here so the re-mode engine owns no
dependency on the bulk reconciler"*). So workflow-body shaping lives in **four** places across two
axes: *create/update* bodies (CreateService/PushService) and *reference/sync* bodies
(SyncService/ModeChangeService).

→ One `N8nWorkflowBody` codec owns every shape: `toCreateBody`, `toUpdateBody`, `encodeReference`,
`encodeSync` — the whole n8n-schema contract (the writable-field whitelist, the 8-key settings
allowlist, the `[]→{}` coercion, the pointer payload) in one unit-tested class.

- **Win:** the external, *moving* n8n contract changes in exactly one file; trivially testable
  (closes C2). Highest-value DRY left.
- **Risk: LOW** (pure functions, guarded by new unit tests).

### R4 — The mimetype hack: isolate, scope, and make uninstall reversible *(security/store + performance)*

`RegisterMimetype` writes into the **Nextcloud core tree** — `core/img/filetypes/n8n.svg`,
`core/js/mimetypelist.js`, and `config/mimetype*.json` — and three separate sites
(`SyncService`, `CreateService`, `NodeWrittenListener`) fire `updateFilecache('n8n.json', …)`, the
last on **every single save** as an instance-wide UPDATE keyed on the name pattern.

Three distinct problems, three from-scratch fixes:

1. **Clean uninstall is a store rule (§4.7) and we don't honour it.** Disabling the app leaves our
   bytes in `core/` and the rewritten `mimetypelist.js`. → Add an **uninstall repair-step** that
   reverts the core/config writes. Until then the store-compliance box is unchecked.
2. **Per-save instance-wide UPDATE is a performance smell.** `restampMimetype()` runs on every
   `.n8n.json` `NodeWrittenEvent` as a pattern UPDATE across the whole `oc_filecache`. → Scope it to
   the **single file's id**, or drop the per-save restamp entirely and rely on the rename
   (`MimeRestampListener`) + pull (`fixupFilecacheMimetype`) paths.
3. **The duplication is a symptom.** The `'n8n.json'` literal and the restamp call live in 3–4
   places. → One `WorkflowMimetype` service owns the constant and the (now single-row) restamp.
4. **The compound `.n8n.json` extension is the root cause of the mimetype work — and it is a
   *deliberate, locked* choice, not an accident to refactor away.** NC's detector only inspects the
   last extension segment, which is what forces the `mimetypemapping`/`mimetypealiases` registration.
   The shape earns its keep: the file *is* real JSON (`.json`), so **outside** Nextcloud — a desktop
   sync, a download — the OS opens it in a JSON editor with zero extra setup. The `.n8n.` segment is
   the hook NC keys the custom mimetype / icon / file-actions off **inside** the UI. The alternatives
   both lose: plain **`.json`** → no custom icon/actions/mimetype (it's just another JSON file); bare
   **`.n8n`** → off-Nextcloud the OS has no handler for it and *nothing opens it*, when it should just
   open as JSON. So R4 is "**isolate and make the hack reversible**," never "drop the extension."
   (Locked — see AGENTS.md non-negotiables.)

- **Win:** ticks a real store-rejection box (uninstall), removes a hot-path full-table UPDATE, and
  contains the one piece of the app that reaches outside its own sandbox.
- **Risk: MEDIUM** (the uninstall revert needs live-pod verification; the restamp scoping is testable).

### R5 — `allow_local_address => true` everywhere: name the SSRF trade-off *(security)*

Every n8n call and webhook sets `'nextcloud' => ['allow_local_address' => true]`. For a **homelab**
this is *correct and necessary* — the n8n URL is an in-cluster address. But for a **public app-store
app**, this is a textbook SSRF lever: an admin (or anyone who can set the n8n URL) can point the
server at `169.254.169.254`, `localhost:6379`, or any internal service, and the app will dutifully
connect. Nextcloud blocks local addresses **by default** precisely for this reason; we opt out
globally and silently.

- **From scratch:** keep the capability (the target user *needs* it) but make it a **deliberate,
  documented, ideally opt-out-able** decision — a setting (`allow_local_n8n`, default on for the
  homelab story) and an honest paragraph in `SECURITY.md`. A store security audit (§4.7) *will* look
  at this; better to have the rationale written down than to explain it reactively.
- **Risk: LOW** to document; **MEDIUM** if we make it a real toggle (a wrong default breaks every
  in-cluster install). **Recommendation:** document now; toggle only if the store asks.

### R6 — Stop re-reading + re-migrating the mapping list on every call *(performance + maintainability)*

`MappingService::list()` reads AppConfig, JSON-decodes, **runs legacy migration**, and *re-persists*
on every invocation — and `getById()`/`resolveForPath()` each call `list()`. A single file event with
several listeners calling `resolveForPath` decodes and migration-scans the whole list multiple times
per request.

- **From scratch:** (a) **request-scoped memoization** of the parsed list; (b) move the
  legacy-shape migration **out of the read path** into a proper one-shot `Migration` repair-step, so
  `list()` is a pure read and migration runs once at upgrade — not opportunistically on every page
  load that happens to read mappings.
- **Win:** removes redundant decode/migrate work from the hot path; makes `list()` side-effect-free
  (a read that sometimes *writes* is a latent surprise).
- **Risk: LOW.**

### R7 — Round-1 carryover + new comment/citation hygiene

Still open from Round 1, plus what Round 2 surfaced:

- **A4** (paginate helper), **A5** (JSON-flag constant — note there are *two* sets: pretty for file
  bodies, compact for wire bodies), **A6** (`SyncResult` typed object), **B2** (Settings-class naming
  audit), **B4** (`dispatch()` collision), **B5** (the `link`⇄`reference` synonym tax — still
  *defer*, it's a metadata migration).
- **A3 had a miss:** `NodeWrittenListener:67` still hardcodes `str_ends_with(…, '.n8n.json')` instead
  of `FilenameCodec::isWorkflowFile` — the literal, not `EXT`, so the sweep skipped it. Trivial fix.
- **More stale docblocks (continue B1):** `Mapping.php` still says *"Per-workflow tags in n8n can
  override the mode at the file level"* — that override was **killed in §15.3**; only `n8n:ignore`
  (exclude) remains. And the `saga Ch2 §14` citations in `lib/` docblocks (`Mapping`,
  `MappingService`, `ModeChangeService`) should read **Ch3 §14**, matching the feature-file fix.

### Suggested sequencing for Phase B

```
net first ──▶ R2 (ManagedFile)  ──▶ R3 (N8nWorkflowBody)  ──▶ R6 (mapping memo + migration step)
   │              │ low risk            │ low risk               │ low risk
   │              └──────────────┬──────┴────────────────────────┘
   │                             ▼
   │                    R7 (carryover DRY + comment hygiene)   ← cheap, do alongside
   │
   └──▶ R4 (mimetype: uninstall + scope)  ──▶  R5 (SSRF doc)  ──▶  R1 (event coordinator)
            MEDIUM, store-relevant            LOW, security        HIGH risk, last & incremental
```

R2/R3/R6/R7 are the safe, high-value core of Phase B — *do these*. R4/R5 are **security/store**
wins worth their weight before the submission epics. R1 is the prize for maintainability but the
sharpest knife — it goes **last, incrementally, behind a materially bigger integration net**, and
only if the earlier rounds haven't already made the listeners pleasant enough to leave alone.

### Phase B progress log

One slice per PR; integration green is the gate, since these touch the load-bearing lifecycle paths.

- **PR #41 — R2 (ManagedFile).** Merged. `read()` returns a typed value object; 16 call sites
  simplified; behaviour identical on real NC + n8n.
  - **Lesson, carved in:** dropping the local `$id = $meta[KEY_ID]` binding looked safe at every
    site, but `ReconcileNameJob` used `$id` *downstream* (in `FilenameCodec::format`) — **Psalm
    caught it as `UndefinedVariable` before it ever ran.** When you collapse a guard ladder, grep the
    rest of the method for the variable you just stopped binding; don't trust the eyeball. Static
    analysis is the safety net for the net.
- **PR #42 — R3 (N8nWorkflowBody).** Merged. The duplication was worse than Round 1 logged: not
  two copies but **four** — `encodeReference`/`encodeSync` were *verbatim* twins in `SyncService`
  **and** `ModeChangeService`, a detail Round 1 missed and only a full re-read surfaced. The codec is
  pure + unit-tested, closing the C2 gap (`CreateService`/`PushService` body logic had zero unit
  coverage before).
  - **Operational lesson (not a code one):** between merges the Bash shell's cwd silently snapped back
    to `/projects/cluster`, and a bare `git commit && push` ran against the **wrong repo** — committing
    unrelated cluster WIP under an n8n message and pushing it. Reversed with `reset --mixed` +
    `push --force-with-lease`. **Use `git -C <repo>` for every git op across sibling repos; never trust
    the ambient cwd.**
- **PR #43 — R6 + R7.** Merged. R6: `MappingService::list()` was reading + JSON-decoding +
  *migrating* + sometimes **re-writing** AppConfig on *every* call, and the lifecycle listeners call
  `resolveForPath` several times per event — so one file event meant several decode+migrate passes.
  Now: a request-scoped cache, and the legacy rewrite lives in a `MigrateMappings` repair step (runs
  once on upgrade), so a read is just a read. R7: fixed the `Mapping` docblock still claiming a
  per-file mode override (killed in §15.3) and swept `Ch2 §14`→`Ch3 §14` across `lib/`. (A5 paginate +
  R5 SSRF doc rode along.)
- **PR #44 — Uninstall + Purge (a *feature*, not a refactor).** Surfaced by Kelly interrogating R4:
  the "uninstall-revert" I'd filed under the frozen refactor was actually **net-new behaviour**, and
  the *real* design work was the **data safety**, not the mimetype cleanup. The resolved model:
  - **Uninstall** reverts only the shared *system* writes (`UnregisterMimetype`, the `<uninstall>`
    repair step — NC does support that hook) and **orphans** the user's data. It never deletes files,
    never clears metadata, never touches n8n. Because files keep their `n8n_id`, a reinstall + pull
    reconciles them in place — the reconnect is *free*, thanks to the reconcile-by-id design. (Clearing
    metadata would be the one tempting move that breaks it — duplicates on reinstall.)
  - **Purge** is an explicit admin button (you can't prompt during a non-interactive uninstall). The
    data-safety rule Kelly sharpened: purge deletes only what a pull can **restore** — `sync`/`link` —
    and keeps `unmapped` (an archived standalone copy / template), `ignored`, and untracked files.
    *"I wouldn't want to lose a template because of purge."* Runs under SyncGuard, so n8n is untouched;
    reversible by "Sync from n8n".
  - **Lesson:** "make uninstall clean" is two different jobs — *system* state (revert) vs *user* data
    (never auto-delete) — and conflating them is where data gets hurt. The store rule is about the
    former; the latter is the user's. Specs are `@todo` (the system leg needs a live app-remove the
    CI harness can't drive); the purge *logic* (the safety crux) is unit-tested.

> *The from-scratch lens keeps paying out: every round we open the floorboards we find one more copy
> of the same plank. The point of Phase B isn't to admire the house — it's to make the next change a
> one-file change.*

---

## §4.1 — Epic 1: The frozen-feature refactor

**Goal:** the same verifiable behaviour, less code, better names, more tests. Features are *frozen*
— this is the "now that we can see the finished product, make it beautiful" pass the encore earned us.

Two phases: **Phase A** is the safe, obvious dedupe (mostly landed in PR #40); **Phase B** is the
gloves-off *from-scratch* pass (the **Round 2** section above), bigger and risk-tiered.

**Phase A — the safe pass (✅ landed in PR #40):**

- ✅ **A2** (`WorkflowMetadata::stampSynced`) — the 5-key stamp, 3 sites → 1.
- ✅ **A3** (`FilenameCodec::isWorkflowName`/`isWorkflowFile`) — 15 open-coded guards → 1 predicate
  (with `@psalm-assert-if-true` narrowing). *Carryover:* the `NodeWrittenListener:67` literal miss (R7).
- ✅ **B1/B3 (partial)** — stale §15.3 comments fixed; `Writeback*` → `Sync*`/`AutoSync*`.
- ✅ Docs: `occ n8n_sync:sync` documented; feature-spec saga citations corrected; Ch4 plan.

**Phase B — the from-scratch pass (the prize; sequence from Round 2):**

- ☐ **Net first.** C1–C3 (delete/create/push/reconcile unit coverage) *plus* the bigger integration
  coverage R1 needs — land against *current* code, all green, before touching structure.
- ✅ **R2** `ManagedFile` value object → killed the `array<string,mixed>` guard-ladder across **16**
  read sites; `read()` now returns `?ManagedFile`. **Merged (PR #41).** Also folded in the R7
  `.n8n.json`-literal miss in `NodeWrittenListener`.
- ◑ **R3** `N8nWorkflowBody` codec → all four body-shaping copies (A1 + the `encode*` twins) in one
  unit-tested class (`toCreateBody`/`toUpdateBody`/`encodeReference`/`encodeSync`). **In flight (PR #42).**
- ◑ **R6** mapping memoization (request-scoped cache) + legacy migration moved to a `MigrateMappings`
  repair step — `list()` is now a pure, cached read. **In flight (PR #43, with R7).**
- ◑ **R7** comment hygiene — the stale `Mapping` per-file-override docblock fixed; `Ch2 §14`→`Ch3 §14`
  across `lib/`. (A4/A5/A6 carryover DRY still ☐.) **In flight (PR #43).**
- ☐ **R4** mimetype: uninstall-revert repair-step + single-row restamp (store + perf). *(next; the
  uninstall-revert needs live-pod verification — give it its own focus.)*
- ◑ **R5** SSRF / `allow_local_address`: documented in `SECURITY.md` ("Network egress and local
  addresses") + an N8nClient pointer. **In flight (PR #43).** (No toggle yet — opt-out only if the
  store asks.)
- ☐ **A4 (carryover)** — ◑ **done**: one bounded cursor-walk in `N8nClient` (`paginate` +
  `eachWorkflow`) replaces the workflows/tags loop duplication (PR #43).
- ☐ **R1** event coordinator — **last, incremental, behind the bigger net**, only if still worth it.
- ☐ **B2/B4/B5** Settings naming audit, `dispatch()` rename; B5 (`link`⇄`reference`) gets a written
  *defer* decision.
- ☐ **Prove each step.** Unit suite grows, `behat --dry-run` clean, a human pass on real Nextcloud.
  Net-negative `lib/`, net-positive `tests/`.

Each item is a small, separately-reviewable PR (CONTRIBUTING's anatomy-of-a-change still applies,
minus the `.feature` edit — there is none). Changelog entries are the short "refactor/types/tests"
form. The saga records the *why*; the changelog stays terse.

---

## §4.2 — Epic 2: Branding (functional app → product)

This is the pivot from *what it does* to *what it is*. It can only start once Epic 1 has stopped the
code from moving — branding a moving target wastes the work. Everything the store mechanics (§4.4+)
consume is produced here.

### 4.2.1 — Name & tagline (and an honest look at the name itself)

Kelly asked to scrutinize the name rather than default to `n8n_sync`. Two distinct things, with very
different costs to change:

- **App id (`n8n_sync`)** — this is **effectively locked the moment the CSR is countersigned** (§4.5):
  the certificate binds to the app id, the store URL becomes `/apps/n8n_sync`, and installs key off it.
  Renaming the id post-registration means a new cert and a new listing. **Decide the id before the
  CSR, then never touch it.** Recommendation: keep `n8n_sync` — it's accurate, lowercase-snake per
  convention, and searchable.
- **Display name** — free to change anytime, in `info.xml`'s `<name>`. This is the brainstorm space.

Brainstorm (display name), with the tension named:

| Candidate | Read |
|---|---|
| **n8n Sync** *(current)* | Plain, descriptive, store-searchable for "n8n". Safe. |
| **Workflow Sync for n8n** | Leads with the noun ("Workflow"), reads as a clear integration; "for n8n" is the common community-app framing that signals *unofficial*. |
| **n8n Workflows** | Emphasises the *files* angle (workflows-as-files) over the *sync* mechanic. |
| **Flowfiles / Flowsync** | Brandable, n8n-trademark-free — relevant because saga §8 frames this as a *template* (Grafana next). A generic brand survives the app outliving n8n-specificity; the cost is store discoverability today. |

Two real considerations to resolve, not just pick a favourite:
1. **Trademark.** "n8n" is a third-party mark. The store's only hard rule is *the name must not contain
   "Nextcloud"* — so "n8n Sync" is allowed — but a community app leaning on someone else's mark should
   read as clearly **unofficial** (the "…for n8n" framing does this; Nextcloud's own first-party apps
   use "X integration"). Worth a line in the description disclaiming affiliation.
2. **Template future vs. discoverability now.** A descriptive n8n-name wins search today; a brandable
   name ages better if the Grafana sibling ever ships. Recommendation: **keep the descriptive display
   name now** (discoverability matters more than a hypothetical sibling), revisit only if the template
   actually spawns.

- ☐ **Decide the display name** (recommend "n8n Sync" or "Workflow Sync for n8n").
- ☐ **Lock the app id `n8n_sync`** as a conscious decision *before* the CSR.
- ☐ **Land a one-line `<summary>`** — what it is + who it's for. Current placeholder is fine but
  generic ("Sync n8n workflows to and from Nextcloud as files"); sharpen to a benefit
  (e.g. *"Your n8n workflows as native, editable, backed-up Nextcloud files."*).

### 4.2.2 — The icon system (this is the "we sorta generated something" cleanup)

Audit of what exists today, because the gap is bigger than "make a nicer icon":

| Icon | Today | What's needed |
|---|---|---|
| **App / navigation icon** (`img/app.svg`, `app-dark.svg`) | **MISSING entirely.** | The monochrome white SVG NC shows in the app list + settings sidebar + the store grid. This is *the face of the product* and it doesn't exist yet. **Highest priority.** |
| **Filetype icon** (`img/n8n.svg`) | A homemade "two circles + connector" glyph that deliberately avoids the n8n wordmark. Functional, a bit generic. | Decide: keep the trademark-safe abstract mark, or commit to an n8n-evocative one (with the affiliation disclaimer). Make it legible at 16px in the Files row. |
| **Mode tag pills** (`n8n:sync` / `n8n:link`) | Coloured pills, colours set by NC. | Pick an **accent colour** for the brand and make the pills + icon agree. |
| **In-app action icons** | Inline **generic Material glyphs** hand-pasted into [`src/files.js`](../src/files.js#L154-L234) ("Open in n8n" = a generic external-link/pencil) and [`js/mapping-settings.js`](../js/mapping-settings.js#L165-L198) (info / sync / trash). | These are the "sorta generated" ones. Replace with icons that *mean* their action and match the accent. Centralize them (one icon module) instead of inline string-pasting — a small frontend DRY win that rides along. |

- ☐ **Create `img/app.svg` (+ dark variant)** — the missing product face. Monochrome, white, square.
- ☐ **Choose the accent colour**; apply across app icon, filetype icon, pills.
- ☐ **Revisit `img/n8n.svg`** for 16px legibility (or commit to a new mark).
- ☐ **Replace + centralize the inline action SVGs** so each glyph matches its verb and the brand.

### 4.2.3 — Store copy, screenshots, repo face

- ☐ **Description copy.** Replace the Phase-0 placeholder `<description>` with real, benefit-led copy
  — the rewritten README intro (Modes table, Move/Copy lifecycle) is the source material. Write it for
  a user deciding whether to install. Include the affiliation disclaimer (4.2.1).
- ☐ **Screenshots (3–4 frames telling the story):** Files app showing `.n8n.json` files with the icon
  + mode pills; the admin Settings (mappings + sync); a click opening a workflow in n8n. Each ≤2 MiB,
  served over HTTPS, ≥1 required. (`info.xml` supports a `small-thumbnail` attribute per screenshot —
  see §4.4.)
- ☐ **Repo face.** README header/banner, GitHub social-preview image, About blurb + topics, a
  consistent voice across README ↔ store ↔ changelog.

**Gate:** none of this starts while Epic 1 is in flight. Branding follows the freeze.

---

## §4.3 — Epic 3: Quality stamps (badges)

Kelly wants the README to *show*, at a glance, that this is tested and cared for. Due diligence on
what popular repos actually use, and which badges this repo can legitimately earn:

**The ecosystem-authentic one — REUSE (but it's not free yet).** The official Nextcloud apps
(e.g. `integration_openai`) lead their README with
`[![REUSE status](https://api.reuse.software/badge/github.com/<org>/<repo>)](…)`. It certifies every
file declares its license. **This repo is not REUSE-compliant yet:** 34 files lack an SPDX header —
the `.json` files (which can't carry comments) need a `.reuse/dep5` (or `.license` sidecars), and the
`.feature`/`.yml`/manifest files need a `# SPDX-…` line. So the badge is a *real task*, not a paste:

- ☐ Add SPDX headers to `.feature`, `.yml`, and the manifests (composer.json can't take comments →
  dep5).
- ☐ Add `.reuse/dep5` covering the JSON config + lockfiles + test fixtures.
- ☐ Confirm green via the REUSE API, then add the badge. (This doubles as a real licensing-hygiene win,
  not just decoration.)

**CI status badges — map each to a real workflow** (the repo already has them, so these are honest):

- ☐ **Tests** → `tests.yml` · ☐ **Quality** → `quality.yml` · ☐ **Integration** → `integration.yml`
  (`[![Tests](https://github.com/kubed-io/nextcloud-n8n/actions/workflows/tests.yml/badge.svg)](…)`).

**Static, factual badges (cheap, true):**

- ☐ **License** AGPL-3.0-or-later · ☐ **Nextcloud** 30–33 (mirrors `info.xml` deps) ·
  ☐ **PHP** ≥8.1 (or whatever composer requires) · ☐ once live, the **App Store version**
  badge (`apps.nextcloud.com` exposes one).

**Optional / earn-it:**

- ☐ **Codecov** — `cookbook` uses it; only add if we actually wire coverage upload in CI, else it's a
  vanity badge that'll go stale. Decide; don't fake it.

Rule for this epic: **a badge must reflect something real and green.** A red or perma-stale badge is
worse than no badge. Order them in the README by trust value: REUSE/License → CI status → version.

---

## §4.4 — Epic 4: Store metadata (`info.xml`)

The store validates `info.xml` against an XSD on upload. Current state and the gaps (the branding
assets from §4.2 drop straight in here):

| Field | Current | Action |
|---|---|---|
| `licence` | `agpl` | ✅ correct — the official apps use the **`agpl`** short form in `info.xml`, *not* the SPDX string. (SPDX `AGPL-3.0-or-later` belongs in source headers + root `LICENSE` — already present.) |
| `bugs` | `…/nextcloud-n8n/issues` | ✅ |
| `repository` | present | ✅ |
| `dependencies/nextcloud` | `min 30 / max 33` | ✅ has both bounds (store requires both). |
| `summary` | generic placeholder | Replace with the §4.2.1 one-liner. |
| `description` | **Phase-0 placeholder** ("skeleton only") | Replace with the §4.2.3 store copy. **Blocker for upload.** |
| `screenshot` | **missing** | Add ≥1 HTTPS URL, ≤2 MiB. Use the `small-thumbnail` attribute for a faster grid thumb: `<screenshot small-thumbnail="…/1-small.png">…/1.png</screenshot>`. |

**New due-diligence findings (not in the old draft) — optional polish the store will render:**

- ☐ **`<website>`** — homepage URL, rendered on the app detail page.
- ☐ **`<discussion>`** — forum/discussion URL; if absent it defaults to the NC forum. Point it at our
  GitHub Discussions/issues if we want.
- ☐ **`<category>`** — `integration` is valid and correct; the element *may repeat* if a second
  category fits (e.g. `tools` or `files`). Valid set incl.: `files`, `integration`, `tools`,
  `organization`, `security`, `multimedia`, `social`, `monitoring`, `office`, `customization`.
- ☐ **Localized fields** (optional) — `<name lang="…">`, `<summary lang="…">`, `<description lang="…">`
  support translations; English-only is fine to launch.

Required minimum for acceptance: `id`, `name`, `summary`, real-English `description`, `version`,
`licence` (the `agpl` short form), `author`, `bugs` (URL), and `dependencies/nextcloud` with both
`min-version` and `max-version`. We satisfy all but `description` + `screenshot`.

---

## §4.5 — Epic 5: Signing key, CSR, registration (start this EARLY)

The store requires every release to be cryptographically signed, and the Nextcloud team must
**countersign your certificate** before you can register the app. This is the chapter's only external
dependency — kick it off the moment the app id is locked (§4.2.1), so the few-days wait overlaps the
polish work.

### Step 1 — Generate the key + CSR

```sh
mkdir -p ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 \
  -keyout ~/.nextcloud/certificates/n8n_sync.key \
  -out ~/.nextcloud/certificates/n8n_sync.csr \
  -subj "/CN=n8n_sync"
```

Open a PR to [github.com/nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
adding `n8n_sync.csr`, with a link to the public source repo. The team signs and returns
`n8n_sync.crt`. **Keep `n8n_sync.key` secret; never commit it.** (Follow their directory/naming
structure exactly — their tooling asserts it.)

### Step 2 — Register the app id

Once you have `n8n_sync.crt`, at [apps.nextcloud.com](https://apps.nextcloud.com) (REST API or the
web "register app" form), provide:

- the certificate contents (`cat ~/.nextcloud/certificates/n8n_sync.crt`), and
- an ownership signature over the app id:
  ```sh
  echo -n "n8n_sync" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/n8n_sync.key | openssl base64
  ```

A one-time claim that proves you hold the private key for app id `n8n_sync`. After this you can publish
releases.

---

## §4.6 — Epic 6: What the pipeline needs added (Chapter 2 → Chapter 4 delta)

Chapter 2's `publish.yml` produces a tarball. To reach the store it needs two new steps:

### 1. Generate `appinfo/signature.json` (inside the tarball)

The tarball must contain a `signature.json` in `appinfo/` covering every file, produced by Nextcloud's
own `occ` against an extracted copy:

```sh
php occ integrity:sign-app \
  --privateKey=~/.nextcloud/certificates/n8n_sync.key \
  --certificate=~/.nextcloud/certificates/n8n_sync.crt \
  --path=/path/to/extracted/n8n_sync
```

Insert it **between Build and Package**. Requires:
- the NC app private key as a GitHub secret (`NC_APP_PRIVATE_KEY`), written to a temp file at runtime;
- the certificate committed to the repo (not secret) or fetched from a secret;
- either a temporary NC install in CI to run `occ`, **or** a standalone signing script (the
  `integrity:sign-app` logic is simple enough to replicate).

### 2. Sign the tarball + upload to the store

After GitHub creates the release, sign the archive and POST it:

```sh
# tarball signature (separate from the in-tarball signature.json)
openssl dgst -sha512 -sign ~/.nextcloud/certificates/n8n_sync.key \
  dist/n8n_sync-X.Y.Z.tar.gz | openssl base64

curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
  -H "Authorization: Token <store-api-token>" \
  -H "Content-Type: application/json" \
  -d '{
    "download": "https://github.com/<repo>/releases/download/vX.Y.Z/n8n_sync-X.Y.Z.tar.gz",
    "signature": "<base64 tarball signature>",
    "nightly": false
  }'
```

The store **downloads and validates the tarball itself** — the GitHub release asset URL must be
publicly reachable (it is). Requires a store API token as a GitHub secret.

---

## §4.7 — Store rules checklist (must be true or it's rejected/removed)

- **License:** AGPL-3.0-or-later (or compatible). ✅ — already true.
- **Name:** must not contain "Nextcloud". ✅ — "n8n Sync" is fine.
- **APIs:** public Nextcloud APIs only, no private/internal classes. ⚠️ — we *do* reach into Sabre /
  `OCA\DAV\*` for the `LinkWriteGuardPlugin` (§15.1) and `OC::$SERVERROOT`/`GenerateMimetypeFileBuilder`
  in `RegisterMimetype`. These are the supported integration points for those jobs, but **flag them**
  for the review — they're the most likely "is this a public API?" question from the store auditors.
  Have the saga §15.1 / §7.1 justification ready.
- **Uninstall:** must clean up completely (no leftover DB tables, config, files). ⚠️ — we *write into
  core* (`core/img/filetypes/n8n.svg`, `core/js/mimetypelist.js`, `config/mimetype*.json`,
  filecache mimetype rows). Verify disable/remove leaves a clean system, or document why the mimetype
  registration is benign/idempotent. **Worth an explicit test before submission.**
- **Performance:** must not crash NC or degrade unrelated features.
- **Contact:** bug-tracker URL in `<bugs>`. ✅.
- **Security:** NC may audit unannounced; malicious intent = 2-year ban.
- **CHANGELOG.md:** Keep-a-Changelog format with `## X.Y.Z` headers matching `info.xml` version. ✅
  — already maintained.

> Two items here (**public-API usage** and **clean uninstall**) are the real review risks for *this*
> app specifically, because of the mimetype/Sabre integration. They aren't blockers, but they deserve
> a deliberate pre-submission pass — don't let the first store rejection be the thing that surfaces them.

---

## §4.8 — The finale: first store release (may not happen this chapter)

```
Lock app id ──▶ Generate key + CSR PR ──▶ (Nextcloud team countersigns — the wait)
                                                      │
   Epic 1 (refactor) ─┐                              ▼
   Epic 2 (branding) ─┼─▶ assets ready ──▶ Register app id
   Epic 3 (badges)   ─┘                              │
                          fix info.xml (§4.4) ───────┤
                          add sign+upload (§4.6) ────┤
                                                      ▼
                                            First store release 🎬
```

The CSR countersign is the only thing we wait on; everything else is ours to pace. If the chapter ends
with a polished, branded, badged, store-*ready* app whose CSR is still in flight — **that's a complete
chapter.** The marquee can light up in Chapter 5.

---

Sources:
- [Nextcloud app store rules / publishing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html)
- [App Developer Guide — Nextcloud AppStore](https://nextcloudappstore.readthedocs.io/en/latest/developer.html) (CSR, register, upload, REST API)
- [App metadata / info.xml](https://docs.nextcloud.com/server/latest/developer_manual/app_development/info.html) (screenshot `small-thumbnail`, `website`, `discussion`, categories, localization)
- [Code signing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html)
- [REUSE](https://reuse.software/) — the `api.reuse.software/badge/...` badge the official NC apps carry.
- Code reviewed at chapter open: `lib/` (≈7.3k LOC), `src/`, `js/` — findings in the Code Review section above.
</content>
</invoke>
