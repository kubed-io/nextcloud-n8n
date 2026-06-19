# Nextcloud ↔ n8n (and later Grafana) — Native Plugin Plan

> Status: **Phase 0–5 working in production.** Everything below in §1–§13 is the
> original design narrative; **§15 is the authoritative current-state record** —
> read it first, then §14 (manual sync) and §16 (still-pending). Quick summary of
> what's live: admin section redesigned into **Instance / REST API / Webhook /
> Writeback-timing** cards + a **Test Connection** card (Test API + Test webhook);
> Phase 2 metadata (`n8n_id`, `n8n_mode`, `n8n_versionId`, `n8n_writeback`,
> `n8n_syncedHash`, and the **indexed `n8n_mapping`**); Phase 3 pull (folder-scoped,
> mapping-id-aware, subfolder-capable); Phase 4 **dual-channel writeback** (REST API
> and/or webhook, independent toggles, loop guard, n8n errors → notifications);
> **Phase 5 done** — custom mimetype/icon, **row-click "Open in n8n"** (the §11/§12
> "row downloads" blocker was the bundled `@nextcloud/files` being v3 vs NC 33's v4;
> fixed by upgrading), **right-click "Edit as text"** (plain source editor modal),
> and a **move-out veto** keeping managed files in their mapping folder. Manual sync
> is now **async-only for "all", inline for one mapping** (§14). The mappings UI is a
> **card form** with colored icon buttons + per-mapping Sync + sticky per-card status.
>
> § note: §11 ("active blocker") and §12 ("row click downloads") are **RESOLVED**
> (see §15). §13 is the crash-loop postmortem (still relevant ops guidance).
>
> Naming drift to be aware of: §1/§5 still describe Axis 1 as `link|sync`,
> but the implemented metadata key is **`n8n_mode = reference|sync`**. The
> semantics are identical; treat `reference` as a synonym for `link`
> wherever the doc still says "link". This is the only field rename
> between spec and code.
>
> n8n-first, explicitly the **template** for an identical Grafana plugin
> later. Forks stay open with pros/cons so the implementor can back out of
> dead ends.

## 0. What this is (and what it is NOT)

This is **not** a generic file plugin and **not** external storage. We are **mapping an API (n8n)
whose resources are JSON into Nextcloud files**, and wrapping them in a **native-feeling UI**:
a real **icon** and a **click that opens the workflow in n8n** — whether the file is a *link-only*
stub or a *fully synced* JSON.

**Decision (locked):** External Storage custom backend (`files_external` / `OCP\Files\Storage`) is
**rejected**. It's a live filesystem projection with no backup benefit and the most fragile code —
wrong tool for "API ⇆ JSON files".

## 1. Ownership split (the key architectural decision)

The work divides into **what the plugin owns** vs **what the user owns**. This split is what keeps
the plugin small and "leaves room for improvement."

### The plugin owns
1. **Metadata contract** — registers the WebDAV/Files-Metadata keys so they're writable + indexed
   (`source`, `mode` = `link|sync`, `writeback` = `two-way|readonly` *(sync only)*, `n8n_id`,
   optional `instance`).
2. **Admin settings (UI)** — n8n base URL, **n8n API key** (stored encrypted), optional post-save
   **webhook path**, defaults, and **"Sync now → n8n" / "Sync now ← n8n"** buttons.
3. **Nextcloud → n8n writeback** — on file save, immediately push to n8n (native feel).
4. **Bulk sync** both directions (the two admin buttons): the n8n REST client + idempotent reconcile.
5. **The UI** — custom icon for n8n JSON + an **"Open in n8n"** file action (deep link), for both
   link-only and synced files.

### The user owns (NOT our code)
- **Incremental n8n → Nextcloud** is the user's **own n8n workflow**, triggered by n8n's internal
  workflow events. In that workflow Nextcloud is just **one branch** (send this to FTP, that to
  Nextcloud, …). The branch's job is simply: **PROPPATCH the right metadata + PUT the JSON as a
  file** over WebDAV. The plugin only has to *provide the metadata keys* and *react to them in the
  UI*. (The plugin's "Sync now ← n8n" button is the bulk/fallback equivalent for first-seed and
  drift repair — it is not the steady-state path.)

This is the crux: **steady-state outbound is config-free for us**; we provide the contract, the
user wires their own n8n flow to it.

## 2. Verified environment (live cluster — do not re-discover)

Checked on the running pod (ns `cloud`):
- **Nextcloud 33.0.4** → frontend uses `@nextcloud/files` **v4.x** (ESM-only; breaks across NC majors).
- Installed & enabled: `webhook_listeners 1.5.0`, `workflowengine` (Flow) `2.15.0`,
  `files_external 1.25.1`, `systemtags 1.23.0`, `activity 6.0.0`.
- This repo installs NC apps **at runtime via `occ`** on the PVC (`apps/nextcloud/README.md`), and
  already injects config through per-component `*-config.sh` + helm. **We reuse that pattern** for
  app enable + admin-setting injection (§7).

## 3. Plugin internals — mechanisms to bank on (popular NC patterns)

Reference app studied for patterns: `integration_openai` (modern `IBootstrap` bootstrap,
`OCP\Util::addInitScript`, vite + `@nextcloud/vue`, sensitive AppConfig). Viewer/file-action
patterns from `nextcloud/viewer` (`registerFileAction`, `OCA.Viewer.registerHandler`).

| Concern | Mechanism | Notes / caveats |
|---|---|---|
| App bootstrap | `Application extends App implements IBootstrap` | `register()` wires listeners/settings; `boot()` runs `initMetadata()`. |
| Metadata keys | **Files Metadata API** `IFilesMetadataManager::initMetadata()` | Register `n8n_id`/`mode`/`source` with `EDIT_REQ_OWNERSHIP` so the user's n8n flow can PROPPATCH them; mark indexed for SEARCH. |
| Save event (NC→n8n) | `OCP\Files\Events\Node\NodeWrittenEvent` listener | Fires for text-editor saves **and** WebDAV PUTs. Sync-vs-async fork in §5-C. |
| Admin settings page | `IDelegatedSettings` / `ISettings` + Vue panel | Same shape as `integration_openai` admin panel. |
| Secret storage | **AppConfig `sensitive` flag** (encrypted, hidden from reports) | Settable via UI **or** `occ config:app:set` → helm injection (§7). |
| n8n REST client | `IClientService` (server-side HTTP) | Used by writeback + both bulk-sync buttons. |
| Custom icon | mimetype mapping + `occ maintenance:mimetype:update-db`/`update-js` | **No clean per-app mimetype API yet** (server#10131). Done via config files + occ → fits our lifecycle. Frontend-only fallback in §5-D. |
| "Open in n8n" action | `@nextcloud/files` `registerFileAction` (loaded via `addInitScript`) | Builds deep link from the n8n id; id-source fork in §5-D. |
| Raw JSON view | existing **Text** editor already handles `.json` | Don't build a viewer unless there's a real need. |
| Bulk/async work | `IJobList` background jobs | For "Sync now" and (optionally) async writeback. |

## 4. The two directions, concretely

```
 n8n  ──(user's own n8n workflow: PROPPATCH metadata + PUT json)──▶  Nextcloud   (steady state, not our code)
 n8n  ◀─────────────(plugin: NodeWrittenEvent → n8n REST/webhook)──  Nextcloud   (our code, immediate)
 n8n  ◀──(admin "Sync now → n8n")──  Nextcloud   |   n8n ──(admin "Sync now ← n8n")──▶ Nextcloud   (our code, manual bulk)
```

- **Outbound steady state** = user's n8n flow (branch to NC, FTP, …). Plugin just exposes the
  metadata contract.
- **Inbound (NC→n8n)** = plugin listener on save → push immediately. Two push modes (§5-B).
- **Bulk both ways** = the two admin buttons (first-seed + drift repair).

## 5. The forks (decide per area; pros/cons = the escape hatches)

### Fork A — Representation: `link` vs `sync` (does NC store content?)
**Axis 1.** A **folder/mapping sets the default**; an **individual workflow's own tag overrides** it
(per-file precedence). Both get the same icon + "Open in n8n":
- **A1. `sync`**: real `.json` stored in NC → a backup of record; eligible for the writeback axis
  (Fork F).
- **A2. `link`**: **no content stored** — a stub the UI renders as a pointer to n8n. **Read-only by
  nature** (nothing to edit/push), so writeback is N/A. For things backed up elsewhere (e.g.
  operator/marketplace Grafana dashboards).
- **Precedence:** workflow self-tag (`mode:link` / `mode:sync`) → mapping default. *Example:* a
  folder defaulting to `link` still syncs the one workflow tagged `sync`; everything untagged stays
  `link`.
- ➖ `readonly` is **not** a peer here — it's a property of `sync` (Fork F). You must `sync` for
  read-only to mean anything.

### Fork B — Writeback push mode (NC → n8n)
- **B1. Direct n8n REST API** *(by id, using stored API key)*: plugin updates the workflow itself.
  - ➕ Truly native ("save here = saved in n8n"); no user wiring. ➖ Plugin must track id mapping + handle n8n schema.
- **B2. POST to a configured n8n webhook path** (host + `/webhook/foo`): hand the JSON to the user's
  n8n workflow and let *it* decide.
  - ➕ Matches "user owns the n8n side"; flexible branching in n8n. ➖ Needs the user to build the receiving workflow.
- **B3. Offer both** *(recommended)*: admin picks per instance; webhook path empty ⇒ use REST.

### Fork C — Writeback timing (the "immediate, native feel")
The plugin only ever does one of two things, and **assumes no special infra**:
- **C1. Synchronous in the listener** (short timeout + retry-job on failure): most immediate, pure
  plugin, nothing extra to operate.
  - ➖ A slow/down n8n can delay the user's save; must cap timeout and fail soft.
- **C2. Enqueue an `IJobList` job** *(recommended default)* and let the background queue drain it.
  **Who drains the queue is an infra choice the plugin is agnostic to** — identical plugin code
  either way:
  - **C2-cron** *(default · zero infra)*: Nextcloud's **normal background-job cron** runs it — every
    NC install needs this cron anyway, and here it's the k8s CronJob you already run. Latency ≈ cron
    interval (tighten the cadence to taste).
  - **C2-worker** *(optional operator upgrade)*: run the **built-in** `occ background-job:worker`
    continuously. In k8s that's a tiny Deployment using the **same Nextcloud image** with
    `occ background-job:worker` as its `command:` — **not a custom daemon, not a separate codebase**;
    it lives alongside the rest of `apps/nextcloud`. It drains the queue → near-real-time. The plugin
    **doesn't require it** and it's a drop-in with **no plugin changes** (same queue, emptied faster).
- **C3. `webhook_listeners` instead of our listener**: zero plugin code for the event, but batchy and
  less control — a fallback, not the native path.

> Key point: the plugin's job is to **enqueue**; cron-vs-worker is purely *who empties the queue*. Ship
> on C2-cron now, add a worker sidecar later for lower latency — **without touching the plugin.**

### Fork D — Where the n8n id (for the deep link + icon) comes from
- **D1. Encode id in filename/path** (e.g. `myapp/Name.<n8nid>.n8n.json`) *(most robust)*.
  - ➕ File action builds the link with **zero extra lookup**; immune to the metadata-PROPFIND bugs
    (server#53155 / #50302). ➖ Renames must preserve the id segment.
- **D2. Read `nc:metadata-n8n_id` client-side**: cleanest model, but **currently buggy** for shared
  files / SEARCH — verify on NC33 before relying on it.
- **D3. Tiny plugin OCS endpoint** `fileid → n8n_id`: reliable, but an extra round-trip per action.
- Icon source mirrors this: **D-icon-1** real mimetype (config + occ) for a true list-row icon, or
  **D-icon-2** frontend-only badge/icon via the file action + extension match (no mimetype, lighter,
  but not the native row glyph).

### Fork E — Metadata storage backing the contract
- **E1. Files Metadata API** *(recommended)*: typed, indexed, WebDAV `PROPPATCH`/`SEARCH`; needs the
  plugin's `initMetadata()` (we're building the plugin anyway, so ~free).
- **E2. System tags** (`systemtags`): zero extra code, great for coarse flags (`source:n8n`,
  `mode:link`), already webhook-able. Good complement to E1 for human-facing filtering.
- **E3. Filename convention** for the id (pairs with D1) + tags for the rest. Pragmatic hybrid.

### Fork F — Writeback (only under `sync`): `two-way` vs `readonly`
**Axis 2 — only meaningful when a resource is `sync`** (`link` is read-only by nature, so writeback
is N/A). A mapping that defaults to `sync` **must** declare its writeback (no global default); a file
may override per-file:
- **F1. `two-way`**: NC edits push back to n8n (Forks B/C).
- **F2. `readonly`**: backup mirror — NC edits **never** push (optionally enforce read-only in NC).
- **Precedence:** per-file `writeback` metadata (optional) → mapping `writeback` (required for sync).
- **UI affordance:** render writeback as a single **read-only checkbox** (checked = `readonly`,
  unchecked = `two-way`). Choosing `link` **force-checks and greys it out** — visually proving *link
  is always read-only*, no prose needed.
- The writeback listener (Phase 4) acts **only** on files that resolve to `sync` + `two-way`;
  `readonly` and `link` never push (no writeback ⇒ no loop). **Both axes + their precedence must be
  documented in the addon README** (see §9).

> The two axes compose into three effective end-states — `link`, `sync · two-way`,
> `sync · read-only` — but they are **not flat peers**: `link/sync` is representation (Axis 1),
> `two-way/read-only` is a sub-property of `sync` (Axis 2).

### Fork G — Folder mapping & path relativity
**Path model (make this concrete, not abstract):** from n8n's perspective every workflow lives at
its own root `/` or a folder beneath it. A **mapping translates between n8n's relative root and an
absolute Nextcloud path**, in both directions:
- Map n8n `/` → NC `/` ⇒ **overlay**: n8n folders land directly on the NC top level; a new workflow
  shows up in place.
- Map n8n `/` → NC `/n8n` ⇒ namespaced: a workflow created in NC `/n8n/foo` is **n8n-relative
  `/foo`**, i.e. it appears at `/` (under `foo`) from n8n's side. The `/n8n` prefix is stripped on
  the way out and re-added on the way in.
- Map n8n `/tester` → NC `/projects/tester/n8n-fun` ⇒ a specific subtree binds to an arbitrary NC
  location; paths translate by swapping the mapped prefixes.

Each mapping declares its **default representation** (`link`/`sync`, Fork A) and, when `sync`, its
**writeback** (`two-way`/`readonly`, Fork F). Options:
- **G1. Single mapping** *(simplest)*: one entry, e.g. n8n `/` → NC `/n8n` · `sync › two-way`.
- **G2. Mapping list** *(recommended)*: an ordered list of `{ n8n path → NC path, mode[, writeback] }`
  entries; G1 is the one-entry case. A catch-all `/ → /n8n` handles "everything else"; specific
  entries (e.g. `/tester → /projects/tester/n8n-fun`) win by longest-prefix match.

**Binding metadata on the bound folder (the nice WebDAV part):** the plugin **stamps each mapped NC
folder** with the mapping facts — n8n-relative target + default `mode` (+ `writeback` when `sync`) —
as folder **metadata** (and/or a `n8n-bound` **system tag**). A WebDAV PROPFIND on a bound folder is
then **self-describing**: the user's n8n flow reads the binding + defaults straight off the folder.
Admin-config mappings are the **source of truth**; folder stamps are the **discoverable projection**;
**per-file workflow tags override the folder default** (Fork A/F precedence).

**Relativity guard (creating in the right place):** restrict n8n file creation/sync to **bound
folders only** — a file under no mapped folder is ignored (soft guard in the save listener) and,
optionally, **rejected at creation** (hard guard via `BeforeNodeCreatedEvent`) so a stray
`*.n8n.json` can't be made in an unmapped folder. The "New → n8n workflow" menu entry only appears
inside bound folders. This removes the ambiguity of "which n8n root does this path even mean?"

## 6. Nextcloud Flow (workflowengine) — answering "can we configure it from code?"

- **You've not used it; with this design you mostly don't need it.** The plugin's own
  `NodeWrittenEvent` listener does what a Flow "on file change → webhook" rule would, with more
  control (your API key, your webhook path, immediate timing).
- **Can it be configured programmatically? Yes:**
  - Flow rules are created via its **OCS API** (`/ocs/.../apps/workflowengine/api/v1/workflows/...`).
    There is **no dedicated `occ`** command to create a configured flow, but the OCS call can be
    scripted from our lifecycle just like other setup.
  - An app can also **register its own `IOperation` + `ICheck`** in PHP so custom steps/conditions
    appear in the Flow UI — that's the code path if you ever want admins to build no-code rules
    against n8n events.
- **Recommendation:** skip Flow on the critical path; revisit only if you want user-built no-code
  rules in the NC admin UI later. (You said you're fine with code doing what the UI could — so our
  listener is the better fit than a scripted Flow rule.)

## 7. Packaging + config injection in this repo

This repo enables apps via `occ` and injects config via `*-config.sh` + helm (not Dockerfile).
- **Ship the app:** mount the app dir into the container's custom-apps path (source under
  `apps/nextcloud/…`) and `occ app:enable` — most aligned with the runtime-install pattern; no
  image rebuild. (Release-tarball or Dockerfile-bake remain options if needed, but they fight the
  current model.)
- **Inject admin settings without the UI:** `occ config:app:set <appid> n8n_url …`,
  `… api_key … --sensitive` (or set sensitive in code) from a new `components/n8n-config.sh`,
  exactly like the ldap/keycloak components. So the admin page and helm injection are two faces of
  the **same AppConfig values** — set it in the UI by hand, or bake it via lifecycle. Both supported.

### 7.1 Custom mimetype/icon — devops step, not an admin button

`occ maintenance:mimetype:update-db` / `update-js` are **server CLI commands**, *not* a Files-UI
button. On a **base Nextcloud install**, giving `.n8n.json` its own icon is a server/devops action:
(1) extension→mimetype in `config/mimetypemapping.json`, (2) alias→icon in
`config/mimetypealiases.json`, (3) ship the SVG, (4) `occ maintenance:mimetype:update-db`
(re-scans cached file mimetypes) + `update-js` (regenerates the frontend map). There is **no
built-in admin click** for this — it needs `occ` + `config/` access.

For this repo, **no human runs it** — fold it into the app's deploy/enable lifecycle. Options:
- **App Repair step** that runs the mimetype update on install/upgrade *(recommended — invisible)*.
- Optional **"Re-apply icon mapping"** button in admin settings (controller triggers it) — convenience
  only; running `occ maintenance:*` from a web request is awkward.
- **Fallback:** frontend-only icon (no mimetype) — but that styles the actions menu, **not** the true
  file-list row glyph, so it's a weaker native feel.

### 7.2 Admin settings — ASCII mockup (consolidated end-state)

Connection + defaults land in **Phase 1**; the writeback options arrive with **Phase 4**, the manual
sync buttons with **Phase 3/4**, and the icon-status row with **Phase 5**. Shown together here:

```
╔══════════════════════════════════════════════════════════════════════════╗
║  Administration  ›  n8n Integration                                       ║
╠══════════════════════════════════════════════════════════════════════════╣
║                                                                          ║
║  Connection                                                              ║
║  ──────────────────────────────────────────────────────────────────     ║
║  n8n base URL   [ https://n8n.kellyferrone.com________________ ]         ║
║  API key        [ ••••••••••••••••••••••••••• ] 👁  (stored encrypted)   ║
║                 [ Test connection ]   ✔ Connected — n8n 1.6x · 42 wf     ║
║                                                                          ║
║  Writeback (applies to  sync › two-way  only)                            ║
║  ──────────────────────────────────────────────────────────────────     ║
║  Push mode  ( •) Direct REST — update workflow by id                     ║
║             ( ) POST to webhook path                                     ║
║  Webhook path  [ /webhook/foo____________________ ]  (if selected)      ║
║  Timing     ( •) Immediate (sync · 5s timeout)                          ║
║             ( ) Background — enqueue (drained by NC cron)                ║
║                   ⓘ cron = no extra infra; an optional same-image        ║
║                     worker sidecar drains faster (no plugin change)      ║
║                                                                          ║
║  Folder mappings   ( n8n path → Nextcloud path · type )                  ║
║  ──────────────────────────────────────────────────────────────────     ║
║   /         →  /n8n                      sync › two-way   [edit][✕]      ║
║   /backup   →  /n8n-archive              sync › read-only [edit][✕]      ║
║   /grafana  →  /dashboards               link             [edit][✕]      ║
║   [ + Add mapping ]                                                      ║
║   ⓘ Folder sets the default type; a workflow's own tag overrides it      ║
║     (a 'link' folder still syncs a workflow tagged sync).               ║
║   ⓘ read-only only applies under sync; link is read-only by nature.      ║
║  Guard  [✓] Only allow n8n files inside mapped (bound) folders           ║
║                                                                          ║
║  Manual sync                                                             ║
║  ──────────────────────────────────────────────────────────────────     ║
║  [ ⭳ Sync now ← n8n ]   last: 2026-06-15 21:40 · 42 pulled · 0 errors   ║
║  [ ⭱ Sync now → n8n ]   last: never      ( sync › two-way only )         ║
║                                                                          ║
║  File type & icon                                                        ║
║  ──────────────────────────────────────────────────────────────────     ║
║  .n8n.json icon   ✔ Registered (applied at deploy)                       ║
║     ⓘ Requires server `occ` — applied automatically by the deploy        ║
║       lifecycle, not a per-click admin action on a base install.         ║
║  [ Re-apply icon mapping ]   (optional convenience; runs occ)           ║
║                                                                          ║
║                                              [ Cancel ]    [ Save ]      ║
╚══════════════════════════════════════════════════════════════════════════╝
```

Controls map to the forks: each mapping's **`type`** = representation `link`/`sync` (Axis 1, Fork A)
plus, under `sync`, **writeback** `two-way`/`read-only` (Axis 2, Fork F) — shown as the nested
`sync › two-way` token; a **workflow's own tag overrides its folder's default** (no global default).
**Push mode** = Fork B, **Timing** = Fork C (the Background option is C2 — Nextcloud's normal cron,
i.e. your existing CronJob), **Folder mappings + Guard** = Fork G. Each mapping translates an n8n-relative path to an
absolute NC path (`/ → /` overlays). Every field is an AppConfig value → equally settable via
`occ`/helm (§7); bound folders are additionally **self-described via WebDAV metadata** (Fork G).

### 7.3 Add / Edit mapping dialog — the read-only checkbox trick

What `[+ Add mapping]` / `[edit]` opens. Writeback is a **single checkbox**; picking `link` forces it
checked and disabled, so the "link is always read-only" rule is shown, not told:

```
  Type = sync  →  checkbox is live              Type = link  →  checkbox forced + greyed
 ┌─ Edit mapping ───────────────────────┐      ┌─ Edit mapping ───────────────────────┐
 │  n8n path        [ /backup_________ ] │      │  n8n path        [ /grafana________ ] │
 │  Nextcloud path  [ /n8n-archive____ ] │      │  Nextcloud path  [ /dashboards_____ ] │
 │  Type      ( ) link    ( •) sync      │      │  Type      ( •) link    ( ) sync      │
 │  [✓] Read-only  (uncheck = two-way)   │      │  [✓] Read-only  ░always — link is░    │
 │                                       │      │                 ░read-only by nature░ │
 │              [ Cancel ]  [ Save ]     │      │              [ Cancel ]  [ Save ]     │
 └───────────────────────────────────────┘      └───────────────────────────────────────┘
```

Mapping to data: **Type** = `mode` (`link|sync`), **Read-only checkbox** = `writeback` (checked ⇒
`readonly`, unchecked ⇒ `two-way`); under `link`, `writeback` is irrelevant and the box is pinned on.

## 8. n8n vs Grafana (why this is a template, not a one-off)

Keep a thin **per-source adapter** (`list/read/upsert/deeplink/routing/backupPolicy`) so Grafana is
a second adapter + second NC root folder, sharing the metadata vocabulary and reconcile core:
- **n8n:** workflows REST API; `active` state, tags, projects/folders; no native "on-save webhook"
  → steady-state outbound is the user's n8n flow; writeback = update workflow by id.
- **Grafana:** dashboards API + folders + provisioning/versioning; some dashboards are
  operator/marketplace-loaded → naturally `mode:link` (never written back). Deep-link + folder model
  differ. Same plugin shape, different adapter.

## 9. Risks / open questions

- **Writeback loops:** plugin save→n8n must not bounce back as the user-flow's n8n→NC write. Guard
  with an origin marker / content hash / etag before enabling `sync · two-way`. **`sync · read-only`
  and `link` sidestep this entirely — they never push.**
- **Sync-vs-async timing (Fork C):** the plugin only enqueues (C2) or pushes synchronously (C1),
  assuming just a normal Nextcloud cron. Near-real-time is an **optional** upgrade — run the built-in
  `occ background-job:worker` as a **same-image** sidecar (no custom daemon, no plugin change); the
  plugin is agnostic to who drains the queue.
- **Metadata client-side reliability (Fork D):** prefer filename-encoded id until #53155/#50302 are
  confirmed fixed on NC33.
- **Mimetype API gap:** per-app icon needs config + occ (server#10131 still open). Frontend-only
  icon is the fallback if we don't want to touch root config.
- **Frontend API churn:** `@nextcloud/files` v4 is NC33-only — the UI is the part that will need
  rework across NC majors. Keep the action/icon code thin.
- **README must document (addon README):** the **source-based writeback-loop guard**; the **two-axis
  model** — representation `link`/`sync` (Axis 1) and writeback `two-way`/`read-only` *under sync only*
  (Axis 2), with **folder default + per-workflow-tag override, no global default** (Forks A/F); the
  **folder-mapping / path-relativity model + bound-folder guard** (Fork G — including how `/n8n/foo` ⇄
  n8n `/foo`); the **WebDAV metadata contract** the user's n8n flow writes to (file *and*
  folder-binding metadata); and the **sharing model** (below). These are the non-obvious
  safety/contract bits.

### 9.1 Sharing — lowest priority, owned by n8n (not the plugin)
Sharing a synced file to other NC users should **not** be the plugin trying to replicate it into
each user's n8n account. Instead, **the plugin just exposes/forwards the facts** — the file's
`n8n_id` plus the NC share target (who it was shared with) — and **an n8n workflow decides what
"share" means** (copy into the target's account, grant API access, ignore, etc.). Mechanically this
is the same ownership split as everything else: NC emits a **share event**
(`OCP\Share\Events\ShareCreatedEvent`, via our listener or `webhook_listeners`) → n8n reacts.
- **Priority: lowest (defer to Phase 6).** Identity mapping (NC user ⇄ n8n account/owner) is the
  tricky part and is explicitly **out of scope** until the core loop is solid. Don't let it shape
  earlier phases.

## 10. Phased delivery (effort scopes — riskiest last)

Each phase is independently shippable and ends on a concrete exit criterion. Risk rises toward the
end; the churn-prone frontend is deliberately **last**, after there's real data to render against.

### Phase 0 — Skeleton + wiring *(risk: low — "the install path works")* — ✅ **DONE**
- **Goal:** an empty-but-real app that installs, enables, and loads cleanly in this repo's lifecycle.
- **Scope:** `appinfo/info.xml` (id, NC 33 dep, namespace); `Application` implementing `IBootstrap`
  with empty `register()`/`boot()`; composer autoload stub. Repo wiring: app dir under
  `apps/nextcloud/…`, mounted into the custom-apps path; idempotent `occ app:enable` in a
  `components/n8n-…sh` lifecycle hook.
- **Exit:** `occ app:list` shows it enabled; no log errors; survives a pod restart (PVC durability).
- **Decides:** packaging fork → **mount** (not tarball/Dockerfile). **Depends on:** nothing.
- **Done:** lives at `apps/nextcloud/custom-apps/n8n_sync/` (PVC-backed `custom_apps`); enables
  cleanly; verified across rolling restarts.

### Phase 1 — Admin settings + connection test *(risk: low — "configure before use")* — ✅ **DONE**
- **Goal:** a configurable n8n target before any sync logic exists.
- **Scope:** AppConfig values `n8n_url`, `api_key` (**sensitive/encrypted**), `webhook_path`
  (optional), **`mappings` (ordered list of `{n8n path → NC path, mode[, writeback]}` — `mode`
  (`link|sync`) is the folder default (Fork A); `writeback` (`two-way|readonly`) **required when
  `sync`** (Fork F); workflow tags override per file; no global default; seed one entry
  `/ → /n8n · sync › two-way`)**, **`guard_bound_only` (bool, default on)**. Admin page
  (`IDelegatedSettings` + small Vue panel) to view/set them;
  **"Test connection"** button hitting the n8n REST API. occ/helm parity
  (`occ config:app:set … --sensitive`) so values can be baked by lifecycle.
- **Exit:** set via UI *and* via occ; Test connection passes against live n8n; key hidden in reports.
- **Option:** ship config-via-occ first, add the Vue panel after, if you want to defer even this bit
  of frontend. **Depends on:** P0.
- **Done:**
  - **Custom sidebar section** — `Settings\AdminSection` (`IIconSection`) registered via
    `info.xml` `<admin-section>`; uses core `categories/workflow.svg` icon.
  - **Connection panel (priority 10)** — `Settings\AdminSettings` (`IDeclarativeSettingsForm`,
    `STORAGE_TYPE_INTERNAL`) with fields `n8n_url` (URL) and `api_key` (PASSWORD,
    `sensitive: true` → `ICrypto`-encrypted). Form id is **unprefixed** (`connection`) — the
    settings frontend strips a leading `<app>_` before save, so prefixed ids fail backend
    lookup and quietly store secrets unencrypted.
  - **"Test connection" panel (priority 15)** — classic `Settings\AdminTest`
    (`IDelegatedSettings`) registered via `info.xml` `<admin>`; renders the button + msg span
    via the `admin_test` template wrapped in `<div class="section">` to align with the other
    panels. JS + CSS loaded with `Util::addScript()` / `addStyle()` so they pick up the strict
    CSP nonce. Status uses NC's native `msg success` / `msg error` classes.
    `Controller\ConfigController::testConnection()` decrypts the stored key, calls
    `GET <n8n_url>/api/v1/workflows?limit=1` with `X-N8N-API-KEY` via `IClientService`
    (`allow_local_address: true` for in-cluster URLs); admin-gated by
    `#[AuthorizedAdminSetting(settings: AdminTest::class)]` (no manual `isAdmin()` check).
    Returns `{status: ok|error, message}` and maps 401/403/404 to friendly errors.
  - **Writeback panel (priority 20)** — `Settings\WritebackSettings` declarative form with
    `push_mode` RADIO (`api` / `webhook`, default `api`), **`webhook_path`** TEXT
    (used only when push mode = webhook), and `timing` RADIO (`sync` / `async`, default `sync`).
  - **Folder mappings panel (priority 30)** — classic `Settings\MappingSettings`
    (`IDelegatedSettings`); `Service\Mapping` value object enforces `link ⇒ writeback null`
    and `sync ⇒ writeback in {two-way, readonly}`; `Service\MappingService` persists the list
    as a single JSON AppConfig key (`mappings`) with longest-prefix `resolveForPath()` ready
    for the writeback listener; `Controller\MappingController` exposes
    `GET/POST/PUT/DELETE /apps/n8n_sync/mappings[/{id}]` (admin-gated). The template
    server-renders rows + uses the **read-only checkbox trick** from §7.3: switching mode
    to `link` force-checks and disables the Read-only checkbox; vanilla JS does add/edit/delete
    via REST.
  - **Guard panel (priority 32)** — `Settings\GuardSettings` declarative form with one
    CHECKBOX `guard_bound_only` (default ON). Read by the writeback listener (Phase 4) to
    skip files outside any mapping; a future hard guard on `BeforeNodeCreatedEvent` is a
    drop-in extension.
  - **Manual sync panel (priority 35)** — classic `Settings\SyncSettings`
    (`IDelegatedSettings`) shows two buttons (`Nextcloud ← n8n`, `Nextcloud → n8n`) plus a
    last-run line per direction. `Service\SyncStatusService` persists `pull` / `push` records
    (`started_at`, `finished_at`, `status`, `processed`, `succeeded`, `failed`, `message`) under
    AppConfig keys `sync_status_pull` / `sync_status_push`. `Controller\SyncController` exposes
    `POST /sync/{pull|push}` and `GET /sync/status` (admin-gated). Bodies are Phase-1 stubs that
    `markStarted` → `markFinished` with a "No-op (Phase 3/4 not implemented)" message so the
    UI is fully wired — Phase 3/4 only has to swap in the real bulk reconcile / push and call
    `markFinished` from the job.
  - **Skeleton infrastructure ready for Phase 3/4:** `Service\PushService` dispatcher (reads
    `push_mode`, routes to `pushViaApi` / `pushViaWebhook` stubs), `Listener\NodeWrittenListener`
    (registered against `NodeWrittenEvent`, **early-returns** until the Phase 2 metadata filter
    exists; the post-return code already reads `timing` and dispatches sync vs queued),
    `BackgroundJob\PushWorkflowJob` (`QueuedJob` skeleton resolving `fileId` + `userId` via
    `IRootFolder` and delegating to `PushService`).
- **Still TODO before P1 closes:**
  - **occ/helm parity check** — confirm `occ config:app:set n8n_sync api_key … --sensitive`
    round-trips with the declarative form's `ICrypto` storage so values can be baked by
    `components/n8n-config.sh`. (Schema is in place; just needs a manual round-trip test.)

### Phase 2 — Metadata contract + id-strategy spike *(risk: medium — validation)* — ✅ **DONE**
- **Goal:** lock the contract that both the user's n8n flow and the UI depend on; de-risk the
  buggy metadata path *early*, before building UI on it.
- **Scope:** `initMetadata()` for `source`, `mode` (`link|sync`), `writeback` (`two-way|readonly`),
  `n8n_id`, `instance` (indexed, `EDIT_REQ_OWNERSHIP`), applied to **files and bound folders** (folder
  carries the mapping defaults; a workflow's own tags override per file — Fork A/F). Spike
  PROPPATCH/PROPFIND/SEARCH on NC33 (incl. shared files) to confirm or refute D2. Document the WebDAV
  contract for the user's n8n flow (which props to PROPPATCH, file naming). Record id-source decision:
  **filename-encoded (D1, default)** vs metadata (D2).
- **Exit:** a manual curl PROPPATCH→PROPFIND round-trips the keys; SEARCH behavior known; id strategy
  chosen. **Decides:** Fork D (id source), Fork E (backing). **Depends on:** P0 (not P1).
- **Done:** `Service\WorkflowMetadata` registers five keys via `IFilesMetadataManager::initMetadata`
  on `boot()` —
  `n8n_id`, `n8n_mode` (**`reference|sync`** — semantic synonym for `link|sync` in this spec),
  `n8n_writeback` (`two-way|readonly|''`), `n8n_versionId`, `n8n_syncedHash`. All five surface
  automatically over WebDAV as `{http://nextcloud.org/ns}metadata-<key>` via core's
  `apps/dav/lib/Connector/Sabre/FilesPlugin::handleGetProperties` —
  verified by reading `getMetadata(2238)` directly:
  ```
  n8n_id        => w0TtomB3I8dCHSXW
  n8n_mode      => sync
  n8n_versionId => 142c0665-4cc3-4336-ad0b-7e2e266b36d8
  n8n_writeback => two-way
  n8n_syncedHash => 445888a679120d1e338e9da54cbd35575811ecda
  ```
  **Id strategy decided: D1 + D2 hybrid** — `Service\FilenameCodec` keeps the
  `Name.<id>.n8n.json` filename convention (the robust path) AND the metadata is written so
  any future client can read it from PROPFIND. Loop guard for Phase 4 uses `n8n_syncedHash`.
- **Caveat (relevant to P5):** the standard `@nextcloud/files` PROPFIND for the dir listing does
  **not** include `nc:metadata-*` keys by default. We register the keys client-side via
  `registerDavProperty('nc:metadata-n8n_id')` etc. (verified in `window._nc_dav_properties`),
  but see §11 for why this isn't yet flowing through to the rendered Files view's per-row
  `node.attributes`.

### Phase 3 — Bulk "Sync now ← n8n" (populate) *(risk: medium)* — ✅ **DONE**
- **Goal:** pull n8n workflows into NC as files with metadata/id — first-seed + drift repair, and it
  gives real data to build the UI against.
- **Scope:** n8n REST client (list/read); idempotent reconciler that resolves each workflow's
  n8n-relative path through the **mappings (Fork G)** to an absolute NC path and upserts
  `<mappedDir>/Name.<id>.n8n.json` + sets metadata/tags + `mode`; **stamps bound folders** with the
  mapping metadata/`n8n-bound` tag; admin **"Sync now ← n8n"** → `IJobList` job.
- **Exit:** button populates NC at the mapped paths; re-running is idempotent (no dupes); files carry
  id + mode; bound folders are self-described over WebDAV.
- **Decides:** §8 adapter shape; mapping/routing (Fork G). **Depends on:** P1, P2.
- **Done:** `Service\SyncService::pullAll()` iterates configured mappings; per-mapping
  `pullOne()` lists workflows from `Service\N8nClient`, writes/updates each as
  `Name.<id>.n8n.json` under the mapped folder, and stamps the metadata via
  `WorkflowMetadata::write()`. After write, `SyncService::fixupFilecacheMimetype()` upgrades
  `application/json` to `application/n8n+json` so the icon shows immediately. The admin
  "Sync now ← n8n" button (`SyncController::pull` → `SyncStatusService`) drives this directly;
  `IJobList` enqueueing is wired but currently runs synchronously per click. Verified live:
  21 filecache rows hold `application/n8n+json` and the corresponding `oc_files_metadata` rows
  carry the n8n id/version/hash.

### Phase 4 — NC→n8n writeback + "Sync now → n8n" *(risk: medium-high)* — ✅ **DONE**
- **Goal:** the native save — editing a file in NC pushes to n8n immediately; plus bulk push.
- **Scope:** `NodeWrittenEvent` listener scoped to bound folders; **acts only on files that resolve
  to `sync` + `writeback:two-way`** (per-file tag → folder default precedence, no global default,
  Fork A/F); `readonly` and `link` are skipped. Push via REST (by id) **and/or** configured webhook
  path (Fork B — offer both). Timing
  (Fork C): default **C2 (enqueue; drained by Nextcloud's normal cron — your existing CronJob, no new
  infra)**; C1 (sync) trades robustness for immediacy; an optional same-image `background-job:worker`
  sidecar can cut latency later with no plugin change. **Loop guard:** origin marker / content hash so
  plugin writes don't bounce against the user's n8n→NC flow. Admin **"Sync now → n8n"** bulk push
  (`sync · two-way` only).
- **Exit:** save of a `sync · two-way` file → workflow updated in n8n within target latency;
  `sync · read-only` and `link` never push; no echo loops with a live n8n→NC flow; failures fail soft
  + retry. **Decides:** Fork B, Fork C, Fork F. **Depends on:** P1–P3.
- **Done:** `Listener\NodeWrittenListener` is live (no longer short-circuited); it runs every
  save through `Service\SyncGuard` (mapping resolution + bound-folder check + `mode === sync` +
  `writeback === two-way`) and dispatches to `Service\PushService::push()`. Push reads
  `push_mode` and routes to `pushViaApi` (n8n REST `PATCH /workflows/{id}`) or
  `pushViaWebhook` (`POST <n8n_url>/<webhook_path>`). The loop guard uses
  `n8n_syncedHash` — if the file's current sha1 matches `n8n_syncedHash`, the push is
  skipped (this is what stopped the earlier echo-loop / lock errors). On success the new
  `n8n_versionId` and `n8n_syncedHash` are written back via `WorkflowMetadata::write()`. The
  bulk "Sync now → n8n" button drives the same path. Sync vs queued timing is wired through
  `BackgroundJob\PushWorkflowJob`.

### Phase 5 — Native UI: icon + "Open in n8n" *(risk: high — last on purpose)* — ⚠️ **PARTIAL — click-to-open BLOCKED (see §11)**
- **Goal:** the native feel — a real icon and one-click open-in-n8n for **both** link-only and
  synced files.
- **Scope:** `registerFileAction` "Open in n8n" building the deep link from the chosen id source;
  icon via mimetype mapping + `occ maintenance:mimetype:update-db/js` (D-icon-1) or frontend-only
  icon (D-icon-2) as fallback; loaded via `addInitScript`. Keep the surface **thin** (one action,
  one icon).
- **Exit:** click opens the correct n8n workflow; icon shows; works across link/sync; no console
  errors on NC33. **Decides:** Fork D-icon. **Depends on:** P2 (id), P3 (files to render).
- **Done — icon (D-icon-1, the proper row glyph):**
  - `application/n8n+json` mimetype mapping in `config/mimetypemapping.json` + alias to `n8n`
    in `config/mimetypealiases.json`, both merged idempotently into the live install by
    `Migration\RegisterMimetype` (registered as `<repair-steps>` → `<install>` and
    `<post-migration>` so it runs on every enable/upgrade).
  - SVG ships as `img/n8n.svg`, copied to `core/img/filetypes/n8n.svg` by the migration.
  - `core/js/mimetypelist.js` regenerated via `GenerateMimetypeFileBuilder`.
  - `IMimeTypeLoader::getId` + `updateFilecache('n8n.json', $id)` upgrade existing rows; new
    files get the right mime via `SyncService::fixupFilecacheMimetype()` after each pull.
  - **Verified:** users see the n8n icon on `.n8n.json` rows. 21 filecache rows currently
    carry `application/n8n+json` (id `40`).
- **Done — Files-app integration scaffolding:**
  - `Listener\LoadFilesScriptListener` listens for `OCA\Files\Event\LoadAdditionalScriptsEvent`
    and calls `Util::addScript('n8n_sync', '../dist/n8n_sync-files', 'files')` (the
    `../dist/...` path resolves correctly through Apache normalization for the `custom_apps`
    URL prefix). Initial state `n8n_url` is provided.
  - Build pipeline: plain `vite` (NOT `@nextcloud/vite-config`, whose `EmptyJSDirPlugin` would
    `rmSync('js', recursive: true)` and wipe our hand-written admin JS). Output is `dist/`,
    gitignored. Source at `src/files.js`.
  - Bundle imports `registerFileAction`, `FileAction`, `DefaultType`, `registerDavProperty`
    from `@nextcloud/files`, plus `loadState` and `translate`.
  - **Verified at runtime in console:** `window._nc_fileactions` contains `n8n_sync.open`;
    `window._nc_dav_properties` contains `nc:metadata-n8n_id`, `nc:metadata-n8n_mode`,
    `nc:metadata-n8n_versionId`. Action shape is valid (`default: 'default'`, `order: -50`,
    `hasEnabled: true`, `hasExec: true`).
- **BLOCKED — click-to-open:** clicking a `.n8n.json` row downloads the file instead of opening
  n8n. Root cause is documented in detail in **§11 (n8n_sync.open registry-snapshot blocker)**.
  In one sentence: NC 33's `files-main.js` reads `getFileActions()` once at component mount,
  before our `LoadAdditionalScriptsEvent`-injected bundle has a chance to register, and there
  is no Vue/Pinia reactivity over the `window._nc_fileactions` array, so our action's
  `enabled()` is **never called** even though the action is in the singleton.
- **Why last:** all the churn lives here — `@nextcloud/files` v4, the mimetype API gap, client-side
  metadata bugs. Quarantined so the reliable plane ships first.

### Phase 6 — Stretch / template-ization *(risk: varies)*
- Grafana adapter as a second instance of the same plugin shape (**proves the template**).
- Optional raw-JSON viewer (only if Text editor proves insufficient).
- Optional Flow `IOperation`/`ICheck` if you later want no-code rules in the NC UI.
- **Sharing (lowest priority — see §9.1):** emit a NC **share event** (`ShareCreatedEvent` via our
  listener or `webhook_listeners`) carrying the file's `n8n_id` + the NC share target; **an n8n
  workflow decides what "share" means.** The plugin only forwards facts — it does **not** own
  identity mapping. Deferred because NC-user ⇄ n8n-account mapping is the tricky bit.

**Critical path:** P0 → P1 → P2 → P3 → P4 → P5. P2 can run in parallel with P1. After P3 you have a
working, backed-up, populated read path; after P4 a trustworthy two-way data plane; P5 adds the feel.

## 11. Verdict: effort & maintenance

The UI/native feel is, as you guessed, the real work — but it decomposes into very different costs:

- **Tractable, low-churn (days):** metadata registration (E1), admin settings + sensitive key (§3),
  NC→n8n writeback listener (B/C), both "Sync now" buttons, and the occ/helm injection. These are
  standard, well-trodden NC patterns and map cleanly onto this repo's lifecycle.
- **The real "native feel" cost & ongoing maintenance:** the **icon + "Open in n8n" file action**
  (Fork D) and anything touching `@nextcloud/files` v4 / vite / Vue. This is where breakage across
  NC majors lives. It's a genuine app, not a weekend script — budget iterative polish, and keep the
  frontend surface as thin as possible (one file action, one icon strategy) to contain the churn.
- **What stays cheap forever:** steady-state outbound is the **user's n8n flow**, so the most
  "ongoing" part of the data plane isn't our maintenance burden at all — by design.

**Bottom line:** realistic as a focused build, *not* a few-hours hack, and *not* perpetually shaky
either — the shakiness is quarantined to the thin frontend layer. Everything that must be reliable
(metadata, writeback, sync, config) rides stable, popular NC mechanisms.

## 12. Active blocker — `n8n_sync.open` row click silently downloads (Phase 5 frontend)

> Where Phase 5 sits as of this writing. Backend is fine; mimetype/icon is fine; the bundle
> registers cleanly. The single remaining symptom is: clicking a `.n8n.json` row in the Files
> view downloads the JSON instead of opening the workflow in n8n. This section captures the
> investigation in enough detail that we can pick it up cold.

### 12.1 Symptom

Clicking a `.n8n.json` row triggers a download. No error in the console. The "Open in n8n"
action never appears in the row's overflow menu either, even though the action is registered
and visible in `window._nc_fileactions`.

### 12.2 What is verified working (do NOT re-verify)

- Backend metadata is on the file. Sample (file id 2238 — `Complete Homelab Task.n8n.json`):
  `n8n_id = w0TtomB3I8dCHSXW`, `n8n_mode = sync`, `n8n_writeback = two-way`,
  `n8n_versionId`, `n8n_syncedHash`. Verified via
  `IFilesMetadataManager::getMetadata(2238)`.
- Mimetype `application/n8n+json` is the file's mime in `oc_filecache` (id `40`); icon
  renders.
- The bundle `dist/n8n_sync-files.js` loads on Files pages — top-level
  `console.info('[n8n_sync] bundle loaded …')` fires.
- After load: `window._nc_fileactions` contains `n8n_sync.open` and
  `window._nc_dav_properties` contains our three `nc:metadata-n8n_*` keys.
- Action passes `new FileAction(...)` validation; `default: 'default'`, `order: -50`,
  `enabled` and `exec` are functions.
- A capture-phase document-level click listener confirms the click reaches userland —
  `targetTag: 'SPAN'`, `targetClass: 'files-list__row-name-'`, `isAnchor: false`,
  `defaultPrevented: false`. The native browser is **not** initiating the download from an
  `<a download>` anchor; the Files app is doing it.

### 12.3 What is verified NOT working

- `enabled()` of our action is **never called**. Heavy `console.info` instrumentation inside
  `enabled()` (logs every invocation regardless of node) shows zero entries.
- Therefore `exec()` is also never called.
- The PROPFIND that the file list uses to populate rows does **not** include our
  `nc:metadata-n8n_*` properties — even though we called `registerDavProperty()` at module
  top. The fetch+XHR interceptor only captures `dav/systemtags/` PROPFINDs; the actual file
  listing PROPFIND is not flowing through `window.fetch` or `XMLHttpRequest.prototype.send`
  in a form we've intercepted yet.

### 12.4 Diagnosis

NC 33's `dist/files-main.js` invokes `getFileActions()` once during the initial component
mount and stores the result in component state. It then derives `enabledFileActions` /
`defaultFileAction` reactively only against that snapshot. There is **no watcher over
`window._nc_fileactions`** — pushes after mount are invisible to Vue's reactivity. Our
`LoadAdditionalScriptsEvent` listener loads our bundle after the Files app has already
mounted, so our `registerFileAction` push is too late by milliseconds.

The same ordering explains the missing PROPFIND props: by the time we call
`registerDavProperty()`, the Files app's first PROPFIND has already been built and
issued. (`registerDavProperty` *does* update `window._nc_dav_properties`, but the Files
app doesn't re-issue PROPFIND on mutation.)

So the action **is** registered, the DAV props **are** registered, both are visible to any
*new* code that reads the singletons after we run — but the Files app has its mount-time
snapshot of both and never refreshes from the global. Our action effectively never enters
the Files app's mental model of the page.

### 12.5 What we tried (and why each is a dead end alone)

1. Switched the action's `enabled(nodes, view)` signature to NC 33's
   `enabled({nodes, view, folder, contents})` — still never invoked, because the registry
   is the gate, not the signature.
2. Added `default: DefaultType.DEFAULT` and `order: -50` so we'd win over any other action —
   moot because we're not even in the Files app's snapshot.
3. Called `registerDavProperty('nc:metadata-n8n_id')` before any other module work —
   updates `window._nc_dav_properties`, but PROPFIND has already happened.
4. Capture-phase document `click` listener — confirms the click reaches us with no anchor
   and no prevented default. Useful as a hook for option A below.
5. Verified bundle URL serves correctly (`Util::addScript('n8n_sync', '../dist/n8n_sync-files', 'files')`
   resolves through Apache's path normalization to a working 200) and the script tag has the
   strict-CSP nonce.

### 12.6 Options forward

#### Option A — Capture-phase click intercept (recommended pragmatic path)
Use the same capture-phase listener we already proved works, this time *acting* on it
instead of just logging:

1. On `click` capture-phase: if `event.target.closest('[data-cy-files-list-row-name$=".n8n.json"]')`,
   look up `data-cy-files-list-row-fileid`, `event.preventDefault()`, `event.stopImmediatePropagation()`.
2. Resolve the n8n id. Two sources, in this order of preference:
   - **Filename codec (D1):** parse `<name>.<id>.n8n.json` via the same `Service\FilenameCodec`
     rules — the id is in the filename for every file we wrote, no DAV needed.
   - **Targeted PROPFIND (D2 fallback):** if filename doesn't carry the id, do a single
     `PROPFIND Depth: 0` for that file with `<nc:metadata-n8n_id />` in the `<d:prop>`.
3. `window.open(\`${n8nUrl}/workflow/${id}\`, '_blank', 'noopener,noreferrer')`.

Pros: works regardless of Files-app internals; survives NC majors as long as the row data
attribute selector exists. Cons: not the "native FileAction" feel — the entry won't appear
in the row's overflow menu (Option A is purely a click hijack). Mitigate with a separate
small Vue-free DOM badge if needed.

#### Option B — Push into the Files-app reactive store directly
Investigate whether NC 33's Files app keeps its actions in a Pinia store or `Vue.observable`
ref that's reachable from the global scope. If yes, push our action into that store directly
(in addition to `registerFileAction`) so the existing `enabledFileActions` computed re-derives
reactively. Higher risk, more brittle on NC upgrade, but is the "proper FileAction" path.

#### Option C — Run our bundle before Files mounts
`LoadAdditionalScriptsEvent` is by design "additional" (after). There may be no clean way
to load earlier without monkey-patching core templates.

#### Option D — Server-rendered `n8n_url` redirect anchor (last-resort)
Replace the row's link target server-side via a custom column renderer — likely impossible
without forking the Files app; not worth pursuing.

### 12.7 Recommended next move

**Go with Option A.** It's tractable in a single bundle update:
- Keep the `registerFileAction` registration so the menu entry is wired for any future
  consumer that re-reads the registry (and so the action shows up if/when NC fixes the
  snapshot pattern).
- Add the capture-phase click hijack as the actual mechanism that powers the click today.
- Drop the diagnostic `console.info` chatter once Option A is verified.
- Use `Service\FilenameCodec` as the source of truth for the id (filename codec is already
  the chosen D1 strategy; metadata reads can stay as a fallback for files written by
  hand-PROPPATCH that don't carry the id in the filename).

### 12.8 Build/deploy loop (current)

```sh
cd apps/nextcloud/custom-apps/n8n_sync
npm run build
k -n cloud cp -c nextcloud dist/n8n_sync-files.js \
  nextcloud-8799fc5f-xx6f4:/var/www/html/custom_apps/n8n_sync/dist/n8n_sync-files.js
k -n cloud exec nextcloud-8799fc5f-xx6f4 -c nextcloud -- \
  chown -R www-data:www-data /var/www/html/custom_apps/n8n_sync/dist
```
Hard reload (Ctrl+Shift+R) the Files page in the browser to test.

### 12.9 Pod / repo state at write-time

- App version inside the pod: `0.0.3` (manually bumped via `sed` for testing); repo is at
  `0.0.2`. **Sync the bump back to `appinfo/info.xml` once we land Option A so the next
  fresh deploy carries it.**
- Repo path: `apps/nextcloud/custom-apps/n8n_sync/`.
- Pod path: `/var/www/html/custom_apps/n8n_sync/`.
- Pod: `nextcloud-8799fc5f-xx6f4` ns `cloud`, container `nextcloud` (the original
  pod we used for live cp; a fresh replica `nextcloud-755fc67944-9fb7p` came up
  after the §13 crash recovery — either is fine, but bear in mind anything dropped
  into the PVC via `kubectl cp` to the old pod is also visible to the new one).
- Generated artifacts (`dist/`, `node_modules/`, `package-lock.json`) are gitignored.

---

## 13. Crash-loop incident (resolved) — DB upgrade vs `set -e` LDAP hook

Recorded here because it bit during Phase 5 iteration and **will bite again** on the
next custom-app version bump or image change. Also captured in repo memory at
`/memories/repo/nextcloud-crash-loops.md`.

### 13.1 Symptom

Replica `nextcloud-755fc67944-lkmhp` went `1/2 CrashLoopBackOff`. The previous
container's log ended with:

```
user_ldap already enabled
==> Failed at executing script "/docker-entrypoint-hooks.d/before-starting/helm.sh". Exit code: 1
```

The old replica `nextcloud-8799fc5f-xx6f4` kept serving traffic — so the site
stayed up but no new pods could roll.

### 13.2 Root cause

Tracing `ldap-config.sh` under `sh -x` in the cron sidecar of the failing pod
showed every `php occ ldap:*` call returning:

> *"Nextcloud or one of the apps require upgrade — only a limited number of
> commands are available. You may use your browser or the occ upgrade command
> to do the upgrade."*

`php occ status` on the failing pod confirmed `needsDbUpgrade: true`.

The chain:
1. Custom app `n8n_sync` was sed-bumped to 0.0.3 inside the PVC for live testing.
2. NC marks DB-upgrade-needed when an installed app's declared version exceeds
   the migrated version.
3. In that state, the entire `occ ldap:` and `occ app:` namespaces error out.
4. `before-starting.sh` runs under `set -e` and sources `ldap-config.sh`.
5. The first `php occ ldap:create-empty-config` (or any `cfg` call) returns
   non-zero → `set -e` aborts the boot script → liveness probe fails → crash loop.

### 13.3 Fix that worked

```sh
# Run upgrade in a still-healthy nextcloud container (NOT cron — cron runs as
# uid 33 and can't write some migration artifacts):
k -n cloud exec nextcloud-8799fc5f-xx6f4 -c nextcloud -- php occ upgrade --no-interaction
# Then boot the failing replica fresh:
k -n cloud delete pod nextcloud-755fc67944-lkmhp
```

`occ upgrade` ran the Mail address purify + background-job + code-integrity
migrations and exited maintenance mode. The next pod cleared the LDAP hook on
first try.

### 13.4 Recommended hardening (NOT yet applied)

Two cheap changes would prevent this class of crash loop:

1. **Self-heal the upgrade boundary.** Add to the very top of
   `apps/nextcloud/components/lifecycle/before-starting.sh` (just after `set -e`
   and the comments, before any `occ` call):

   ```sh
   # If a custom-app version bump or image roll left NC in pending-upgrade state,
   # finish the migration first — otherwise the `occ ldap:*` / `occ app:*`
   # namespaces error out and trip set -e below.
   php occ upgrade --no-interaction || true
   ```

   Idempotent and a no-op when already upgraded. Belt-and-suspenders for the
   exact failure mode we hit.

2. **Make `ldap-config.sh` resilient to `occ` namespace gaps.** Either change
   `cfg()` to swallow errors during boot (`php occ ldap:set-config "$1" "$2" "$3" || true`)
   or wrap the whole script in `set +e` … `set -e`. The current strict-failure
   posture made the LDAP hook the canary for *any* unrelated `occ` regression,
   which is the wrong coupling.

If we land **(1)**, **(2)** is optional. If we land **(2)** alone, NC can boot
in stale-config state silently, which is worse — so prefer (1).

### 13.5 Operational note for Phase 5 iteration

Anytime we bump `appinfo/info.xml` (or sed-bump the version inside the PVC for
live testing), we may now be one pod restart away from a crash loop. Until
13.4(1) is landed, after any version bump:

```sh
k -n cloud exec <healthy-pod> -c nextcloud -- php occ status   # check needsDbUpgrade
# if true:
k -n cloud exec <healthy-pod> -c nextcloud -- php occ upgrade --no-interaction
```

before triggering any rollout / pod delete.

---

## 14. Manual sync — parameterized dispatch (sync vs async)

> NOTE: an earlier revision of this plan had §14 (n8n API findings), §15 (sync
> reconciliation/pruning), §16 (UI/UX: card redesign, per-mapping sync, async)
> — those were reverted off disk. Most of that is already **implemented** in
> code (Phase 5 row-click works via @nextcloud/files v4; indexed `n8n_mapping`
> metadata + folder-scoped pull; move-out veto; the card-based mappings UI with
> per-mapping Sync). This section re-captures the manual-sync piece.

### 14.1 One parameterized pull entry point

The sync-running function takes two inputs so callers choose behavior:
- **`mappingId` (optional)** — a specific mapping, or empty/null = **all mappings**.
- **`async` (bool)** — `false` runs **inline** in the request (current behavior, returns counts); `true` **enqueues a background `IJobList` job** and returns immediately (`queued`), so navigating away can't kill it.

Both paths stay available (we can still do exactly what we do now). The async job records run state in `SyncStatusService` (`queued → running → done|error`) so the UI reflects progress across navigation, and posts a completion notification.

### 14.2 Caller wiring (hardcoded per context)

- **Per-mapping "Sync" button** (card) → `dispatchPull(mappingId, async = false)` — synchronous, one mapping. Fast feedback on a small set; it's already bounded to a single mapping.
- **Bulk "Manual sync" section** → `dispatchPull(null, async = true)` — asynchronous, all mappings. The long/full sync is always a background job → safe and reliable, never tied to the page.

This gives a quick inline sync for one mapping and a safe queued sync for everything.

---

## 15. Current implemented reality (authoritative — supersedes §1–§13 specifics)

Reconstructed after a Copilot "keep changes" overwrite dropped the prior §14–§16. This is what is actually built and live.

### 15.1 Phase 5 frontend — DONE
- **Row click → "Open in n8n"** works. The §11/§12 blocker was the bundled `@nextcloud/files` being **v3** (legacy `window._nc_fileactions`) while NC 33 uses **v4** (`window._nc_files_scope.v4_0`). Fixed by upgrading to v4. v4 API changes adopted: `registerFileAction` takes a plain `IFileAction` object (no `FileAction` class); `registerDavProperty` moved to `@nextcloud/files/dav`; `enabled`/`exec` receive `{ nodes, view, folder, contents }`.
- **Deep link source:** `nc:metadata-n8n_id` rides the directory PROPFIND (zero extra calls) for navigations. On the first-folder-after-page-load race (our add-on script registers the DAV prop a beat after core's first PROPFIND), `exec()` falls back to **one on-demand WebDAV single-node `stat`** via `@nextcloud/files/dav`'s `getClient()` — **no custom endpoint** (an earlier `LinkController` was added then removed).
- **Right-click → "Edit as text"**: Text's editable mimetypes are **hardcoded** (`TextDirectEditor::getMimetypes()`) and exclude our custom mime, and `OCA.Text.createEditor()` is **Markdown-only** (it reflows/corrupts JSON). So we open a **plain monospace source editor in a modal**, loading/saving via the built-in WebDAV client. Saving fires the normal writeback.
- **Mimetype drift fix:** a WebDAV PUT re-detects mime off the last extension segment (`.json` → `application/json`), losing our custom mime + icon. The save listener **re-stamps** `application/n8n+json` (same fixup the pull runs).
- The Files bundle is built from `src/files.js` → `dist/` (Vite, plain config — the @nextcloud preset wipes `js/`), loaded via `LoadFilesScriptListener`.

### 15.2 n8n API capability findings (verified live; do NOT re-litigate)
- **Folders: not in the public API** (`/folders` 404, `/projects` 403 enterprise, `parentFolder` read-only). n8n folders are UI/enterprise-only → **subfolders are a Nextcloud-side concept only**; the n8n side is flat-tagged.
- **`meta` is read-only** (`PUT … meta` → 400). Can't stash our metadata there.
- **Tags API works**: `GET/POST /tags`, `PUT /workflows/{id}/tags [{id}]`. Powers UC-6.
- List-by-tag returns **full bodies** (`nodes`/`connections`) → pull is **not N+1**.

### 15.3 Mapping-id as indexed per-file metadata
`WorkflowMetadata::KEY_MAPPING = 'n8n_mapping'` is registered **indexed** and stamped on every write = the mapping's stable id. The *file* owns its mapping (not the folder). `MappingService::getById()` resolves it. Survives moves; enables multi-mapping-per-folder + nested mappings; queryable.

### 15.4 Pull discovery — folder-scoped + mapping-id
`indexByN8nId` recurses the mapping's `team_folder` subtree (folder-scoped) and filters by each file's `n8n_mapping` (a file owned by a *different* mapping in an overlapping subtree is skipped; legacy files with no id are backfilled). Existing files update **in place wherever they live** (renames stay in the file's own folder); only brand-new workflows are written to the `team_folder` root. → subfolders work for free.

### 15.5 Invariant: "managed = under its mapping folder" + move-out veto
`MoveGuardListener` (on `BeforeNodeRenamedEvent`) **vetoes** moving a `*.n8n.json` out of its mapping folder (throws `AbortedEventException`); rename + subfolder moves are allowed. Pull is folder-scoped; an escaped copy is inert (not pulled, not pushed). We deliberately rejected the rich "on move-out: delete/unlink/convert" option matrix.

### 15.6 Dual-channel writeback (Fork B resolved as B3)
`PushService` pushes a saved two-way file to **every enabled channel** — independent toggles, not either/or:
- `api_enabled` → `PUT /workflows/{id}` (writable fields; empty `connections`/`settings`/`staticData` coerced from `[]`→`{}` because n8n's GET serialises empty as `[]` but PUT demands objects; `settings` stripped to its allowlist).
- `webhook_enabled` → POST to `webhook_path` with its **own** `webhook_token` Bearer (separate from the API key).
Loop guard = `SyncGuard` + `n8n_syncedHash`. Errors are **not swallowed**: `N8nClient` extracts n8n's `{message}` into `N8nApiException`; the save listener surfaces it as a **native Notification** (bell + toast, `Notifier`/`WritebackNotifier`), and the bulk push returns it. `push_mode` (the old exclusive enum) was removed.

### 15.7 Admin settings cards
`InstanceSettings` (`n8n_url`) · `AdminSettings` = REST API (`api_enabled`, `api_key`, + classic Test Connection panel with Test API/Test webhook) · `WebhookSettings` (`webhook_enabled`, `webhook_path`, `webhook_token`) · `WritebackSettings` (`timing` only). Titles dropped the redundant "n8n" prefix.

### 15.8 Mappings UI — card form
`templates/mapping_settings.php` renders **one card per mapping** (not a table): grid of tag+mode / folder+team-folder with the **groups picker spanning all rows** on the right; **colored icon-only buttons** (green Save ✓ / blue Sync 🔄 / red Delete 🗑); a **sticky per-card status** span right of the buttons (no auto-dismiss, cleared on reload). Card `max-width: 900px` to match NC's `.section p` text width. Each field label carries a **ⓘ info button** with a **pure-CSS hover/focus tooltip** (`.n8n-sync-info` + `data-tip`; no `@nextcloud/vue`, no JS — the old native `title=` was useless) holding the field's description; this replaced the verbose `settings-hint` paragraphs (and a briefly-tried "legend card", kept in git history as a fallback). Field labels are prominent (1.1em, 600, full-contrast); the ⓘ is subtle (opacity 0.4 → 1 on hover). Descriptions live once in PHP `$desc` + mirrored in JS `DESC` for added cards. A native `<details>` collapsible around a field-guide remains an easy optional add.

### 15.9 Manual sync — see §14
Async-only for "all" (background job), inline for one mapping. `SyncStatusService` has a `queued` state and `markQueued`/`markStarted` **preserve the previous run's result** so the UI shows "Queued…/Running… · last: <when>" instead of falling back to "never". The manual panel polls `/sync/status`.

### 15.10 Test fixtures currently in n8n + NC
- Mapping **"N8N Tasking"** (Team Folder, tag `nextcloud:tasking`) — the original demo set.
- Mapping **"N8N Admin Test"** (admin-owned, team-folder unchecked, tag `nextcloud:admintest`) + workflow **"Admin-Owned Example"** — for testing the admin-owned path + multi-mapping sync. Safe to delete when done.

### 15.11 UC-6 "New → n8n workflow" — DONE (file-creation half)
Files **New menu** entry "n8n workflow" (`addNewFileMenuEntry`, category CreateNew), **always offered in any folder** — deliberately *not* gated to mapped folders (per the owner's call: a new `.n8n.json` outside a mapping is just a file with our icon + empty metadata; docs say "drop it into a mapped folder to sync it"). Handler writes a **starter workflow JSON** (`{name, nodes:[], connections:{}, settings:{}, active:false}`) via the WebDAV client (`getUniqueName` for collisions), then `emit('files:node:created', resultToNode(stat))` so the view picks it up. The `NodeWrittenListener` re-stamps the custom mimetype on write → correct icon immediately, no n8n call at creation. The **"make it real in n8n" half** (create-on-land in a mapped folder) is the move-in path in §17.

---

## 16. Still pending (roadmap)

> **Work-in-flight (concurrent agents):**
> - **Other agent** — admin-settings reshape (§17.4) + scheduled `TimedJob` (§17.3.2 / §17.5). **DONE**.
> - **Copilot** — UC-6 create-on-land (§17.2). **CODE LANDED, awaiting live verification.**
>   Shipped: `N8nClient::listTags/ensureTag/setWorkflowTags`, new `Service\CreateService` and
>   `Listener\CreateInN8nListener` (registered on `NodeWrittenEvent` + `NodeRenamedEvent`),
>   deployed to the pod and lint-clean. `MoveGuardListener` untouched (eject §17.1 still open).
>   Needs: drop a fresh `.n8n.json` into a mapped folder via the New menu **and** drag-in from
>   outside, then confirm the workflow appears in n8n with the mapping's tag and the file's
>   metadata + ownership pill are stamped.

- **Sync reconciliation & pruning (pull):** today's pull upserts but never prunes. Target: 3-way reconcile per mapping — build the NC index (indexed `n8n_mapping` search, scoped to the mapping folder), stream the n8n list (full bodies, paginated), `create`/`update`/**`prune`** (NC files whose workflow is no longer in the mapping = deleted *or* untagged in n8n). **Prune safety is paramount:** only on a **complete, successful** enumeration (never on partial/errored/empty/MAX_PAGES-truncated lists — else a transient n8n hiccup mass-deletes); only files with `n8n_mapping` + `n8n_id`; delete → **NC trash** (recoverable). Push stays **upsert-only** (never auto-delete n8n workflows).
- **UC-6 New workflow:** file-creation half **DONE** (§15.11); **create-on-land half — code shipped, pending live test** (§17.2).
- **Move semantics — eject (§17.1):** replace the move-out *veto* with the "eject = back up + detach + delete from n8n" rule. (Create-on-land for the inverse direction is being picked up under §17.2.)
- **Continuous n8n→NC sync (§17.3):** today only manual pull. Add webhook-driven and/or scheduled (TimedJob) pull. **(Other agent.)**
- **Data Sync admin redesign (§17.4):** rename "Manual sync"→"Data Sync", reorder, add a cron sub-section. **(Other agent.)**
- **Writeback folder-scope symmetry:** the save listener is path-independent; add the same "still under its mapping folder?" check so an escaped copy can't push (matches §15.5).
- **occ command for synchronous "sync all":** the parameterized `dispatch()` already supports `(mappingId=null, async=false)`; expose it as an `occ` command (the UI keeps "all" async-only on purpose).
- **Conflict handling:** currently last-writer-wins per direction; could detect `n8n_versionId`/`n8n_syncedHash` divergence and warn instead of clobber.
- **Polish:** completion Notification for bulk sync; optional "archive instead of delete" on prune.

---

## 17. Move semantics, continuous n8n→NC sync, and cron (decisions + feasibility)

Design decisions from the post-UC-6 discussion. Not yet built (except where noted).

### 17.1 Move OUT of a mapped folder = "eject" (replaces the §15.5 veto)
The current `MoveGuardListener` **vetoes** moving a managed `.n8n.json` out of its folder. The owner proposed a simpler/safer rule that we're adopting:

> Moving a managed workflow out of its mapped folder **(1) keeps a full standalone copy** of the JSON in Nextcloud as an `.n8n.json` (icon kept) with **its sync metadata stripped**, and **(2) deletes the workflow from n8n**.

Why it's safe: n8n **archives** deleted workflows (easily un-archived), and Nextcloud keeps the full copy — which can be **dragged back into a mapped folder to re-create it in n8n** (§17.2). So "move out" = "take it out of n8n but keep a backup in NC".

**The detached state needs a name** — proposal: **`detached`** (a `.n8n.json` with full contents but no live n8n link / empty `n8n_*` metadata). Alternatives considered: `local`, `unlinked`, `orphan`. (`backup` is taken — it's a *mapped* mode.) **Decision: outside a mapped folder, mode is irrelevant — link/sync/backup all collapse to `detached`.** Mode only matters *inside* a mapped folder.

**Edge cases flagged (not damning, but handle):**
- **n8n delete fails** (API down) → don't strip metadata / don't leave a half-state: either abort the eject (keep it managed) or retry; otherwise the still-tagged workflow re-pulls into the folder as a duplicate next sync.
- **Move between two mappings** (A→B folder) = eject from A *then* land in B (§17.2 re-creates with B's tag). Treat as eject-then-create.
- **Bulk move** of many → many n8n deletes; recoverable (archive + NC copies) but could surprise — consider a confirm.

### 17.2 Move/drop INTO a mapped folder = "create-on-land" (the UC-6 sync half)
A `.n8n.json` **without `n8n_id`** appearing in a mapped folder (created there, moved in, or dropped) → resolve the folder's mapping (`resolveForPath`, parent-walking) → `N8nClient::createWorkflow` + `ensureTag`/`setWorkflowTags` (apply the mapping tag) → stamp `n8n_id`/`n8n_mapping`/mode + mime. This is what makes the §15.11 "drop it in to sync" promise real, and the re-attach side of §17.1. (Tags API + create verified live.)

> **Status (Copilot):** code shipped to the pod, app reloads clean, syntax-checked across all four touched files. Live UX verification still owed — needs a drop via the New menu AND a drag-in from outside to confirm both event paths trip the listener and the workflow appears in n8n with the mapping tag.

> **Notes from Copilot (taking this):**
> - Wiring: new `Listener\CreateInN8nListener` on `NodeWrittenEvent` (covers
>   the New-menu create + Text-editor saves of a hand-made file) and
>   `NodeRenamedEvent` (covers move-in from outside any mapping). Both bail
>   unless: extension is `.n8n.json`, file resolves into a mapping via
>   `MappingService::resolveForPath`, `WorkflowMetadata::read` has no
>   `n8n_id`, and `SyncGuard` is inactive. Wrapped in `SyncGuard::run()` so
>   the stamp-write doesn't echo into the writeback listener.
> - `N8nClient` gains `listTags`, `ensureTag`, `setWorkflowTags` (the plan
>   said "verified live" but the methods didn't exist yet — adding them now).
>   GET `/tags` → POST `/tags {name}` if missing → PUT `/workflows/{id}/tags`
>   with `[{id}]`.
> - Body shape sent to `POST /workflows`: same writable-field whitelist as
>   `PushService::pushViaApi` (`name`, `nodes`, `connections`, `settings`,
>   `staticData`), with the same `[]→{}` coercion on object-typed fields and
>   the same `settings` allowlist. Defaults if the file omits them: empty
>   `nodes: []`, `connections: {}`, `settings: {}`, `active: false` — matches
>   the frontend's `STARTER_WORKFLOW`.
> - **Name authority on create:** prefer the file's JSON `name` if non-empty;
>   else use the parsed file stem (`FilenameCodec::parse(basename)['name']`).
>   Rationale: a hand-edited file likely set `name` deliberately; the starter
>   has `"New workflow"` and the user probably renamed the file, not the
>   contents.
> - **Mapping-tag assignment is additive only** — we do NOT strip other
>   tags from the workflow (n8n's tag namespace isn't ours). A pre-existing
>   workflow that someone uploads as a `.n8n.json` keeps its other tags.
> - **Post-create:** stamp full metadata + ownership system tag (so the user
>   sees the right `n8n:sync`/`n8n:backup` pill immediately), and re-stamp
>   the `application/n8n+json` mimetype (the listener already does this on
>   write, but call it explicitly to be sure the icon shows on the very
>   first render).
> - **Out of scope here:** no rename of the file (we keep `idInFilename =
>   false` to match the pull side). No move-out / eject — that's §17.1.
>
> Question for other agent: I'm not changing the writeback listener's order
> or the `WritebackSettings` form. If your admin reshape moves keys around
> (`api_enabled`, `webhook_enabled`, `timing`), the create path doesn't read
> any of them — it's create-only, no push channel branch — so we should be
> conflict-free. Flag here if I'm wrong.

### 17.3 "Un-sync by untag" + continuous n8n→NC
- **Untag in n8n to un-sync:** yes — removing the mapping's tag from a workflow means it no longer matches → the **prune** step of reconciliation (§16, *not yet built*) removes the NC file on the next pull. So this works **once prune exists**, not today.
- **Keeping NC current with n8n edits** (n8n→NC), two options (not mutually exclusive):
  1. **Webhook-driven:** an n8n workflow POSTs to a Nextcloud endpoint on save → targeted pull of that one workflow. Near-real-time, but requires per-instance n8n wiring.
  2. **Scheduled pull:** a TimedJob runs `dispatch('pull', null, async)` on an interval (§17.4/§17.5). Simple, no n8n-side setup; latency = the interval.

### 17.4 Admin "Sync Settings" + "Manual sync" — DONE
Final shape (the section was iterated a few times): instead of one combined panel, **two panels below Folder mappings**, because declarative forms can't host action buttons:
- **"Sync Settings"** — a **declarative** form (the renamed `WritebackSettings`, id `data_sync`, priority 33; class name kept to preserve registration). Auto-persists (no controller/JS). Fields = the two directions:
  - `timing` (radio) — **Nextcloud → n8n** writeback: async (default) | sync. Read by `NodeWrittenListener`.
  - `schedule_enabled` (checkbox) + `schedule_interval` (**free-text duration**, e.g. `15m`/`1h`/`6h`/`1d`/`900`, placeholder/default `1h`) — **n8n → Nextcloud** scheduled pull. Read by `ScheduledPullJob`.
- **"Manual sync"** — the existing classic `SyncSettings` panel (priority 40), now just the two always-available buttons (**Sync to n8n** first, then **Sync from n8n**) + last-run line.
- The per-channel writeback enables (`api_enabled`, `webhook_enabled`) **stay in their REST API / Webhook cards** (they're channel-specific — there are two NC→n8n channels, so it can't collapse to one toggle).
- Copy is kept short and frames n8n→NC explicitly as a **pull** (Nextcloud reads from n8n, never changes it), with a one-line nod to the **push** alternative (an n8n workflow webhooking into Nextcloud — see §17.3.1).
- **Why duration text, not a `SELECT`:** the declarative `SELECT` rendered every option as "undefined" in this NC build (option labels didn't bind), so the interval is a `TEXT` field instead. Better fit anyway — NC schedules by interval, and a duration string is clear.
- **Storage-type gotcha (important):** declarative INTERNAL storage records the **checkbox as bool-typed** but `TEXT`/string fields as **string-typed**. So `getValueBool('schedule_enabled')` works (same as `PushService` reads `api_enabled`) but `getValueInt('schedule_interval')` **throws `AppConfigTypeConflictException`**. `ScheduledPullJob` reads **defensively** (typed getter → fall back to a string parse) so it's robust either way.

### 17.3.2 Scheduled pull `ScheduledPullJob` — DONE
`TimedJob` registered once in `Application::boot` via idempotent `IJobList::add`. Constructor reads the interval (defensively) → `setInterval(max(60, …))`, so changing it in settings takes effect next tick. `intervalSeconds()` parses the duration string: `<n>[s|m|h|d]` or plain seconds, unparseable → hourly, floored at 60s (the cron tick). `run()` no-ops when `schedule_enabled` is off; otherwise `runInline('pull', null)` wrapped in `SyncStatusService` start/finish (so the Manual-sync "last:" line reflects scheduled runs). Verified live: disabled → no-op; enabled (bool checkbox + string interval) → pulled 8/8; parsing verified across `15m`→900, `1h`→3600, `6h`→21600, `1d`→86400, `900`→900, `2 h`→7200, junk→3600, `45s`→60 (clamped).

---

## 18. Handoff note (for the next agent)

**State:** Phase 0–5 + manual/scheduled Data Sync are **live in production** (deployed to the `cloud` namespace nextcloud pod, app `n8n_sync` at `custom_apps/n8n_sync`). §15 is the authoritative current-state record; §16 is the roadmap; §17 holds the latest move-semantics + sync decisions.

**Deploy loop (no version bump — ever):** edit under `apps/nextcloud/custom-apps/n8n_sync/` → `kubectl cp <subdir>` into the running pod's `custom_apps/n8n_sync/` → `chown -R www-data:www-data` → `kubectl rollout restart deployment/nextcloud -n cloud`. **Never bump `info.xml <version>`** (triggers needsDbUpgrade → LDAP hook crash-loop; see §13). Frontend bundle changes need `npm run build` first (Vite → `dist/n8n_sync-files.js`). Admin CSS/JS/templates are served unbundled; **the browser caches them — hard-refresh** after each change (no cache-buster wired; offered but not built).

**Next up (recommended order):** the transfer pair — **§17.2 create-on-land** (a `.n8n.json` without `n8n_id` appearing in a mapped folder → create in n8n + tag + stamp) and **§17.1 eject-on-move-out** (replaces the current `MoveGuardListener` veto: back up locally as `detached` + delete from n8n). Then **§16 reconciliation/pruning** (the only thing that makes "untag in n8n → file removed in NC" work). 

**Gotchas to respect:** declarative INTERNAL storage type mismatch (§17.4); `kubectl up <dir>` is the only sanctioned way to apply *manifests* (code goes via cp+rollout); test against throwaway n8n workflows (current fixtures: mappings "N8N Tasking" + "N8N Admin Test", §15.10); n8n API has no folders + read-only `meta` (§15.2).

### 17.5 Cron feasibility — VERIFIED, it's a go (interval, not cron-expression)
- **Can NC schedule?** Yes, via **`TimedJob::setInterval(seconds)`** — interval-based, runs ~every N seconds when the cron tick fires. NC does **not** natively support cron *expressions* (`0 3 * * *`); a configurable **interval / preset** (e.g. 15 min / hourly / daily) is the native path. Full cron-expression would need custom next-run logic.
- **Are we already using cron?** Yes. `apps/nextcloud/components/config/values.yaml` runs a **sidecar** looping `php -f cron.php; sleep 60` as www-data (uid 33) — i.e. the NC cron executor every 60s (background-jobs mode = cron). Our `ManualSyncJob` already runs through it.
- **Does every-60s mean every job runs every minute?** **No.** The 60s loop is just the *executor tick*; each `TimedJob` honors **its own interval**, and `QueuedJob`s run once. A 15-min TimedJob runs ~every 15 min regardless. The fast tick only buys fine granularity + near-real-time async writeback (per the values.yaml comment).
- **Conclusion:** the scheduled pull (§17.3.2 / §17.4) is buildable now — a `TimedJob` reading an **enable flag + interval** from AppConfig. **Not** a blocker. (Only a full cron-expression UI would be a "revisit".)

---

## 17.6 Three-way name sync (filename ≡ JSON `name` ≡ n8n name) — DONE

`Listener\NameSyncListener` (on `NodeWrittenEvent` + `NodeRenamedEvent`, registered in `Application::register`). Keeps the three names equal for **two-way** managed files; authority follows what the user changed:
- **Rename the file** (`NodeRenamedEvent`) → `nameFromFilename()` writes the new stem into the JSON `name`; that write drives the writeback → n8n.
- **Edit the JSON `name` + save** (`NodeWrittenEvent`) → `filenameFromName()` renames the file (within its own folder, suffix-aware via `FilenameCodec`); the same write already pushed the new name to n8n via `NodeWrittenListener`.

Gate mirrors the writeback listener (metadata-only, survives moves): `n8n_id` + `mode=sync` + `writeback=two-way`. Backup/reference stay n8n-driven (pull renames their files). **Loop-safe by idempotency, not guarding** — each fix makes both sides equal so the follow-up event no-ops; we still bail on `SyncGuard::active()` so pull/create writes don't reshuffle names.

**Verified:** both directions + the n8n push, on a throwaway workflow (logic via direct method calls + `PushService::push`). NOTE: the full event-driven chain can't be exercised from the `base.php` CLI harness — nested move→event→metadata-read trips NC's "dirty table reads" guard (a CLI/transaction artifact; the production writeback listener uses the same metadata-read-in-handler and works over HTTP). **Owner to confirm in the UI:** rename a `*.n8n.json`, and separately edit its `name` via "Edit as text" + save — both should sync filename/JSON/n8n.

Interaction notes for the create-on-land / §17.1 eject work: NameSyncListener bails unless `n8n_id` is set, so it never collides with create-on-land (which owns the first, id-less write); within-folder renames pass `MoveGuardListener`. When §17.1 replaces the move-out veto with eject, keep within-folder renames flowing to NameSyncListener (only *out-of-mapping* moves should eject).

### 17.6.1 Lock fix — name sync is deferred to a job (UPDATE)

First cut wrote the file *synchronously inside* the rename handler and failed in production with `OCP\Lock\LockedException` ("existing lock on file") — you can't `putContent` a file that's locked by the in-flight rename. Fixed: `NameSyncListener` now only **reads** (shared lock, safe mid-rename) to detect a mismatch and **enqueues `BackgroundJob\ReconcileNameJob`**; the job does the write/rename + push **after the request commits** (next cron tick). Both actions verified on a throwaway:
- `name_from_filename` (rename → JSON `name` + n8n via direct push, guarded so no echo),
- `filename_from_name` (edit JSON name → rename file; the save's writeback already pushed n8n).

Consequence: the reconciliation is **cron-paced** (~1 tick, ≤60s here), like the async writeback — not instant.

Edge case observed in the wild: renaming a managed file to drop the `.n8n.json` extension (e.g. `MyFlow.json`) un-manages it (extension gate), and a pull then re-creates a fresh `<n8nname>.n8n.json` for the same workflow → **duplicate NC files for one `n8n_id`**. Cleaned up by hand this time; the real guard is the §16 reconciliation/prune (dedupe by `n8n_id`). Consider also keeping the custom mimetype/`.n8n.json` on rename, or warning on extension change.

---

## 17.7 Delete semantics — VERIFIED IN PRODUCTION ✅

> **Status: BUILT, deployed, end-to-end verified.** Owner deleted a `New Workflow.n8n.json` via the right-click "Delete file" context menu (`mode=sync`+`writeback=two-way`); NC moved the file to trash and the corresponding n8n workflow flipped to `isArchived:true`. Permanent purge from the trash bin then issued the `DELETE /workflows/{id}` and the workflow disappeared from n8n. Restore from trash was not exercised in this round but uses the same plumbing (separate listener, log-and-swallow on failure).
>
> **v3 supersedes v2.** v2 split soft/hard across two events (`MoveToTrashEvent` + `BeforeNodeDeletedEvent`). v3 collapses to **one listener on `BeforeNodeDeletedEvent`** after confirming in `HookConnector::delete` that the View dispatches `BeforeNodeDeletedEvent` *before* trashbin's `storage->unlink` runs (so it fires for both the trash-move and the trash-purge), and that the dispatch already catches `AbortedEventException` and sets `arguments['run']=false`. That's the abort hook we need; `MoveToTrashEvent` was extra surface area for no benefit (its only abort knob is `disableTrashBin()`, which *bypasses* trash rather than aborting the delete).
>
> `NodeRestoredEvent` stays as the restore hook (separate concern, non-aborting failure semantics).

### TL;DR (rule table, all three lifecycle steps)

| Effective state                              | **Trash-move (soft)** | **Trash-purge (hard)** | **Restore from trash** |
|----------------------------------------------|-----------------------|------------------------|------------------------|
| `mode=reference` (link)                       | untag n8n             | nothing                 | re-tag (if mapping still exists; else log+skip) |
| `mode=sync` + `writeback=readonly` (backup)   | untag n8n             | nothing                 | re-tag (if mapping still exists; else log+skip) |
| `mode=sync` + `writeback=two-way`             | **POST /workflows/{id}/archive** (`isArchived:true`) | **DELETE /workflows/{id}** (hard, unrecoverable) | **POST /workflows/{id}/unarchive** (full restore) |
| **detached** (no `n8n_id`)                    | nothing               | nothing                 | nothing                |

The sync+two-way row is a **clean 1:1 mirror** of NC's own model: NC trash ↔ n8n archive; NC trash-purge ↔ n8n hard-delete; NC restore ↔ n8n unarchive. Files-Metadata travels with the file through trash (file id is preserved), so on restore we still know which `n8n_id` to unarchive.

Reference/backup are not archived in n8n because the n8n workflow is the source of truth there — we just remove the mapping tag so the next pull doesn't re-create the NC file. Restore re-applies the tag (idempotent).

### Why archive (verified live)

Probed against the production instance (throwaway workflow `copilot-archive-probe`, hard-deleted at end):

- `POST /api/v1/workflows/{id}/archive` → **200**, flips `isArchived:true`. Preserves everything: nodes, connections, settings, owner, tags, versionId.
- `POST /api/v1/workflows/{id}/unarchive` → **200**, flips back to `isArchived:false`. Same id, same content, **tags preserved**.
- `DELETE /api/v1/workflows/{id}` works on **active or archived** workflows. Hard delete = unrecoverable via the public API.
- `POST /restore` → 405; `DELETE /archive` → 405 (not valid alternatives).
- Every workflow object now carries an `isArchived` boolean (in single-GET and list responses).
- Aside, relevant to §16 prune (not this section): `GET /workflows?tags=...` **does not** auto-exclude archived ones — n8n community confirmed there's no `?excludeArchived` filter. Prune logic will need to either check `isArchived` per row or accept that an archived workflow keeps its file (matches the "trash" semantics).

### Events wired (as built)

Two listeners, both implemented in `lib/Listener/`:

- **`DeleteToN8nListener`** on `OCP\Files\Events\Node\BeforeNodeDeletedEvent` — handles **both** the trash-move and the trash-purge in a single handler. Step is discriminated by whether the node's path is under `<uid>/files_trashbin/files/`. `AbortedEventException` aborts the NC delete (verified in `\OC\Files\Node\HookConnector::delete`, which catches it and sets `arguments['run']=false`).
- **`RestoreFromTrashListener`** on `OCA\Files_Trashbin\Events\NodeRestoredEvent` — handles restore. Failures are logged + swallowed (we don't strand the user's file in trash because n8n is down).

**Rejected events:** `MoveToTrashEvent` (its only abort knob `disableTrashBin()` *bypasses* trash → still deletes — wrong semantics for our use), `BeforeNodeRestoredEvent` (we don't veto restores), `NodeDeletedEvent` (fires after the file is gone — metadata harder to read).

### Decision flow (as built)

```
on BeforeNodeDeletedEvent(node):
  if SyncGuard active                                    → return       # our own purge/prune
  if not File OR name !endsWith .n8n.json                → return
  meta = WorkflowMetadata::read(fileId)
  id   = meta[n8n_id]; if blank                          → return       # detached: NC delete as usual
  mode      = meta[n8n_mode]
  writeback = meta[n8n_writeback]
  mapping   = MappingService::getById(meta[n8n_mapping])                # may be null
  isHard    = path matches ^/[^/]+/files_trashbin/files/
  try:
      if isHard: DeleteService::hardDelete(id, mode, writeback)
      else:      DeleteService::softDelete(id, mode, writeback, mapping)
  on n8n error → AbortedEventException("…")                             # NC delete aborts cleanly

on NodeRestoredEvent(source, target):
  if SyncGuard active                                    → return
  if target is not File OR name !endsWith .n8n.json      → return
  meta = WorkflowMetadata::read(target.fileId); id = meta[n8n_id]
  if blank                                               → return
  mode, writeback = meta[...]
  mapping = MappingService::getById(meta[n8n_mapping])
  try DeleteService::restore(id, mode, writeback, mapping)
  on any error → log + swallow                                          # never strand a restore
      on error → log + return
```

### `DeleteService` shape (one place for the rules)

```php
class DeleteService {
    /** Soft step on trash-move. */
    public function softDelete(string $id, string $mode, ?string $writeback, ?Mapping $mapping): void {
        if ($mode === Mapping::MODE_SYNC && $writeback === Mapping::WRITEBACK_TWO_WAY) {
            $this->n8n->archiveWorkflow($id);   // POST /workflows/{id}/archive
            return;
        }
        // reference  OR  sync+readonly  → untag (no-op if mapping is null)
        if ($mapping !== null) {
            $this->untagWorkflow($id, $mapping->n8nTag);
        }
    }

    /** Hard step on trash-purge / trash-bypassed delete. */
    public function hardDelete(string $id, string $mode, ?string $writeback): void {
        if ($mode === Mapping::MODE_SYNC && $writeback === Mapping::WRITEBACK_TWO_WAY) {
            $this->n8n->deleteWorkflow($id);    // 404 = treat as success
        }
        // ref/backup: nothing — the workflow has been live in n8n the whole time; only the tag was touched.
    }

    /** Restore step. */
    public function restore(string $id, string $mode, ?string $writeback, ?Mapping $mapping): void {
        if ($mode === Mapping::MODE_SYNC && $writeback === Mapping::WRITEBACK_TWO_WAY) {
            $this->n8n->unarchiveWorkflow($id); // POST /workflows/{id}/unarchive (404 → log+swallow)
            return;
        }
        if ($mapping !== null) {
            $this->ensureTag($id, $mapping->n8nTag); // additive (re-add if missing)
        }
    }

    /** read-modify-write: PUT /workflows/{id}/tags with our tag removed. */
    private function untagWorkflow(string $id, string $tagName): void { /* see below */ }

    /** read-modify-write: PUT /workflows/{id}/tags with our tag id added (additive). */
    private function ensureTag(string $id, string $tagName): void { /* mirror of N8nClient::ensureTag + setWorkflowTags */ }
}
```

### `untagWorkflow` / `ensureTag` mechanics

n8n's `PUT /workflows/{id}/tags` is **set-style** (replaces the workflow's tag list), so both helpers are read-modify-write:

```
workflow = N8nClient::getWorkflow(id)
existing = [ t.id for t in workflow.tags ]

untag:
  desired = [ t.id for t in workflow.tags if t.name != tagName ]
  if desired == existing: return                # tag wasn't on it — noop
  N8nClient::setWorkflowTags(id, desired)

ensureTag:
  tagId = N8nClient::ensureTag(tagName)         # already exists from §17.2
  if tagId in existing: return                  # already tagged — noop
  N8nClient::setWorkflowTags(id, existing + [tagId])
```

Failure modes:
- **404 on workflow** → treat as success (idempotency).
- **n8n unreachable / 5xx** → in `softDelete`, abort the NC trash-move (see open question); in `hardDelete`, abort the NC purge; in `restore`, log + swallow (don't abort — see flow above).

### Files-Metadata stays put through trash (the lynchpin)

NC trash preserves the **file id**, so Files-Metadata (which is keyed by file id) survives the trash-move and is readable on restore via `WorkflowMetadata::read(target.getId())`. We do **not** strip metadata on trash-move; on the final trash-purge it disappears with the row naturally. This is why archive ↔ trash is a clean mirror.

### What additions `N8nClient` needs

Two small methods, each one line of `request()`:
- `archiveWorkflow(string $id): array` → `POST /workflows/{$id}/archive`
- `unarchiveWorkflow(string $id): array` → `POST /workflows/{$id}/unarchive`

Everything else (`getWorkflow`, `setWorkflowTags`, `ensureTag`, `deleteWorkflow`) already exists from §17.2.

### Re-entrancy with sync paths

- `SyncService::purgeManagedFiles` calls `$node->delete()` outside `SyncGuard`. **Bug to fix during this work:** wrap that call in `$this->guard->run(fn() => $node->delete())`, otherwise an admin purge of a two-way mapping would archive every workflow in n8n. Add to the implementation TODO.
- §16 prune (when built) must also wrap its deletes in `SyncGuard::run`.

### Folder delete cascade

Deleting a whole mapped folder (or the Team Folder itself) fires `BeforeNodeDeletedEvent` per descendant file. For a folder of N two-way workflows: N archive calls. Recoverable by restoring the folder from trash (which fires N `NodeRestoredEvent`s, N unarchives). Acceptable at homelab scale (tens, not thousands). A future improvement could batch-archive via tag, but — explicitly NOT v1.

### Out of scope for this listener

- **n8n → NC delete** (workflow deleted in n8n → file should also go in NC): §16 prune step.
- **Bulk confirm UI**: no JS work; v1 is a server-side listener only.
- **Migrating existing trashed files** when this lands: any file already in trash bypasses the soft step entirely (no `BeforeNodeDeletedEvent` fires retroactively). Purging them will still hard-delete if they have `n8n_id`. Owner can accept this or pre-empty the trash before deploy.

### Files added (as built)

- `lib/Service/DeleteService.php` — three rule methods (`softDelete`, `hardDelete`, `restore`) + private `untagWorkflow` / `ensureTag` RMW helpers (additive set on `setWorkflowTags`).
- `lib/Listener/DeleteToN8nListener.php` — single `BeforeNodeDeletedEvent` handler; soft vs hard via path-prefix check.
- `lib/Listener/RestoreFromTrashListener.php` — `NodeRestoredEvent` handler.
- `lib/Listener/MimeRestampListener.php` — `NodeRenamedEvent` handler; closes the rename-erases-mimetype gap (§17.7.1).
- `lib/Service/N8nClient.php` — added `archiveWorkflow($id)` and `unarchiveWorkflow($id)`.
- `lib/AppInfo/Application.php` — registered all three listeners.
- `lib/Service/SyncService.php` — wrapped `purgeManagedFiles`' `$node->delete()` in `SyncGuard::run` so admin purges don't accidentally archive every workflow via the new listener.

### Open question — RESOLVED

**Abort on n8n unreachable on trash-move:** ✅ Owner approved ("abort when n8n is unreachable"). Implemented by throwing `AbortedEventException` from `DeleteToN8nListener` on any non-404 n8n error during the soft step. The View-layer `HookConnector::delete` catches it and sets `arguments['run']=false`, so NC presents an error toast and the file stays where it is. Hard-step failures also abort (purge stays in trash on failure).


### 17.6.2 Name sync — VERIFIED IN PRODUCTION ✅

Owner renamed `MyCoolFlow.n8n.json` → `MyBaddieFlow.n8n.json` in the Files UI; within one cron tick the `ReconcileNameJob` ran and all three converged: filename = JSON `name` = n8n name = "MyBaddieFlow". The full chain (UI rename → `NodeRenamedEvent` → `NameSyncListener` enqueue → cron → job writes JSON + pushes n8n) works end to end. §17.6 is **done**.

Further verified on `FlowBurger.n8n.json` → `FlowBurrito.n8n.json`: filename + JSON name + n8n name + custom icon all stay in sync after rename (see §17.7.1 for the mimetype fix that made the icon survive renames).

---

## 17.7.1 Mimetype-on-rename fix — VERIFIED IN PRODUCTION ✅

**Two-part bug, found in pieces:**

1. **Rename leg.** Renaming a managed file in the Files UI (e.g. `New Workflow.n8n.json` → `FlowBurger.n8n.json`) erased the custom icon. NC's rename pipeline runs the new filename through `\OC\Files\Type\Detection::detectPath()`, which only inspects the **last** extension segment (`json`) and resets the filecache `mimetype` column to `application/json`. Fixed by `lib/Listener/MimeRestampListener.php` on `NodeRenamedEvent`.

2. **Cron-tick regression leg.** After 1️⃣ landed, the icon would come back briefly and then disappear again ~one cron tick later. Cause: the next `ReconcileNameJob` rewrites the JSON content via `putContent()` (inside `SyncGuard::run`). NC's `Updater::update` calls `Scanner::scan(SHALLOW)` on every content write, which re-detects mime from the path → `application/json` again. Our existing `NodeWrittenListener::restampMimetype()` was supposed to catch this, but it was gated by an early `SyncGuard::active()` short-circuit — so it never ran on our own writes (which are all SyncGuard-wrapped on purpose). Fixed by moving `restampMimetype()` **above** the guard check in `NodeWrittenListener::handle()`. The mime UPDATE only touches the `mimetype` column and does not refire `NodeWrittenEvent`, so it's loop-safe even on guarded paths.

**Coverage now:** every code path that touches a `*.n8n.json` row's mime is re-asserted:
- external save (WebDAV PUT, editor, desktop client) → `NodeWrittenEvent` → restamp
- our own save (`ReconcileNameJob`, `SyncService`, `CreateService`) → `NodeWrittenEvent` (guarded) → restamp still runs
- rename → `NodeRenamedEvent` → `MimeRestampListener` → restamp
- cold-start / migration → `RegisterMimetype` repair step (one-shot at install/upgrade)

**Verified:** owner renamed `FlowBurger.n8n.json` → `FlowBurrito.n8n.json`; icon survives both the rename and the subsequent cron tick that propagates the name into the JSON. One existing broken row was repaired inline by running `IMimeTypeLoader::updateFilecache('n8n.json', application/n8n+json)` directly — note that `occ maintenance:mimetype:update-db` does NOT cover compound extensions and never updates these rows.

---

## 18.1 Handoff #2 — for the agent taking over (now planning DELETE)

**Status:** Phase 0–5, admin Data Sync (manual + scheduled), create-on-land (§17.2, owner-verified earlier), **three-way name sync (§17.6), delete/restore (§17.7), and the rename-mimetype fix (§17.7.1) are all live + verified in production.** Files in `N8N Tasking` / `N8N Admin Test` are consistent (filename ≡ JSON `name` ≡ n8n name) and survive rename cycles with their custom icon. Right-click "Delete file" trash + permanent purge cleanly mirror to n8n archive + hard-delete. The current New-menu test workflow is `FlowBurrito.n8n.json`.

### Hard-won lessons (apply these to DELETE)
1. **You cannot write/rename/mutate a file synchronously inside its own `NodeWrittenEvent`/`NodeRenamedEvent` handler — the file is locked** (`OCP\Lock\LockedException`). **Reads** (`getContent`, shared lock) are fine in-handler. Any *write/rename/delete* that the event implies must be **deferred to a `QueuedJob`** (runs after the request commits, next cron tick). This is the pattern: listener decides + enqueues; job does the lock-sensitive work. See `NameSyncListener` → `ReconcileNameJob` and the async writeback (`PushWorkflowJob`).
   - Consequence: these reconciliations are **cron-paced (~≤60s)**, not instant. Set that expectation in any UX.
2. **The `base.php` CLI harness can't faithfully exercise event-driven file ops** — nested move/write→event→read trips NC's "dirty table reads" guard, and heavy `pullOne` in one CLI process OOM-kills (exit 137). Verify event flows **in the UI**, or test **service/job logic directly** (reflection on the job's `run`) against **throwaway** n8n workflows (create + tag + `pullOne` a *light* mapping like `N8N Admin Test`, then delete). Never test against the owner's real workflows.
3. **Dropping the `.n8n.json` extension un-manages a file** (extension gate), and a subsequent pull re-creates a fresh `<n8nname>.n8n.json` for the same `n8n_id` → **duplicate NC files for one workflow**. Real dedupe-by-`n8n_id` belongs to §16 reconciliation/prune.
4. Declarative INTERNAL storage types differ (checkbox = bool-typed, text/select = string-typed) → **read defensively** (`getValueBool` for the checkbox, `getValueString`+cast for text). See `ScheduledPullJob`.
5. Deploy = `kubectl cp` individual changed files (not whole dirs — avoid clobbering the other agent) + `chown www-data` + `rollout restart`. **Never bump `info.xml <version>`** (DB-upgrade crash-loop, §13).
6. **Compound extensions silently lose their mimetype on EVERY content touch, not just rename.** NC's `Detection::detectPath()` only inspects the last extension segment, and TWO independent code paths use it: (a) the rename pipeline directly, (b) `Updater::update` → `Scanner::scan(SHALLOW)` on every `putContent`. So `.n8n.json` resolves to `application/json` and silently overwrites our `application/n8n+json` row. Fix: re-stamp on **both** `NodeRenamedEvent` (covers leg a) and `NodeWrittenEvent` (covers leg b — and crucially must run **before** any `SyncGuard::active()` short-circuit, since our own jobs write inside the guard). See `MimeRestampListener` + `NodeWrittenListener::restampMimetype` (§17.7.1). Also note: `occ maintenance:mimetype:update-db` does NOT cover compound extensions — call `IMimeTypeLoader::updateFilecache` directly for one-shot repairs.

### DELETE — design notes to weigh
- **See §17.7 v3 (implemented) for the full design.** TL;DR: **one** listener on `BeforeNodeDeletedEvent` covers both the trash-move and the trash-purge (path-prefix `<uid>/files_trashbin/files/` discriminates) — soft = archive in n8n for sync+two-way / untag for ref+backup, hard = `DELETE /workflows` for sync+two-way only. Plus a `NodeRestoredEvent` listener for unarchive/re-tag. Failures on delete throw `AbortedEventException` (caught in `HookConnector::delete` → NC aborts cleanly); failures on restore are logged + swallowed. Archive/unarchive verified live on the production n8n instance; Files-Metadata survives trash because file id is preserved.
- **Safety:** still guard against echoes via `SyncGuard`; fix the existing `SyncService::purgeManagedFiles` (wrap its `$node->delete()` in `SyncGuard::run`) so admin purges don't archive every workflow.
- **Reuse the deferred-job pattern** for the n8n API call so a delete during the event isn't lock-bound.
- **Ties into §16 (prune) and §17.1 (eject):** "untag in n8n → file removed in NC" (prune) and "move file out of mapping → delete from n8n + keep local backup" (eject) are the sibling flows; keep delete semantics coherent with both. The current `MoveGuardListener` still **vetoes** move-out — §17.1 will replace that with eject; coordinate so name-sync's within-folder renames keep flowing while only out-of-mapping moves eject/delete.
