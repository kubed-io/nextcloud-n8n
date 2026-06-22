# n8n Sync

A Nextcloud app that surfaces n8n workflows as native files — browse, edit, and manage your automation workflows right inside the Files app, with full bidirectional sync back to n8n.

---

## How It Works

n8n Sync maps one or more n8n workflow tags to Nextcloud folders. Every workflow carrying a mapped tag appears in the corresponding folder as a `.n8n.json` file. Depending on the mode you choose, changes you make in Nextcloud push back to n8n automatically, and changes made in n8n pull back into Nextcloud on a schedule.

```
n8n (tagged workflows) ⟺ Nextcloud (mapped folder)
```

The sync is reconcile-based: re-running a pull never duplicates files. The link between a file and its workflow is a stable workflow ID embedded in the file's metadata — not the filename — so renaming, moving, and restoring all work without ever breaking the connection.

---

## Modes

Every managed `.n8n.json` file is in exactly one of three modes. The mode is the single source of truth for how much authority Nextcloud has over the workflow — there is no separate "writeback" setting to reason about.

| Mode | File content | Pushes to n8n? | Tag |
|---|---|---|---|
| **Sync** | Full workflow JSON | Yes — bidirectional | `n8n:sync` |
| **Link** | Tiny pointer (id, name, URL) | No — click opens n8n | `n8n:link` |
| **Unmapped** | Full workflow JSON, no longer in a mapping | No | *(none)* |

### Sync

Full two-way ownership. The workflow JSON lives in Nextcloud and any save — via the web editor, WebDAV, or your desktop client — pushes the updated workflow back to n8n. Renaming the file renames the workflow in n8n, and vice versa. Because Nextcloud always holds the complete JSON, a sync folder is also a full, restorable backup of every workflow in it.

### Link

A lightweight pointer. The file holds only the workflow's ID, name, and URL — not the full JSON. Clicking it opens the workflow in n8n (links are read-only by nature: you edit in n8n, not in the file). Deleting a link just untags the workflow in n8n; the workflow itself is untouched. Use a link to give a folder a "shortcut" to a workflow that lives elsewhere.

### Unmapped

When you **move** a sync workflow *out* of its mapped folder, it becomes **unmapped**: Nextcloud keeps the full JSON (and the workflow's identity), while the workflow is archived in n8n. The file is now a free-standing, self-contained copy you can keep anywhere. Move it back into any mapping and the workflow is **restored** in n8n — same workflow, not a new one. An unmapped file is, in effect, a portable archive of a workflow.

---

## Features

This is a high-level showcase. Each feature links to its **executable specification** — a Gherkin `.feature` file under [`features/`](features/) that describes the exact behaviour in plain language and drives the integration tests — and to the **code** that implements it. Docs, tests, and code stay aligned: the `.feature` files *are* the requirements.

### Create a workflow from Nextcloud

Make a `.n8n.json` file in a mapped sync folder (new file, upload, or move-in) and the app registers it as a real n8n workflow — tagged with the mapping and stamped with the workflow's ID. Author in your editor of choice; it goes live in n8n without opening the n8n UI. A file created **outside** any mapped folder stays a plain, untracked document.

📋 spec: [`features/create-workflow.feature`](features/create-workflow.feature) · 🛠 [`lib/Listener/CreateInN8nListener.php`](lib/Listener/CreateInN8nListener.php)

### Mapping membership follows the folder

Folder mappings are **metadata on the folder**, so a file's mapping is resolved by where it lives. Because mappings are per-folder, you can map a folder **inside** an already-mapped folder — the nearest enclosing mapping wins.

📋 spec: [`features/mapping-membership.feature`](features/mapping-membership.feature) · 🛠 [`lib/Service/MappingService.php`](lib/Service/MappingService.php)

### Moving a workflow (it's the same workflow, leaving and coming back)

A move in Nextcloud mirrors as the *same workflow* moving in n8n — never a duplicate.

- **Within its own mapping** (rename, or into a subfolder of the same mapped folder): stays managed; nothing changes in n8n.
- **Out of its mapped folder** (sync only): the file becomes **unmapped** — Nextcloud keeps the full JSON and the workflow's ID, and the workflow is **archived** in n8n. Nothing is lost; you simply hold the only live copy in Nextcloud.
- **Back into any mapping**: if the file still carries its workflow ID, the workflow is **restored** (unarchived) in n8n — the same workflow returns, not a fresh one.
- **A link** cannot be moved out of its mapping (ejecting a pointer is meaningless); that move is refused with a message.

📋 spec: [`features/move.feature`](features/move.feature) · 🛠 [`lib/Listener/MoveGuardListener.php`](lib/Listener/MoveGuardListener.php)

### Copying a workflow (always a brand-new instance)

Where a move is "the same workflow," a **copy** is always a *new* one. A copied file never carries the original's n8n identity — its metadata is stripped the moment it is copied.

- **Copy within a mapped sync folder** → the copy becomes a **new** workflow in n8n (new id, its own name).
- **Copy to outside any mapping** → a plain, untracked `.n8n.json`.
- **Copy of an unmapped file** → metadata stripped wherever it lands; it's a new instance.

So duplicating a workflow is as simple as copying its file, and you never have to worry about a copy silently hijacking the original's n8n workflow.

📋 spec: [`features/copy.feature`](features/copy.feature) · 🛠 [`lib/Listener/MoveGuardListener.php`](lib/Listener/MoveGuardListener.php)

### Renaming (three-way)

In **sync mode** the filename stem, the JSON `name` field, and the n8n workflow name are kept in agreement. Rename the file → the JSON and n8n update. Edit the `name` inside the JSON → the file is renamed and n8n updates. The stable link is the workflow ID, so no rename ever breaks the connection.

📋 spec: [`features/rename.feature`](features/rename.feature) · 🛠 [`lib/Listener/NameSyncListener.php`](lib/Listener/NameSyncListener.php), [`lib/Service/FilenameCodec.php`](lib/Service/FilenameCodec.php)

### Deleting (mode-aware)

Deletion mirrors Nextcloud's two-step trash model, and what happens in n8n depends on the mode:

| Action | Sync | Link | Unmapped |
|---|---|---|---|
| Move to trash | Workflow **archived** in n8n | Mapping tag stripped | nothing (already archived) |
| Purge from trash | Workflow **permanently deleted** | no-op | Workflow **permanently deleted** |
| Restore from trash | Workflow **unarchived** | Mapping tag re-added | nothing |

If n8n is unreachable on delete, the delete aborts (the file stays) rather than desyncing the two systems.

📋 spec: [`features/delete.feature`](features/delete.feature) · 🛠 [`lib/Service/DeleteService.php`](lib/Service/DeleteService.php), [`lib/Listener/DeleteToN8nListener.php`](lib/Listener/DeleteToN8nListener.php)

### Reconcile & prune (no duplicates, ever)

Because a move-out archives the workflow in n8n while Nextcloud keeps the copy, a duplicate can briefly appear if someone independently **restores that workflow in n8n** — the next pull would bring it back into its mapped folder while the unmapped copy still exists. The reconcile pass detects this (two files, one workflow ID — one mapped, one unmapped) and **prunes the redundant unmapped copy**, keeping the authoritative n8n-sourced one.

📋 spec: [`features/reconcile.feature`](features/reconcile.feature) · 🛠 [`lib/Service/SyncService.php`](lib/Service/SyncService.php)

### A first-class file type: custom icon, click-to-open, DAV metadata

A managed workflow isn't a generic JSON blob — it's a proper file type. The app registers the `application/n8n+json` mimetype, so files show the **n8n icon** and a **click opens the workflow directly in n8n** (not a download, not the text editor). And every file's state is exposed over WebDAV: a raw `PROPFIND` returns the metadata in its XML —

| DAV property | What it contains |
|---|---|
| `nc:metadata-n8n_id` | The workflow's ID in n8n |
| `nc:metadata-n8n_mode` | `sync`, `reference`¹, or `unmapped` |
| `nc:metadata-n8n_versionId` | The version ID of the last successful sync |
| `nc:metadata-n8n_mapping` | The mapping this file belongs to (empty when unmapped) |

¹ `reference` is the on-the-wire value for **link** mode. The two are synonyms; the metadata value is stored as `reference` *only* because Nextcloud's PROPFIND treats a stored value equal to the built-in `link()` function as a callback (so the literal string `link` would crash it). Everywhere else — UI, tag, docs — it's **link**.

These properties are read-only — clients cannot change them via `PROPPATCH`; the sync engine owns them. `n8n_mode` is indexed, so "find every sync workflow" / "find every unmapped file" is a fast metadata query.

📋 spec: [`features/file-type.feature`](features/file-type.feature) · 🛠 [`src/files.js`](src/files.js), [`lib/Migration/RegisterMimetype.php`](lib/Migration/RegisterMimetype.php), [`lib/Service/WorkflowMetadata.php`](lib/Service/WorkflowMetadata.php)

### Tagging

Each managed file carries exactly one system tag indicating its mode:

| Tag | Meaning |
|---|---|
| `n8n:sync` | Full JSON, edits push back to n8n |
| `n8n:link` | Pointer only, click opens n8n |

Tags are visible as coloured pills in the Files app. They are mutually exclusive — the app enforces only one per file. Tags survive metadata wipes and can also be used to manually opt a hand-crafted file into management.

### Bidirectional Sync

**Nextcloud → n8n** happens on every file save for sync-mode files. The app compares the file's content hash to the last-pushed hash so unchanged files are never pushed twice. Pushes can go via the REST API, a webhook, or both simultaneously.

**n8n → Nextcloud** happens on a schedule you configure, or on-demand via the "Sync from n8n" button in the admin panel. On each pull, the app fetches all workflows carrying the mapped tag, writes or updates the corresponding files, reconciles filenames (matching on workflow ID so renames don't create duplicates), and prunes any redundant unmapped copies.

A request-scoped guard prevents the app from pushing its own pull writes back to n8n (the classic bidirectional sync loop problem).

---

## Administration

### n8n Instance

| Setting | Description |
|---|---|
| **n8n URL** | Base URL of your n8n instance, e.g. `https://n8n.example.com`. No trailing slash. Used by both the REST API and webhook channels. |

---

### REST API

| Setting | Description |
|---|---|
| **Enable REST API** | Master toggle for the REST API channel. When on, file saves and pulls communicate with n8n via its REST API. Pull and Test buttons always use the REST API regardless of this toggle. |
| **API Key** | Your n8n REST API key. Sent as `X-N8N-API-KEY` on every request. Stored encrypted — never echoed back after saving. |

---

### Webhook

The webhook channel provides a second push path alongside the REST API. You can run both for belt-and-suspenders reliability, or use the webhook alone if REST write-back is disabled.

| Setting | Description |
|---|---|
| **Enable Webhook** | Toggle push via webhook. When on, file saves POST to the configured webhook path in n8n. |
| **Webhook Path** | Path under the base URL where your n8n workflow receives pushes, e.g. `/webhook/n8n-sync`. |
| **Webhook Token** | Optional Bearer token for webhook authentication. Leave empty for unauthenticated webhooks. Stored encrypted. |

A **Test Webhook** button is available to verify the webhook is reachable. It posts to n8n's `/webhook-test/` variant of your path so a test trigger reaches a waiting workflow without activating a production one.

---

### Sync Schedule

| Setting | Description |
|---|---|
| **Enable scheduled sync** | Master toggle for automatic n8n → Nextcloud pulls. When off, use the "Sync from n8n" button manually. |
| **Sync interval** | How often to pull from n8n. Format: `<number><unit>` — e.g. `15m`, `1h`, `6h`, `1d`. Minimum 1 minute. Changes take effect on the next cron tick. |
| **Push timing** | **async** (recommended): push runs in the background after save. **sync**: push runs inline during save for immediate feedback. |

---

### Folder Mappings

A mapping binds an n8n workflow tag to a Nextcloud folder and defines who can see it and in what mode.

| Field | Description |
|---|---|
| **n8n Tag** | The n8n tag whose workflows sync into this folder. Must be unique across all mappings — one folder per tag. Cannot contain commas. |
| **Folder** | The Nextcloud mount point where workflows appear. Backed by either a Team Folder (ownerless, requires the groupfolders app) or an admin-owned shared folder. |
| **Groups** | The Nextcloud groups who can access the folder. At least one group is required for anyone to see the folder. |
| **Mode** | `sync` or `link` — see [Modes](#modes) above. (`unmapped` is a *file* state produced by moving a sync file out of a mapping; it is never something you configure on a mapping.) |

**Constraints:**
- The storage backend (Team Folder vs admin-owned) is fixed at creation time. Switching requires deleting and recreating the mapping.
- The app never creates groups — it only uses groups that already exist.

**Per-mapping sync controls** let you pull or push an individual mapping without triggering a full sync across all folders.

---

## CLI Commands

Every admin action is available over `occ`, so the whole connection + mappings setup can be automated (e.g. from a Kubernetes init/config job) — the same operations as the admin Settings panel. All commands exit `0` on success and non-zero on error.

### Configure the connection

```sh
# Point the app at your n8n instance
occ config:app:set n8n_sync n8n_url --value="https://n8n.example.com"

# Store your n8n API key (encrypted, exactly as the Settings panel does).
# Pass it as an argument, or pipe it on stdin to keep it out of shell history:
echo "$N8N_API_KEY" | occ n8n_sync:set-api-key

# Turn the REST API channel on
occ config:app:set n8n_sync api_enabled --value=1

# Verify it all works — the headless "Test connection" button
occ n8n_sync:test-connection
```

### Manage folder mappings

```sh
# Add a mapping (JSON: n8n_tag → folder, with a mode).
# mode: "sync" (full two-way JSON) or "link" (pointer, click opens n8n).
occ n8n_sync:add-mapping '{"n8n_tag":"nextcloud:alpha","team_folder":"alpha","nc_groups":["admins"],"mode":"sync","use_team_folder":true}'

# List the configured mappings (JSON)
occ n8n_sync:list-mappings

# Remove a mapping by its id (from list-mappings)
occ n8n_sync:remove-mapping <mapping-id>
```

### Inspect workflows (smoke tests)

```sh
# List workflows from n8n (smoke-tests the REST client)
occ n8n_sync:list-workflows
occ n8n_sync:list-workflows --limit=10 --tag=my-tag

# Fetch a single workflow by ID
occ n8n_sync:get-workflow <workflow-id>
```
