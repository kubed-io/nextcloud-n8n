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

## §6.3 — What the guard round actually cost, in four lessons

`#88` looked like a one-commit job: the spec said `@unbuilt`, so add the guard. It
took six rounds of CI, and each red was a different thing being learned. They are
written down because every one of them is the kind that repeats.

### The listener aborted, and Sabre answered 201 anyway

`CreateGuardListener` fired. The log for the failing request contains its own
*"refused a write to a workflow file in a link-mapped folder"* line. The HTTP
response was **201** and the file was created.

**The storage layer swallows `AbortedEventException`.** `BeforeNodeWrittenEvent` is
the right event and the wrong seam for a refusal a *user* has to see. Only a Sabre
`method:*` handler's throw becomes the response.

So the rule needs both halves, and neither is redundant:

| piece | covers | gives the user |
|---|---|---|
| `CreateGuardListener` | every route — `occ`, another app, the Files API | nothing visible |
| `LinkWriteGuardPlugin::onPut` (`method:PUT`) | WebDAV only | the 403 and the sentence |

`beforeCreateFile` looks like the right hook and is **never emitted on this route**.
The Grafana sibling measured that three times before giving up on it; its comment
is why this app spent one CI round there instead of three. That is the doctrine at
the top of this chapter paying for itself the same evening it was written.

### The harness was arranging a state no user could reach

Four scenarios across three suites went red on the guard, **correctly**. Every
arrange in the suite seeded its file with a local DAV PUT, including into
`Pointers` — a link mapping. That worked only because nothing had ever stopped it.

`Deleting a link is refused` had been setting up its link by doing the very thing
the app forbids. The scenario was green against a state that could not exist.

The fix is never to exempt the harness. A link folder is filled from its tag in n8n
and nowhere else, so that is how a link file gets arranged:
`SetupTrait::seedManagedFileIn()` creates the workflow in n8n, tags it, and pulls.

**Worth asking of any other arrange**: does this set up its state by a gesture the
app would refuse? A `@Given` that can only run because a guard is missing is a test
of nothing.

### Not every "which mapping owns this folder?" question may fail

`modeForFolder()` was written by copying `tagForFolder()`, including its
`$this->fail()` on no match. That took **three suites** down on `Scratch`, the
deliberately unmapped folder several arranges name on purpose.

The two are not the same question:

- a **tag** is something every mapping must have, so asking for one of an unmapped
  folder is a broken Background and failing loudly is right;
- a **mode** is not. *"No mode"* is the correct answer for `Scratch`, and it makes
  the `=== 'link'` branch naturally false, which is exactly what the callers want.

The callers ask *before* they know whether the folder is mapped at all. Copying a
helper's shape without its reasoning is how a lookup becomes an assertion.

### "Green, and testing two different things"

The link arrange resolved its file three ways before it was right, and the middle
one is the instructive failure:

1. `"$folder/$name.n8n"` — the pull names the file after the **workflow**, and a
   second workflow sharing a name gets a numbered suffix.
2. diffing the folder listing — better, still wrong. One pull can bring down more
   than one file (a tag that already had workflows, a mirror the previous pull
   never finished) and `array_diff` has no meaningful order, so `currentFilePath`
   and `lastWorkflowId` could point at **different files**.
3. `mappedFilesByWorkflowId()[$id]` — the id is the only deterministic handle, and
   asking *by* id also asserts the thing worth asserting: that the mirror was
   stamped at all.

Version 2 would have passed for a long time and then failed somewhere unrelated.
Raised by Copilot after the PR was already 17/17 green, which is the second time in
one evening the review bot found something no run had tripped.

---

## Carried OUT — cross-app work this chapter found and did not do

Recorded per the doctrine above: a note the same day, whether or not anyone has
time to port it. Each is written so it can be picked up cold.

### 1. `nextcloud-penpot` probably needs the `method:PUT` hook

**Check, do not assume.** Penpot refuses authoring into a link mapping through a
listener, which is the shape this app had — and which was measured returning
**201** here. If penpot's `LinkWriteGuardPlugin` registers no `method:PUT`, its
refusal likely has the same hole.

The test is cheap: PUT a `.penpot` into a link-mapped folder over WebDAV and read
the status. A 201 with a refusal in the log is the signature.

Penpot's design differs in one way that may matter and should be checked rather
than reasoned about: a `.penpot` is an opaque archive with no text editor, so the
routes a file can arrive by are not identical.

### 2. `nextcloud-grafana`'s link arrange has the desync described above

`LifecycleSteps::aManagedDashboardFile()` seeds a link file through Grafana and
then takes `$files[0]` from the folder listing — the exact version 2 that Copilot
caught here.

It has not bitten, and it is the same shape: one pull bringing down two files makes
`currentFilePath` and `lastUid` disagree, green. Grafana has
`davReadMetadata($path, META_UID)` already, so resolving by uid is the same
one-line change.

**Grafana is the app this arrange was ported FROM**, which is the doctrine again:
the fix travelled one way and the defect stayed behind.

### 3. The three apps' `StorageService::syncGroupShares()` have now diverged

Penpot fixed the pending-share bug first, Grafana second, this app third — each
port slightly different. Worth one pass reading all three side by side, because the
next divergence will be found the way this one was: by a folder disappearing on
somebody's live instance.

Specifically, confirm all three carry: this-group-only, stop-at-first-match, leave
REJECTED alone, and the call on the **existing-share** branch (the recovery path —
without it, anyone already stuck stays stuck).

### 4. A link does not move, and this app currently lets one — OPEN, look deeper

**The rule, from Dr K, stated plainly: links can't move, period.** A link is
read-only in Nextcloud, so no gesture here relocates one. Where a mirror sits is
decided on the far side and followed on this one.

`MoveGuardListener` does not enforce that. Its same-mapping shortcut —

```php
if ($srcMapping !== null && $tgtMapping !== null && $tgtMapping->id === $srcMapping->id) {
    return; // staying within the same mapping folder (rename / subfolder move)
}
```

— returns BEFORE the "A LINK NEVER MOVES, WHEREVER IT IS GOING" check below it, so a
link may be dragged into a subfolder of its own mapped folder. Grafana had the identical
defect in `MoveRules` and it is fixed there (the link refusal is hoisted above the
shortcut, as the link RENAME refusal in the same file always was). **This app is not
fixed.** The gap is deliberate for now: n8n has nuances the sibling does not.

**The nuance that makes it a real question rather than a port.** In Grafana the fix
costs nothing, because Grafana has nested folders and mirrors them: to file a link into
a subfolder you move the DASHBOARD into that subfolder in Grafana, and the mirror follows
down. `FolderSteps::theFollowingItemsInTheMappings()` arranges nested links exactly that
way — `grafanaFolderUidForNcPath(parentOf($path))`, then pull. No local move at all.

n8n has no such route. A mapping is a TAG, and `SyncService` writes every mirror into
`storage->ensureFolder($mapping)` — the mapping root. Nothing anywhere creates a
subfolder. So under the rule as stated, **a nested link file in this app is reachable by
no route at all**, and the questions to settle before porting are:

- is a nested link simply not a thing here, or should tags map to n8n's own folders?
- if it is not a thing, should a link mapping accept subfolders at all?

**And the arrange is doing the forbidden gesture to get there.** `seedManagedFileIn()`
pulls a link to the mapping root and then MOVES it into the subfolder, with a comment
rationalising it as "a real state reached by a user MOVING one". It is not. This is the
same defect as *§6.3 · The harness was arranging a state no user could reach*, found in
this same chapter, committed again by the agent that wrote that section. The warning
there was the right one and was not enough: **it must be asked of every arrange, every
time, and an arrange that explains why the gesture is fine is the loudest possible tell.**

`features/mapping/delete.feature` carries `/Pointers/Coast/Latency.n8n`, which depends
on it. Fixing the rule turns that row red, so the gherkin question comes first — and
gherkin is Dr K's.

The one part already carried across: the DAV plugin answers a move in a move's words
now, instead of telling the user a link "can't be deleted here" for a gesture that
deletes nothing.

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
