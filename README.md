# n8n Sync

A Nextcloud app that surfaces n8n workflows as native files — browse, edit, and manage your automation workflows right inside the Files app, with full bidirectional sync back to n8n.

---

## How It Works

n8n Sync maps one or more n8n workflow tags to Nextcloud folders. Every workflow carrying a mapped tag appears in the corresponding folder as a `.n8n.json` file. Depending on the sync mode you choose, changes you make in Nextcloud can push back to n8n automatically, and changes made in n8n pull back into Nextcloud on a schedule.

```
n8n (tagged workflows) ⟺ Nextcloud (mapped folder)
```

The sync is reconcile-based: re-running a pull never duplicates files. The link between a file and its workflow is tracked by a stable workflow ID embedded in the file's metadata — not by filename — so renaming works without breaking anything.

---

## Sync Modes

Each mapped folder runs in one of three modes. The mode controls how much authority Nextcloud has over the workflow.

| Mode | File content | Edits push to n8n? | Tag shown |
|---|---|---|---|
| **Sync** | Full workflow JSON | Yes — bidirectional | `n8n:sync` |
| **Backup** | Full workflow JSON | No — read-only copy | `n8n:backup` |
| **Link** | Tiny pointer (id, name, URL) | No — reference only | `n8n:link` |

### Sync

Full two-way ownership. The workflow JSON lives in Nextcloud and any save (via the web editor, WebDAV, or your desktop client) pushes the updated workflow back to n8n in real time. Renaming the file renames the workflow in n8n, and vice versa. Deleting the file archives the workflow in n8n; purging from trash deletes it permanently.

### Backup

The full workflow JSON is pulled into Nextcloud so you have a readable, searchable copy — but Nextcloud is not authoritative. Edits you make locally are not pushed back. When you delete the file, the workflow is simply untagged in n8n (not archived or deleted). Use this when you want a Nextcloud mirror for visibility or audit purposes.

### Link

A lightweight read-only pointer. The file contains only the workflow ID, name, URL, and tags — not the full JSON. Deleting the link just untags the workflow in n8n; the workflow itself is untouched. Use this to give a team folder a "shortcut" to a workflow that lives elsewhere.

---

## Features

This is a high-level showcase. Each feature links down to its **executable
specification** — a Gherkin `.feature` file under [`features/`](features/) that
describes the exact behaviour in plain language and drives the integration tests
— and to the **code** that implements it. Docs, tests, and code are meant to stay
aligned: the `.feature` files *are* the requirements.

### Create a workflow from Nextcloud

Make a `.n8n.json` file in a mapped sync folder (new file, upload, or copy-in) and
the app registers it as a real n8n workflow — tagged with the mapping and stamped
with the workflow's ID. Author in your editor of choice; it goes live in n8n
without opening the n8n UI. A file created **outside** any mapped folder stays a
plain, unmanaged document.

📋 spec: [`features/create-workflow.feature`](features/create-workflow.feature) · 🛠 [`lib/Listener/CreateInN8nListener.php`](lib/Listener/CreateInN8nListener.php)

### Mapping membership follows the folder

Folder mappings are **metadata on the folder**, so a file's mapping is resolved by
where it lives. Because mappings are per-folder, you can map a folder **inside** an
already-mapped folder — the nearest enclosing mapping wins.

📋 spec: [`features/mapping-membership.feature`](features/mapping-membership.feature) · 🛠 [`lib/Service/MappingService.php`](lib/Service/MappingService.php)

### Moving files (safe by default)

Nextcloud lets you move a file anywhere — so the app guards the moves that would
break the n8n link. A managed workflow may move freely **within its own mapping**
(rename, or into a subfolder of the same mapped folder). Moving it **out** of its
mapped folder, or into a **different** mapping, is currently **aborted with a
message** — a deliberate block so sync never silently stops. The `move.feature`
spec walks every branch (out / subfolder / mapped→mapped / nested-different-mapping).

**Planned end state:** moving a **sync** workflow *out* of its folder will instead
**strip its n8n metadata**, leaving a plain `.n8n.json` document in Nextcloud (no
longer tracked in n8n); moving it back into a mapped folder will **re-create** it
in n8n and re-stamp the metadata — a move in Nextcloud, a create in n8n. This was a
Chapter-1 leftover whose prerequisites (the delete/restore lifecycle, metadata
contract) now exist; it is intentionally **not implemented until the current
behaviour is covered by passing integration tests**. **Link** and **backup**
move-out stays blocked (not yet designed). See Chapter 2 of the saga for the plan.

📋 spec: [`features/move.feature`](features/move.feature) · 🛠 [`lib/Listener/MoveGuardListener.php`](lib/Listener/MoveGuardListener.php)

### Renaming (three-way)

In **sync mode** the filename stem, the JSON `name` field, and the n8n workflow
name are kept in agreement. Rename the file → the JSON and n8n update. Edit the
`name` inside the JSON → the file is renamed and n8n updates. The stable link is
the workflow ID, so no rename ever breaks the connection.

📋 spec: [`features/rename.feature`](features/rename.feature) · 🛠 [`lib/Listener/NameSyncListener.php`](lib/Listener/NameSyncListener.php), [`lib/Service/FilenameCodec.php`](lib/Service/FilenameCodec.php)

### Deleting (mode-aware)

Deletion mirrors Nextcloud's two-step trash model, and what happens in n8n depends
on the mode:

| Action | Sync | Backup / Link | Unmapped |
|---|---|---|---|
| Move to trash | Workflow **archived** in n8n | Mapping tag stripped | nothing |
| Purge from trash | Workflow **permanently deleted** | no-op | nothing |
| Restore from trash | Workflow **unarchived** | Mapping tag re-added | nothing |

If n8n is unreachable on delete, the delete aborts (the file stays) rather than
desyncing the two systems.

📋 spec: [`features/delete.feature`](features/delete.feature) · 🛠 [`lib/Service/DeleteService.php`](lib/Service/DeleteService.php), [`lib/Listener/DeleteToN8nListener.php`](lib/Listener/DeleteToN8nListener.php)

### A first-class file type: custom icon, click-to-open, DAV metadata

A managed workflow isn't a generic JSON blob — it's a proper file type. The app
registers the `application/n8n+json` mimetype, so files show the **n8n icon** and a
**click opens the workflow directly in n8n** (not a download, not the text editor).
And every file's state is exposed over WebDAV: a raw `PROPFIND` returns the
metadata in its XML —

| DAV property | What it contains |
|---|---|
| `nc:metadata-n8n_id` | The workflow's ID in n8n |
| `nc:metadata-n8n_mode` | `sync` or `reference` |
| `nc:metadata-n8n_writeback` | `two-way`, `readonly`, or empty |
| `nc:metadata-n8n_versionId` | The version ID of the last successful sync |
| `nc:metadata-n8n_mapping` | The mapping this file belongs to |

These properties are read-only — clients cannot change them via `PROPPATCH`; the
sync engine owns them.

📋 spec: [`features/file-type.feature`](features/file-type.feature) · 🛠 [`src/files.js`](src/files.js), [`lib/Migration/RegisterMimetype.php`](lib/Migration/RegisterMimetype.php), [`lib/Service/WorkflowMetadata.php`](lib/Service/WorkflowMetadata.php)

### Tagging

Each managed file carries exactly one system tag indicating its sync state:

| Tag | Meaning |
|---|---|
| `n8n:sync` | Full JSON, edits push back to n8n |
| `n8n:backup` | Full JSON, read-only copy |
| `n8n:link` | Pointer only, no writeback |

Tags are visible as colored pills in the Files app. They are mutually exclusive — the app enforces only one per file. Tags survive metadata wipes and can also be used to manually opt a hand-crafted file into management.

### Bidirectional Sync

**Nextcloud → n8n** happens on every file save for Sync-mode files. The app compares the file's content hash to the last-pushed hash so unchanged files are never pushed twice. Pushes can go via the REST API, a webhook, or both simultaneously.

**n8n → Nextcloud** happens on a schedule you configure, or on-demand via the "Sync from n8n" button in the admin panel. On each pull, the app fetches all workflows carrying the mapped tag, writes or updates the corresponding files, and reconciles filenames — matching on workflow ID so renames don't create duplicates.

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
| **Enable Webhook** | Toggle writeback via webhook. When on, file saves POST to the configured webhook path in n8n. |
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
| **Sync Mode** | `sync` or `reference` — see [Sync Modes](#sync-modes) above. |
| **Writeback** | For sync mode: `two-way` (edits push to n8n) or `readonly` (backup copy). |

**Constraints:**
- The storage backend (Team Folder vs admin-owned) is fixed at creation time. Switching requires deleting and recreating the mapping.
- The app never creates groups — it only uses groups that already exist.

**Per-mapping sync controls** let you pull or push an individual mapping without triggering a full sync across all folders.

---

## CLI Commands

Every admin action is available over `occ`, so the whole connection + mappings setup can be
automated (e.g. from a Kubernetes init/config job) — the same operations as the admin Settings
panel. All commands exit `0` on success and non-zero on error.

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
# Add a mapping (JSON: n8n_tag → team_folder, with mode + writeback).
# mode: "sync" (writeback "two-way"|"readonly") or "reference" (link, no writeback).
occ n8n_sync:add-mapping '{"n8n_tag":"nextcloud:alpha","team_folder":"alpha","nc_groups":["admins"],"mode":"sync","writeback":"two-way","use_team_folder":true}'

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
