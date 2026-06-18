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

### Create a Workflow

Drop a `.n8n.json` file into a mapped sync folder — either by creating a new file, uploading one, or copying an existing workflow file in — and the app automatically registers it as a real n8n workflow. The mapping tag is added to the new workflow, and the file is stamped with the workflow's ID so it's fully managed from that point on.

This means you can author a workflow in your editor of choice, drop it into the sync folder, and have it live in n8n without ever touching the n8n UI.

### Renaming

In **Sync mode**, renaming is three-way: the filename stem, the JSON `name` field inside the file, and the workflow name in n8n are always kept in agreement.

- Rename the file → the JSON is updated and n8n is notified
- Edit the `name` field inside the JSON → the file is renamed to match, and n8n is notified
- Rename in the n8n UI → the next pull renames the file to match

The stable link is the workflow ID, not the filename, so none of these renames break the connection. In **Backup** and **Link** modes, the filename follows whatever n8n calls the workflow on the next pull.

### Moving Files

Files in a sync folder can be freely moved within subfolders of that folder. Moving a managed workflow file *out* of its mapped folder is blocked — the app will reject the move with a clear message. This prevents the workflow from ending up in an unmanaged location where sync would silently stop working.

### Deleting Files

Deletion mirrors Nextcloud's own two-step trash model:

| Action | Sync mode | Backup / Link mode |
|---|---|---|
| Move to trash | Workflow is **archived** in n8n (hidden, preserved) | Mapping tag stripped from workflow |
| Purge from trash | Workflow is **permanently deleted** from n8n | No-op |
| Restore from trash | Workflow is **unarchived** in n8n | Mapping tag re-added |

If n8n is unreachable when you delete, the delete is aborted — the file stays in Nextcloud rather than the two systems getting out of sync. Restoring is forgiving in the opposite direction: if n8n is down during a restore, the restore still completes and the re-sync happens on the next pull.

### WebDAV Metadata & MIME Type

Every `.n8n.json` file is a first-class WebDAV resource. The app registers a custom MIME type (`application/n8n+json`) so the files show an n8n icon in the Files app instead of a generic JSON icon.

The following properties are embedded directly in the DAV resource and are readable by any WebDAV client via `PROPFIND`:

| DAV property | What it contains |
|---|---|
| `nc:metadata-n8n_id` | The workflow's ID in n8n |
| `nc:metadata-n8n_mode` | `sync` or `reference` |
| `nc:metadata-n8n_writeback` | `two-way`, `readonly`, or empty |
| `nc:metadata-n8n_versionId` | The version ID of the last successful sync |
| `nc:metadata-n8n_mapping` | The mapping this file belongs to |

These properties are read-only — clients cannot modify them via `PROPPATCH`. They are maintained entirely by the sync engine.

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

For scripting and troubleshooting, two `occ` commands are available:

```sh
# List workflows from n8n (smoke-tests the REST client)
occ n8n_sync:list-workflows
occ n8n_sync:list-workflows --limit=10 --tag=my-tag

# Fetch a single workflow by ID
occ n8n_sync:get-workflow <workflow-id>
```

Both commands output raw JSON and exit with code `0` on success, `1` on error.
