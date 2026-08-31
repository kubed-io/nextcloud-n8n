<!--
SPDX-FileCopyrightText: 2026 Kelly Ferrone
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Chapter 6 — The Long Run

> **Prerequisite:** Chapter 5 (*The Marquee and the Meal*) closed on the thing it
> set out to do: `n8n_sync 1.0.0` shipped to
> [apps.nextcloud.com](https://apps.nextcloud.com/apps/n8n_sync), cut from `main`
> by the three-stage publish pipeline Chapter 4 built.
>
> **A show that has opened is a different job from a show that is opening.** The
> marquee is lit and the room is full; nobody is coming back to applaud the
> lighting rig. What matters now is the two hundredth performance being as good as
> the first — which means the defects that only appear once real people use the
> thing in ways nobody rehearsed.
>
> Chapter 5 carried three of those out with it: the unbind-window defect, the
> deferred webhook channel, and the body↔pills projection still unwired. This
> chapter starts with a fourth that Chapter 5 never knew about, because it was
> found in a sibling's kitchen.
>
> Chapters 1–4 got the show made and on. Chapter 5 got it sold.
> **Chapter 6 is the run.**

---

## Status: **OPEN** — 2026-08-31

---

## The doctrine — the sibling's bug is your bug until you have checked

Three apps, one shape: `nextcloud-n8n`, `nextcloud-penpot`, `nextcloud-grafana`.
They provision folders the same way, share them the same way, resolve the same
sync actor, and copy each other's listeners deliberately. That is the whole point
of having siblings — the second app costs a fraction of the first.

**It also means a defect found in one is a defect in all three until somebody
looks.** And the looking is the part that does not happen on its own:

> **A fix that stays in the app that found it is half a fix.** §6.1 is a bug
> penpot found, diagnosed and fixed — while n8n and Grafana shipped it untouched
> for weeks, in code that had been copied from penpot in the first place.

The rule that comes out of it: **when a sibling fixes something in shared-shape
code, the other two get a note the same day, whether or not anyone has time to
port it.** A note costs minutes. This one cost an afternoon of re-deriving a
diagnosis that was already written down two repositories away.

---

## §6.1 — The share this app creates cannot be seen by anyone

**Found in the apprentice's kitchen.** `nextcloud-grafana` hit it on a live
instance; `nextcloud-penpot` had already hit it and already fixed it. This app
shipped it.

### The symptom, which gives you nothing to go on

An admin creates a mapping backed by an **admin-owned folder** (not a Team
Folder) and shares it with groups. The folder is provisioned, the workflows land
in it, the share rows are written — and the folder is **invisible to every member
of every group it is shared with**.

No error. No notification. No log line. Nothing in the Files app to click. To the
admin, a folder full of their own files has simply vanished.

### The cause

`DefaultShareProvider::create()` writes `accepted = STATUS_PENDING` for every
share it makes, unconditionally. There is no auto-accept flag to set and no
argument to `createShare()` that changes it. `Files_Sharing`'s mount provider then
declines to mount it:

```php
// apps/files_sharing/lib/MountProvider.php
if ($parentShare->getStatus() !== IShare::STATUS_ACCEPTED
    && ($parentShare->getShareType() === IShare::TYPE_GROUP
        || $parentShare->getShareType() === IShare::TYPE_USERGROUP
        || $parentShare->getShareType() === IShare::TYPE_USER)
) {
    continue;
}
```

A bare `continue`. And **a group share raises no acceptance prompt the way a user
share does**, so the member has nothing to accept even if they knew to look.

### Why the diagnosis is worth keeping rather than repeating

Every other layer looks correct, so all the obvious checks pass and cost an hour.
Measured on the live instance, in this order, all green:

| checked | answer |
|---|---|
| the share rows against a working folder's, column by column | identical |
| the filecache parent chain | identical |
| can the OWNER see the folder | yes, `PROPFIND` 207 |
| group membership (LDAP-backed) | resolves; the user is in 3 of the shared groups |
| `IShareManager::getSharedWith()` | **returns the shares** |
| `MountProvider::getMountsForUser()` | **two mounts where three were due** |

The two checks that actually settle it:

```sh
# 1. does the recipient have pending shares?  (as that user)
curl -u "USER:APP_PASSWORD" -H 'OCS-APIRequest: true' \
  'http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares/pending?format=json'
```

```sql
-- 2. the per-user (type 2) rows are the tell: a mounting folder has them at
--    accepted=1, a stuck one has none at all
SELECT id, share_type, share_with, parent, accepted, file_target
FROM oc_share WHERE file_source = <fileid> ORDER BY share_type, id;
```

### The fix

`StorageService::acceptForMembers()`, called after `createShare()` **and** on the
branch where the share already exists. The second call is the recovery path: a
share left pending by an earlier save will not be re-created, so only that makes
re-saving a mapping's groups put the folder back for anyone already stuck.

Three rules inside it, each bought by a review on a sibling:

- **This group's share only.** The prune above merely LOGS a failed unshare, so
  accepting every share on the node would hand back access an admin just removed
  (penpot #56).
- **One share per (node, group)**, so the scan stops as soon as the match is
  handled — including when it is already accepted or REJECTED (Grafana #77).
- **A REJECTED share is left alone.** Someone who removed the folder from their
  own Files view has expressed an opinion; re-accepting it would overrule them
  every time an admin re-saved the groups. Only PENDING is touched.

It needs `IGroupManager` on the constructor, which this app's `StorageService` did
not take.

### Scope

- **Admin-owned folders only.** Team Folders are unaffected: a groupfolder mounts
  from group membership through its own provider and writes no `oc_share` rows, so
  there is no pending state to be stuck in.
- **Ownership is not the variable.** All three apps resolve the same actor —
  `sync_actor` if configured, else the first member of the built-in `admin`
  group — so the owner is identical across them.
- **Shares already stuck stay stuck** until something accepts them. The
  existing-share branch is what unsticks them, on the next save.

---

## §6.2 — A link mapping authors nothing, and now the code agrees

The other half of the PR `spec/create-link-refusal` opened and did not finish.

Chapter 5's spec work found that `New file in a mapped folder becomes a real
workflow` ran its Outline over `Demo`, `Pointers` and `Shared` — and `Pointers` is
a **link** mapping. So the spec asserted, and the suite proved green, that
authoring a file into a link folder mints a workflow whose mapping tag does not
select it, leaving the next pull with an opinion about a file the user had just
made.

The scenario was corrected and deliberately left `@unbuilt`, with the commit
saying it *"should go green in the same PR that adds the guard."* The guard was
never added, so the spec and the app sat disagreeing in writing — which is the
honest state to be in, and not one to stay in.

**`CreateGuardListener` is the guard**, on `BeforeNodeWrittenEvent`, ported from
the Grafana sibling where the same rule already holds. It is the third of the
family: `MoveGuardListener` refuses a move, `CopyGuardListener` a copy, this one a
write.

**It cannot be the Sabre plugin's job, and that is worth stating** because
`LinkWriteGuardPlugin` looks like the obvious home. The plugin classifies from the
FILE's own metadata (`isLinkFile()`), and a brand-new file has none — so it
correctly waves the write through. **The constraint belongs to the folder, not to
the file**, which is exactly what the plugin cannot see. The plugin also only sees
WebDAV; `occ`, another app, or anything on the Files API never reaches Sabre.

`SyncGuard` is load-bearing rather than defensive here: the PULL writes mirrors
into link folders — that is the whole point of a link mapping — and those writes
fire the same event. Without the guard check, no link mapping could ever be
filled.

---

## Carried in from Chapter 5

- the **unbind-window defect** (gherkin first, then the baseline gate)
- the **webhook channel**, deferred rather than disowned
- the **body↔pills projection** (§5.6.2.3 Slice B), still unwired and still
  unit-tested, waiting for the trigger it deserves

---

Sources / cross-links:
- [Chapter 5 — The Marquee and the Meal](Chapter_5_The_Marquee_and_the_Meal.md) — the release this run follows.
- [`nextcloud-penpot` saga, Chapter 4 — Open for Business](https://github.com/kubed-io/nextcloud-penpot/blob/main/saga/Chapter_4_Open_For_Business.md) — where `acceptForMembers()` was first written.
- [`nextcloud-grafana` saga, Chapter 3 — The Menu and the Deep Clean](https://github.com/kubed-io/nextcloud-grafana/blob/main/saga/Chapter_3_The_Menu_and_the_Deep_Clean.md) — the apprentice's open chapter; §6.1 was diagnosed there.
- Nextcloud `apps/files_sharing/lib/MountProvider.php` and `lib/private/Share20/DefaultShareProvider.php` — read in the running pod, not assumed.
