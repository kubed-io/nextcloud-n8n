<!--
SPDX-FileCopyrightText: 2026 Kelly Ferrone
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Feature notes

The reasoning behind `features/*.feature` — why a scenario exists, what it
replaced, which decision it encodes, and what was deliberately left out.

It lives here rather than in the feature files because Gherkin is meant to be
read as specification: a scenario should be legible at a glance, and a comment
should add scope or a tidbit, not carry an essay. The essays are here, one
section per feature file, and a feature file links to its section on line 1.

For how the suite is organised — tags, suites, which scenarios CI runs and why —
see [README.md](README.md).

> Written for whoever picks this up next, human or agent. If you change a
> behaviour, change the note that explains it in the same commit; a note that
> describes the old behaviour is worse than no note.

## One section per feature file

Every `features/*.feature` links here from its first line, and every scenario
whose reasoning did not fit in a sentence carries a `# notes: AGENTS.md#anchor`
breadcrumb.

Short comments stay in the feature files on purpose. A remark that adds scope to
a step is meant to be read next to the step; only the essays are the problem,
because a scenario buried under twenty lines of history stops being legible as
specification.

**Keep them in step.** If you change a behaviour, change the note that explains
it in the same commit. A note describing the old behaviour is worse than no note,
because it will be believed.

Ported from `kubed-io/nextcloud-penpot`, where this layout was worked out.

## mapping/create

`features/mapping/create.feature`

"Admin makes a mapping" — the mapping list in admin settings, driven over the CLI
(the same operations the Settings panel performs). A mapping binds an n8n **tag**
to a Nextcloud folder, with a storage kind (Team Folder vs admin-owned) and a
mode.

**A MAPPING IS A TAG.** n8n has no folders — a workflow's only grouping construct
is its tags — so where the Grafana sibling maps folder-to-folder and the Penpot
one maps a team, this app binds a tag to a folder and the tag decides which
workflows the mapping owns. Everything downstream follows from that: membership
is a tag question (`mapping-membership.feature`), and the whole tag vocabulary is
its own suite (`tag-sync.feature`, `reserved-tags.feature`).

**MODES ARE EXACTLY `sync` OR `link`** (saga Chapter 3 §14). "backup" was dropped;
"unmapped" is a file state and never a mapping mode.

### The preconditions

ONE SENTENCE PER FACT, AND A MAPPING IS ONE FACT.

    Given a mapping with the following values:
      | tag    | nextcloud:alpha |
      | folder | alpha           |

The table carries the full state of one mapping, and the fields are exactly the
ones the creation form takes. That matters more than it looks: the pre-state and
the action are then described in **one vocabulary**, so a scenario can put a
mapping in place and then perform the very action that would have created it,
with the difference visible in the table rather than hidden between two
differently-worded steps.

`the admin maps the tag "X" with:` is the same table as a `When`. That symmetry is
what makes the uniqueness scenarios readable.

**A BLANK CELL MEANS "THE ADMIN LEFT IT ALONE", NOT "EMPTY".** Blank values are
dropped from the payload entirely, so the app applies its own default. An empty
string is a value and would test the wrong thing — `team_folder` is required, so
submitting `""` tests the validator rather than the default.

This replaced a `When the admin adds these mappings:` step taking a table of four
whole mappings. That form had two problems: the scenario could only pass or fail
as a whole, naming none of the four as the thing that broke; and it was a *When*
doing pre-state work, so nothing could be said about a mapping that already
existed.

### Creating a mapping saves the form

The storage × mode matrix, one Examples row per combination.

The assertion is deliberately one sentence — `the mapping matches the form, unset
fields at their defaults` — rather than a list of per-field `Then`s. The scenario
is about the form round-tripping, so it should say that once; a reader who wants
to know which field broke reads the failure, not the spec.

`an unset field on the mapping form defaults to:` declares the defaults **in the
scenario** rather than hiding them in a step definition, so the two rows that
exercise them are legible without opening PHP.

**Only the tag and the folder are required.** Omitting either is a refusal, not a
default, and the refusal outline carries a row proving it for each. `mode`
defaults to `link`, `nc_groups` to empty, and `use_team_folder` to false.

**Mode did not always default, and writing this table is what found it.**
Declaring what every unset field becomes forces a value for each, and there was
none to put in the `mode` row — omitting it refused the whole mapping, so the
shortest useful `occ` call could not be written and every caller had to name a
mode it had no opinion about. `link` is the conservative choice: it downloads
nothing and pushes nothing back. Grafana had the identical gap and was fixed in
the same pass. An *unknown* mode is still refused — saying nothing and saying
nonsense are different inputs.

**`use_team_folder` defaults to false**, matching Penpot. A Team Folder needs
groupfolders, an OPTIONAL app absent from a stock Nextcloud, so defaulting to it
made the default mapping the one that could not be provisioned: an admin who
filled in a tag and a folder and touched nothing else got a refusal. A default
must be the safe choice, not the preferred one. A Team Folder is opted into, by
naming `| storage | team folder |`.

**This note previously argued the opposite, and that is the lesson.** It recorded
the divergence from Penpot as "real rather than accidental", conceded in the same
breath that "the default still asks for a backend that may be absent", and left
it standing. Writing the reason down is not the same as having one — a documented
defect reads as a decision, and is much harder to see afterwards than an
undocumented one. Two things followed from it: a CI comment claiming groupfolders
had to be installed *because* Team Folders were the default, and a later scenario
adding `| storage | admin folder |` to a table where storage is irrelevant, just
to escape the default. Grafana had the identical inversion.

`MappingTest::testStorageDefaultsToAdminOwned` now pins it, and it asserts the
OMITTED flag rather than an explicit `false` — the whole defect lived in what
happens when nobody says anything.

### A mapping the app cannot honour is refused, and says why

One scenario, not five, because the behaviour is identical every time: refused,
nothing stored, and the message names the field at fault. The rules are the
Examples, which is where a difference belongs when the sentences would otherwise
be word-for-word identical.

Each row is reachable by a human — typing into the form, or into the `occ` JSON
argument. **A refusal earns a row only when someone can provoke it**; a validator
that no input can reach is not a behaviour.

`the refusal explains "<fragment>"` matches a FRAGMENT, not the whole message.
The scenario's job is to prove the refusal names the field so an admin knows what
to change; pinning the exact sentence would make every wording improvement a test
failure.

### An n8n tag may only be mapped once

A tag is what a mapping IS — it decides which workflows the mapping owns — so
mapping it twice would make two mappings mean the same thing, and every workflow
carrying that tag would belong to both. Enforced by
`MappingService::assertTagUnique()`.

### There is no way to change a mapping except its groups

`@decision`, not `@unbuilt`: **there is no operation to test, and that is the
design.**

Immutability is enforced by the API SHAPE rather than by guards.
`MappingService::updateGroups()` takes an id and groups; the PUT endpoint takes
`nc_groups` and nothing else; `update()` is gone and there is no update command.
A caller cannot *express* a change to the tag, the folder, the storage backend or
the mode, so there is no rejection to observe.

**This section used to describe the opposite, and the gap was real.** `update()`
took a whole Mapping and guarded exactly ONE field — `use_team_folder` — so the
tag, the folder and the mode were all editable on a live mapping, and the admin
card PUT every one of them on each save. Changing the tag silently re-decided
which workflows the mapping owned; changing the folder orphaned everything
already mirrored into the old one.

Each field is fixed for the reason it always was: the change would force a live
migration nobody asked for. Re-pointing the tag or the folder moves a whole tree
of already-synced files; switching the storage backend migrates the provisioned
folder and all of its shares; the mode decided how every existing file under the
mapping was written. Delete the mapping and add a new one — which makes the
migration cost visible instead of hiding it behind a dropdown.

**Groups stopped being an edit of the mapping at all.** They are a pass-through
to the folder now — see the two sections that follow, and Penpot's §C6.35 — so
the mapping object itself is wholly immutable and "editing a mapping" means
"re-sharing its folder".

### The groups a mapped folder is shared with can be changed

The one edit a mapping has, and the only one it should ever have.

**NARROWING AND CLEARING ARE THE POINT.** The old `syncGroupShares()` wrote the
listed groups and left the rest alone, so a group could be granted and never
revoked, and "set the groups to nothing" silently did nothing at all. It could
only start pruning safely once the sync stopped re-asserting a stored list — a
sync that pruned from a stored list would have been quietly revoking access an
admin had granted by hand.

The folder name differs per storage kind deliberately: removing a mapping deletes
nothing, so a folder outlives the mapping that made it, and a later Examples row
reusing the name would inherit a folder of the wrong kind.

### Groups are read from the folder, not from the mapping

**The scenario that explains why the whole change exists.** Three apps in this
family — this one, Grafana and Penpot — can map to the same folder. While each
stored its own group list, every sync stamped that list over the others', so all
three fought for control of one folder forever and none of them was wrong.
Reading the groups off the folder makes the folder the single answer.

The share is made through **groupfolders' own `occ` command**, so it comes from
something that is not this app — otherwise the scenario would prove only that the
app agrees with itself.

It is written on a Team Folder for a checked reason: **core ships no `occ` command
that creates a plain group share.** Verified against a live Nextcloud rather than
assumed — core has `sharing:cleanup-remote-storages`, `delete-orphan-shares`,
`expiration-notification` and `fix-share-owners`, and nothing that shares. A first
draft called `occ sharing:share`, which does not exist and would have failed in
CI.

### Two mappings may not target the same folder

`@unbuilt`, **and the gap is real.** `MappingService` asserts the tag is unique
and says nothing whatsoever about the folder, so today the second mapping is
accepted. Two tags mirroring into one folder interleave their workflow files, and
each mapping-scoped sync prunes what the other just wrote — the folder never
settles.

Written in deliberately the same shape as the tag rule above, because once it is
built the two collapse into one outline with the columns as the difference. The
Penpot sibling already refuses it (`assertFolderUnique`).


## mapping/manage-groups

`features/mapping/manage-groups.feature`

THE ONE FIELD A MAPPING LETS YOU EDIT, split out so it is not buried among the
immutable ones — the same split both siblings made. Everything else is fixed at
creation, and not by a guard that rejects a change but by the API shape: there is
no way to express one.

Both storage backends get their own Examples block because the provisioning
differs and the behaviour must not.

The `@decision` scenario that used to sit beside these — "there is no way to
change a mapping except its groups" — is gone. It had no `When`, because there is
no operation to perform; that is what this file's existence already says.

## connection/connection

`features/connection/connection.feature`

The "admin makes the n8n connection" use case — the app's "I'm logged in" gate,
a prerequisite to every other feature. The admin points the app at n8n (base
URL), provides the API key, enables the REST API, and tests the connection to
confirm the URL + key are valid and n8n is reachable.

(Obtaining the API key is out of the app's scope — that's the n8n admin's job;
in the tests it's provided as setup.)

### The connection test says which of the two key problems it is

A sensitive key field renders blank whether or not a key is stored, so the Test
connection result is the admin's diagnostic — and it must tell the two failure
modes apart: "you haven't added a key" vs "the key you added was rejected". Same
distinct messages on the button and the occ command.

## workflows/copy

`features/workflows/copy.feature`

Copying a workflow file. Where a MOVE is "the same workflow" (see move.feature),
a COPY is ALWAYS a brand-new instance. A copy never inherits the original's n8n
identity — its metadata (n8n_id, versionId, mapping, mode) is stripped the moment
it is copied. Copy is therefore the single safest point to strip metadata:
whatever the source was (sync, link, unmapped), the copy starts clean.

Nextcloud distinguishes copy from move at the event layer (NodeCopiedEvent vs
NodeRenamedEvent), which is what lets us treat them oppositely.

## workflows/create

`features/workflows/create.feature`

Creating workflows from Nextcloud. These scenarios are the human-readable spec
for the "author in NC, live in n8n" flow. LIVE: a .n8n.json written over WebDAV
into a mapped folder fires NodeWrittenEvent → CreateInN8nListener → the workflow
appears in n8n. The n8n side is asserted over its REST API; the NC stamp over
DAV PROPFIND of nc:metadata-n8n_id.

### A file that arrives with tags in its body carries them into n8n

══ ADOPTION: A FILE THAT ARRIVES ALREADY CARRYING ITS TAGS ════════════════

A workflow can come into existence from a file that was authored elsewhere —
exported from another n8n, copied from a sibling, or carried out of Nextcloud
and back. Creation is creation, so it lives here rather than in
tag-sync.feature; what tag-sync.feature owns is what happens to tags AFTER a
file is managed.

THE BODY IS THE ONLY SURFACE THAT SURVIVES THE TRIP. Nextcloud's system-tag
pills are bound to a file id, so they do not survive an export or a copy; the
`tags` array is bytes inside the file. At the moment of adoption there are no
pills, no baseline, and no workflow — the body is the ONLY record of what this
thing was tagged. That is why it wins here and nowhere else (saga §5.6.3).

THIS IS A DEFECT TODAY, NOT MERELY UNBUILT. `CreateService` sends
`N8nWorkflowBody::toCreateBody`, whose writable whitelist omits `tags`, so
`$created['tags']` is ALWAYS empty and the "additive merge" merges the mapping
tag into nothing. Every tag the file arrived with is silently discarded. The
docblock claiming "POST /workflows preserves tags the body declared" was wrong
twice over: we never declare them, and n8n's schema marks `tags` readOnly on
create AND update anyway (`workflowCreate.yml`, `additionalProperties: false`).
`PUT /workflows/{id}/tags` is the only writer that exists.

Nothing caught it because adoption's tag behaviour had never been written down.


### A file created in a nested mapping belongs to the nearest one

FROM THE RETIRED `mapping-membership.feature`, reshaped. It read *"a workflow
file lives in the subfolder"* — a state, not an action — so it stated the rule
rather than exercising it. Creating a file there is the gesture that makes the
nearest-enclosing-mapping rule observable, which is the only way a rule ever is.

Two of that file's other scenarios were the rule stated twice more and are gone;
its sync-scope one moved to `connection/sync-now.feature`, where the actor is.

### A round trip out of Nextcloud and back keeps the workflow's tags

THE CASE THAT DECIDES THE DESIGN. The pills did not survive the trip and n8n
no longer holds the workflow. The body is the only carrier left — and it is
enough, which is the whole reason adoption reads it.

## workflows/delete

`features/workflows/delete.feature`

Deletion semantics differ by mode. Mirrors Nextcloud's two-step trash model.
The matrix here is the contract the delete listener must satisfy.
Modes (saga Chapter 3 §14): sync / link / unmapped. A file with NO n8n metadata is
"untracked" (a plain document) — distinct from "unmapped" (a sync file moved out
of its mapping that still carries its id + an archived n8n workflow).
LIVE: delete/purge/restore go over WebDAV (incl. the trashbin DAV endpoint);
DeleteToN8nListener runs synchronously, and the n8n side is asserted over REST.

THE TWO STEPS ARRIVE THROUGH TWO DIFFERENT DOORS, and that is not a style choice:
  - trash-move (soft) → `BeforeNodeDeletedEvent`, a typed event → DeleteToN8nListener
  - purge     (hard)  → the legacy `\OCP\Trashbin` `preDelete` hook → TrashPurgeHook
Nextcloud dispatches NO typed event for a purge. Assuming it did — and
discriminating the two steps by path prefix — is what left purged workflows alive
in n8n; see TrashPurgeHook's docblock for the full autopsy.

A TRASH-BYPASSED DELETE ARCHIVES, IT DOES NOT DELETE. With the trashbin disabled
(or `X-NC-Skip-Trashbin`) only the soft step ever fires, so a `sync` workflow is
left archived. Deliberate: nothing at that point can tell "on its way to the
trash" from "gone for good", and an archive that should have been a delete is
recoverable while the reverse is not.

── RULE: TWO BINS, AND THEY ARE NOT SYMMETRIC ───────────────────────────────────

Both systems have a reversible bin and an irreversible purge, so a workflow has
two independent lifecycles that must be read as a PAIR:

    Nextcloud     live file  →  trash        →  purged
    n8n           live wf    →  archived     →  deleted

The pairing is deliberate and holds in one direction only:

  | Gesture                        | Nextcloud       | n8n                    |
  |--------------------------------|-----------------|------------------------|
  | delete a synced file           | → trash         | → archived             |
  | restore it from the trash      | → live          | → unarchived           |
  | purge it from the trash        | → gone          | → DELETED (best effort)|
  | unarchive the workflow in n8n  | (nothing)       | → live                 |
  | delete the workflow in n8n     | (nothing)       | → gone                 |

NEXTCLOUD DRIVES; n8n DOES NOT DRIVE BACK. The bottom two rows are the asymmetry
and they are the whole reason this table exists: an n8n-side bin change does NOT
reach into Nextcloud's trash. Nextcloud's trash is the user's own undo history and
this app does not reach into it, in either direction — the same "don't lose data"
rule the purge honours by deleting only what the user themself purged.

WHAT THAT COSTS, STATED PLAINLY: the pull cannot see into the trash at all
(`SyncService` never mentions it), so a trashed file is invisible to a reconcile.
Sometimes that is right and sometimes it is a gap, and the difference is the two
@unbuilt scenarios below — the SAME blindness, benign in one and harmful in the
other. Worth reading them together.

### Purging a sync-mode file permanently deletes the workflow

FIXED — AND THE @todo ABOVE HAD ALREADY NAMED BOTH CAUSES. This scenario sat
skipped behind a comment that guessed exactly right, twice: the trashbin
dispatches no typed Files event, AND the ".dNNNN" suffix defeats the
".n8n.json" gate. Both were true, and either alone was enough to kill the leg.
Left as a guess for months, it meant a purged `sync` workflow stayed alive in
n8n forever with its file gone — a leak nobody goes looking for.
The purge now runs off the legacy `\OCP\Trashbin` `preDelete` hook
(TrashPurgeHook) and matches the trashed name with its timestamp suffix
(FilenameCodec::isTrashedWorkflowName). Live from here on.

### Trashing an unmapped file is a no-op in n8n (already archived)

── unmapped mode (a moved-out sync file: keeps its id, workflow archived) ────
Unmapped mode has landed (saga §14.2). An unmapped file's workflow is already
archived and has no live mapping, so trash and restore are both n8n no-ops:
softDelete/restore fall to the link branch with mapping=null and skip the call.
The "left as-is" assertion proves it — the workflow stays present and archived.

### Purging an unmapped file permanently deletes the archived workflow

The listener half of this is now fixed (the purge fires — see the sync purge
above), but this leg still needs a DECISION, which is why it stays skipped:
`DeleteService::hardDelete` is a no-op for anything that is not `sync`, and an
unmapped file's mode is `unmapped`. So the hook reaches n8n and then declines
to act.

The open question is whether it SHOULD act. An unmapped file's workflow is
already archived and belongs to no mapping — purging the last Nextcloud copy is
arguably the user saying "done with this", but it is also the one case where
Nextcloud destroys an n8n object it no longer owns. Not a bug to fix quietly.

### Restoring an unmapped file from trash touches nothing in n8n

══ THE OTHER BIN: CHANGES MADE ON THE n8n SIDE ════════════════════════════

Everything above starts in Nextcloud. These start in n8n, and they are where
the pull's blindness to the Nextcloud trash actually bites. The pull indexes
`$folder->getDirectoryListing()`, and a trashed file is not in the folder —
so as far as a reconcile is concerned, a trashed mirror does not exist.

### Unarchiving a workflow in n8n brings its file back out of the trash

NOT WHAT HAPPENS TODAY. The pull finds no file for that id — the trashed one
is invisible to it — so it writes a BRAND-NEW file and leaves the trashed copy
orphaned. Restore that copy afterwards and TWO files carry the same id, which
is the duplicate the reconcile is otherwise careful to avoid.

The fix is a trash-aware reconcile: before creating a file for an unseen id,
look for a trashed mirror carrying it and restore that instead. The sibling
app built exactly this (penpot saga §6.37); it is the piece n8n never got.

### Restoring a file whose workflow was deleted in n8n gives it a new one

NOT WHAT HAPPENS TODAY, and the fix is already written elsewhere.
`DeleteService::restore()` unarchives through `callIdempotent`, which treats
404 as SUCCESS — so the file comes back carrying a dead id and nothing is
created. It is silently detached from n8n with no sign anything is wrong.

`MotionService::moveIn()` handles the identical situation correctly: catch the
404, create from the file's content, stamp the fresh id — and it is live at
move.feature ("Restoring when the n8n workflow was hard-deleted falls back to
create"). Restoring a file whose workflow is gone and moving one in whose
workflow is gone are the same problem; only the move path knows it.

### Deleting a workflow in n8n leaves an already-trashed file where it is

This one the app gets RIGHT — but for a weak reason, which is why it is worth
pinning. Nothing DECIDES to leave the orphan: the pull simply cannot see into
the trash, the same blindness that makes the first scenario above wrong. A
trash-aware reconcile must keep this behaviour deliberately rather than lose
it, because Nextcloud's trash is the user's undo history and an n8n-side purge
is not permission to empty it.

### A delete is aborted if n8n is unreachable

@blocked, not @todo: the code exists (AbortedEventException aborts the NC
delete), but this harness has no way to make n8n unreachable for the duration
of one request — that is the missing capability, and naming it is what keeps
this out of the @todo work queue. A unit test against a mocked N8nClient is
the cheaper home if it is ever wanted.

## workflows/view

`features/workflows/view.feature`

LOOKING AT A WORKFLOW FILE — the only part of "it is a real file type" that
anyone actually performs.

### view-workflow

**This replaced `file-type.feature`, which described a CONSTRUCT.** "n8n workflow
is a first-class file type" was about a mimetype, a property set and an index —
none of which anyone does. Each turned out to be the end state of something else:

| it described | whose end state it is | where it went |
|---|---|---|
| the mimetype is registered | **enabling the app** | `lifecycle.feature` |
| a file carries this metadata | **creating** or **syncing** a workflow | asserted by those, shown here |
| the mode property's wire value | what the metadata says | the DAV view outline |
| the metadata cannot be edited | a refusal anyone can provoke | stayed, as a scenario |

Nobody registers a mimetype; they install an app. Nobody sets metadata; they make
a workflow and the app stamps it. Once each end state sits with the behaviour
that produces it, what remains is looking — and that is a real thing to do.

### A mapped folder shows its workflows as workflows

**ONE SCENARIO, DELIBERATELY.** Behat cannot read rendered pixels, so the icon is
proven the only way it can be: the file carries the app's own mimetype rather
than `application/json`, and Nextcloud maps that mimetype to the app's glyph.
Elaborating past that would be testing Nextcloud's icon renderer, which is not
this app's to prove.

This is the app's only genuinely UI-only surface, which is why it is one small
scenario rather than a file.

### Viewing the DAV properties on a file shows n8n specific details

`@dav`, because a DAV client is the actor: it asks for the properties and gets
back what the app knows.

**This is the one scenario that spells the properties out**, and everywhere else
the same fact is one sentence — `the file carries its n8n metadata`. The two are
not in tension, they are the difference between a subject and an end state. Sync,
create and rename all *produce* a mirror; which keys that mirror carries is the
app's business, and listing them there would make every one of those scenarios
look like a metadata test. Here the properties genuinely are what is under test,
so the table is the specification.

The keys are the five `stampSynced` writes when a file lands: `n8n_id`,
`n8n_mapping`, `n8n_mode`, `n8n_versionId`, `n8n_syncedHash`. `n8n_syncedTags` is
managed too but is stamped afterwards by the tag reconciler, and only once there
are content tags — so it is not part of what viewing a fresh mirror shows.

The value column takes three forms and no more, because a table that can say
anything stops being readable: `set` (present and non-empty), `the workflow's id`
/ `the mapping's id` (resolved against what the arrange recorded), or an exact
literal. The two id forms exist because presence is too weak for them — an id
that is merely non-empty could be any workflow's, and the whole point of
publishing it is that it names *this* one. A version id and a body hash are
opaque by design; pinning literals there would assert the sync engine's internals
instead of the fact under test.

`link` stores as `reference`. The literal string `link` is `is_callable()`, which
crashes core's PROPFIND — the only place in this app where a wire value differs
from the name of the thing it carries, so it is an Examples column rather than a
footnote, and the row shows both what the admin chose and what a client reads.

**The table says nothing about storage.** Naming a field is a claim that it
matters, and what a mirror publishes over DAV is identical on an admin-owned
folder and a Team Folder — so the mapping takes the app's own default, an
admin-owned folder, which is the one backend that exists on every install.
`storage` is named only where provisioning *is* the behaviour, in
`admin-mapping.feature`, and a scenario wanting a Team Folder asks for one there.

**The outline lost two rows** (`unmapped`, `ignored`) when it was reshaped around
a mapping. That is deliberate and not a coverage regression: a mapping only ever
produces `sync` or `link`. The other two are what a file *becomes* — moved out of
its folder, or hand-tagged `n8n:ignore` — so they cannot be reached from a
mapping form at all, and their DAV values are asserted where those behaviours
live, in `open-with.feature` and `reserved-tags.feature`.

### What the app manages, only the app changes

A REFUSAL SOMEONE CAN PROVOKE, so it earns a scenario: any DAV client can attempt
a PROPPATCH. The identity of a mirror is the app's to write — a client that could
edit `n8n_id` could silently re-point a file at a different workflow.

The load-bearing assertion is that the VALUE did not change, not that a particular
status came back.

### Listing the workflows n8n holds

THE OTHER WAY TO LOOK, and the one with no UI at all. `occ` reads n8n directly
rather than reading the mirror, which is exactly what makes it useful when the
two disagree: *"is it missing from the folder, or missing from n8n?"* is the
first question anyone asks, and this answers the second half without trusting
the first.

Neither this nor `get-workflow` had a scenario before — the CLI view surface was
entirely unspecified, despite being the surface an admin reaches for when
something looks wrong.

### Viewing one workflow n8n holds

The id comes from the listing, which is why the two sit together rather than
being one scenario about "the CLI".

The `Then` asserts it is the RIGHT workflow, not merely that something was
printed: a command that emitted any valid JSON would satisfy the looser reading,
and the whole point of viewing one *by id* is that you get that one.

### Finding workflows by their mode

`@blocked`, and the missing capability is named: there is no proven DAV REPORT
search over `nc:metadata-*` to drive it against. `n8n_mode` is indexed precisely
so this is a fast query; confirm the search surface exists and this becomes an
ordinary `@todo`.


## lifecycle

`features/lifecycle.feature`

Stage 0 (saga §5): the app installs and uninstalls cleanly on a real Nextcloud.
A clean uninstall is also an app-store rule. No n8n contact.

### Enabling the app

**THE MIMETYPE IS WHAT ENABLING LEFT BEHIND.** It used to head a file called "n8n
workflow is a first-class file type", which described the registration as though
someone had gone and done it. Nobody registers a mimetype; they install an app,
and the registration is the consequence — so it is asserted here, on the install.

Proven by uploading a plain file rather than by reading the app's own metadata: a
file this app has never touched, with nothing but the extension going for it,
comes back typed as the app's own mimetype. That is what registration means and
the only part of it a client can observe.

Its visible consequence (a mapped folder that looks like workflows) belongs to
`view-workflow.feature`; its removal belongs to `uninstall.feature`.


### Removing the app

FOLDED IN FROM `uninstall.feature`, which is retired — enabling, disabling and
removing are three points on one lifecycle. `@blocked`: **no app removal**, since
`occ` enables and disables and nothing more. Two of that file's scenarios did not
come with it: "disabling leaves the files in place" asserted Nextcloud rather
than this app, and "re-enabling reconciles without duplicates" is the id-matching
`connection/sync-now.feature` already owns.

## mapping-membership

`features/mapping-membership.feature`

Folder mappings are metadata on the folder, so membership is resolved by where
a file lives. (How the app reacts when you MOVE a file across that boundary is
in move.feature — a sync file moved out becomes "unmapped"; a link can't leave.)

Live (saga §14.9): the resolver matches the deepest mapped folder that encloses
a file, so nested mappings work and the nearest enclosing one wins. Each scenario
lands a real file over WebDAV and reads the resulting n8n_mapping stamp back, so
these are server-observable assertions of MappingService::resolveForPath.

### A sync never touches a file outside every mapping

THE SCOPE OF A SYNC IS A MEMBERSHIP QUESTION, so it is answered here rather than
in a file about syncing. "Which files does this mapping own" is what this file
exists to say; a sync merely acts on that answer.

It moved from a scenario about the sync button, where it was one `Then` among
four — so "an unmapped file is out of scope" could only ever fail as part of "the
button worked", and never named itself.

## workflows/move

`features/workflows/move.feature`

How the app reacts to every move a Nextcloud user can make on a workflow file.
A MOVE mirrors as the SAME workflow moving in n8n — never a duplicate. The
stable link is the workflow id, so a move out and back in is an archive then a
restore, not a delete then a create. (COPY is the opposite — always a new
instance; see copy.feature.)

Model (saga Chapter 3 §14): modes are sync / link / unmapped. "unmapped" is the
state a sync file enters when moved OUT of its mapped folder: NC keeps the full
JSON + the workflow id + versionId, clears the mapping, and the workflow is
archived in n8n. Moving it back into any mapping restores (unarchives) it.

LIVE (saga §14.2, Phase 2): the sync move-out → unmapped + archive, the
unmapped move-in → restore, within-mapping moves, link move-out refusal, and
unmapped relocation are wired (MoveGuardListener + MotionListener +
MotionService) and asserted here over WebDAV (MOVE) + the n8n REST API. The
hard-deleted restore-fallback and brand-new move-in create are now live too;
the lone remaining edge is merge-on-collision (an unmapped copy moved in over an
already-synced file with the same id), which still needs a metadata-by-id lookup.

### The mappings in the Background

Three mappings, declared as one table with a header row — tag, folder and mode
spelled out per line. `move.feature` is the first feature to do this, and the
reason is specific to it: **a move is a Nextcloud gesture, so its steps address a
FOLDER**, while the tag only means anything on the n8n side.

Everywhere else the harness derives the folder name from the tag
(`folderNameForTag`), which made the two look like a single thing. That is not
theoretical: a scenario was drafted asserting that a folder was "no longer a tag
in n8n", with `<folder>` and `<destination>` in the steps and `source tag` /
`destination tag` in the Examples — placeholders that did not resolve, over a
claim that could not be true. Declaring both in the same row is what keeps them
apart.

**Colons were never part of a tag.** One early example used `nextcloud:alpha`
and the shape propagated as though it were required. n8n tags are free text, and
a Nextcloud folder would not normally contain a colon at all — so the mappings
here are `alpha`/`Automations`, `beta`/`Pipelines`, `links`/`Pointers`. The
reserved tags (`n8n:ignore`) are a different thing and keep their prefix, which
is exactly why the ordinary ones should not borrow the shape.

`copy.feature`, `purge.feature` and `reserved-tags.feature` still declare their
mappings tag-first. The tag-addressed steps were kept alongside the new
folder-addressed ones for that reason; those three want the same treatment.

### Restoring when the n8n workflow was hard-deleted falls back to create

The unmapped file kept its id, but the workflow was hard-deleted in n8n in the
meantime. `moveIn` catches the unarchive 404 and recreates from the file we still
hold — a fresh id — then re-stamps sync in the target. The file is the survivor,
so the mirror wins over the missing original.

### Moving a brand-new workflow file into a mapping creates it

An untracked file (no id) dragged into a mapping is create-on-land, owned by
`CreateInN8nListener`. It fires on `NodeRenamedEvent` rather than
`NodeWrittenEvent`, because Nextcloud does not fire the latter for a move — a
detail worth keeping written down, since the obvious listener choice is wrong.

### Moving a workflow to another mapped folder

**`@unbuilt` — this is the spec, and the app does the opposite today.**
`MoveGuardListener` aborts a mapping→mapping move for both modes and tells the
user to move out to an unmanaged folder first, then in. The scenario describes
what should happen, not what does; its steps throw rather than pass, so it cannot
quietly start counting as coverage.

**Saga §14.2 case (a), and the decision it needs.** Landing in a new mapping means
the workflow's tag changes in n8n, and there are two defensible ways to do it:
re-tag in place, or eject and reattach as if it had arrived fresh. They differ in
what happens to the versionId, the synced-tag baseline and the archive state — so
picking one by accident inside the move handler would be picking it permanently.

**These rows choose re-tag in place.** `And the file's mode is "<mode>"` is where
that choice lives: the mode survives the move, so it is the same file in a new
mapping rather than a new arrival. Eject+reattach would not preserve it, and
would mint a fresh versionId.

**The two `link` rows are the sharp end.** A link has no body on the Nextcloud
side, which is exactly why moving one OUT of a mapping is refused — there would be
nothing left to hold. A link moving *between* mappings never becomes bodiless, so
it is arguably fine; but it is a real decision rather than a symmetry, and it is
worth being deliberate that these rows assert it.

Until it is built, the way through is the message's own: out to an unmanaged
folder, then in. Both halves are covered scenarios, so the capability is not
missing — only the shortcut.

### Moving a duplicate in under the same name is refused (the workflow is already synced here)

Move-in duplicate (saga §14.19). A file carrying an id is moved into a mapping
where that workflow is ALREADY synced — e.g. an admin restored it in n8n and it
synced back into the folder while an unmapped copy still existed. This is not the
same file relocating; it's a duplicate. Nextcloud's own rules lead the behaviour:
  • same name → the move is refused (WebDAV Overwrite:F → 412), exactly like any
                NC same-name move. The existing synced file is the source of truth.
  • diff name → the incoming is minted as a BRAND-NEW workflow (copy semantics,
                §14.5): MotionService::moveIn sees a sibling already carrying the
                id and hands the file to CreateService, which strips the carried id
                and creates a fresh workflow — the existing file is left untouched.

### Moving an unmapped file between unmapped locations changes nothing

── decision cases (saga Chapter 3 §14.2 a–d): documented, not yet designed ─────────
These need a design decision before they get concrete Then-steps:
  a. sync moved directly mapping→mapping (different tag): re-tag in place vs
     eject+reattach. THE BLOCK ITSELF IS NOW SPECIFIED — see "Moving a workflow
     straight into another mapping is refused" above. What remains undesigned is
     what should replace it, not what happens today.
  b. moving into a nested subfolder owned by a different mapping (nearest
     enclosing wins) — interaction with case a.
  c. link rename within its mapping — does the filename matter, or is the n8n
     name authoritative?
  d. deleting an unmapped file (it has an id + an archived workflow) — see
     delete.feature.

## workflows/open-with

`features/workflows/open-with.feature`

"Open with" — the openers offered for a managed workflow file, and which one is
the default click. RELATED to the file type (view-workflow.feature: it's *because*
`.n8n.json` is a first-class type that we get custom openers) but a distinct
concern, because the opener set + default depend on the file's MODE, not its type.

Two openers:
  - "Open in n8n"          — jumps to the live workflow in n8n. Only meaningful for
                             sync/link; hidden for unmapped/ignored (nothing to open).
  - "Open with text editor" — edits the raw JSON. ALWAYS available on any workflow
                             file; it's the default for unmapped/ignored.
Default click: sync/link → Open in n8n; unmapped/ignored → text editor.
(Whether editing+saving pushes to n8n follows the file's mode — see
create-workflow.feature / rename.feature / the bidirectional sync, not here.)

Behat can't click the Files-app JS, so the integration steps assert the
server-observable the front-end keys off (the n8n_mode DAV value + the
live/archived workflow state + raw-JSON readability); the opener DECISION logic
itself is unit-tested in tests/js/files-helpers.test.js.

`link` is a ROW, not a separate scenario. It sat in its own @todo outline for
"link integration is uncertain" while two other files were arranging a link file
and running green — a stale reason nobody re-checked. It is one rule over four
modes, so it belongs in the Examples table with the rest; splitting it hid that
the only thing missing was the row.

## workflows/purge

`features/workflows/purge.feature`

Purge — an admin-only button beside "Sync from/to n8n" and "Test connection"
(also `occ n8n_sync:purge`) that removes the workflow files THIS APP created and
nothing else. It deletes every **restorable** managed file — `sync` and `link`,
whose workflow is still live + tagged in n8n — across all mappings, and:
  - never contacts n8n (the delete runs under SyncGuard so it can't mirror out);
  - leaves the mappings configured;
  - leaves the custom mimetype registration alone (that is uninstall's job).

It deliberately KEEPS files a "Sync from n8n" could not bring back, so purge can
never cost you data: `unmapped` files (moved out of a mapping, archived in n8n —
a standalone copy / template you kept), `ignored` files, and untracked `.n8n.json`
(a plain document the app never created).

Driven headlessly through `occ n8n_sync:purge` ({@see \OCA\N8nSync\Command\Purge}).
Two intended flows: purge → "Sync from n8n" (everything reappears), and
purge → uninstall (Nextcloud looks like the app was never there).

### Purge keeps an ignored file

An `ignored` file is one the user excluded ON PURPOSE — it keeps its id and its
place — so the purge must walk past it. Was @todo for want of an arrange; the
arrange existed all along, it just silently ignored the mode it was handed and
produced a `sync` file, which would have made this scenario assert the opposite
of its own Given.

## connection/sync-now

`features/connection/sync-now.feature`

THE FIRST SYNC, AND ONLY THAT.

### sync-now scope

**There is no `reconcile.feature`, and there must never be one.** Reconciling is
a MECHANISM — the thing that carries an n8n-side change into Nextcloud — and a
mechanism does not get a feature file. What a person does gets a feature file.

This file replaced one called "Manual per-mapping sync (Sync from / Sync to
n8n)", which was named after two buttons. Its three scenarios turned out to be
four different behaviours wearing one coat, and every one of them belonged
somewhere else:

| it said | it meant | where it went |
|---|---|---|
| the button pulls the tagged workflows in | the FIRST sync | here |
| …and prunes a file whose workflow lost the tag | a tag removed **in n8n** | `tag-sync.feature` |
| …and leaves the unmapped file alone | what "unmapped" **means** | `mapping-membership.feature` |
| a run that changed nothing rewrote nothing | an mtime, and the reconciler | deleted — see below |
| the button pushes local changes up | **editing** a workflow file | `edit-workflow.feature` |

**Why the first sync is genuinely its own thing.** Nothing is tracked yet, so
whatever sits in n8n is simply a Given. Every LATER run only has work because
something changed upstream — and each of those is a scenario about the change:
renamed in n8n is `rename.feature`, deleted in n8n is `delete.feature`, tagged in
n8n is `tag-sync.feature`. The sync is how the news arrives, not what happened.
Once those files own their behaviours there is no "second sync" left to describe.

**The trigger is data.** Three ways to start one sync — the card's button, the
section's button, the schedule — same pre-state, same post-state. Columns, not
scenarios. Whether a run is synchronous or queued is a mechanism and is asserted
nowhere.

The schedule row drives the REAL job, forced past its interval and the worker's
last-run gate with `background-job:execute --force-execute`. Asserting a row
exists in `oc_jobs` would prove the job is registered and nothing about whether
it runs.

**"A run that changed nothing rewrote nothing" was deleted, not moved.** It
asserted an mtime — a result — about the reconciler — a mechanism — and neither
gets a scenario. The defect it once guarded (a pull rewriting every mirror on
every run) is real and is recorded in the CHANGELOG; the step definitions are
kept, with their docblock, so re-adding it is one line if it ever earns a home.
The behaviours that DO rewrite a mirror assert their own end states, which is
where that guarantee belongs.

**The "every mapping" leg was never actually exercised until Actions came back
from an outage.** `runMappingSync()` required a `string $tag`, but this
scenario's own actor×scope table passes it `null` for that row — the CLI's
`--all` (Reconcile.php:36), which is also what an omitted `--mapping` means. The
mismatch had sat unrun since this file was written; the harness now builds
`--all` when the tag is null instead of type-erroring before the command even
runs. Worth remembering the shape of the failure: it wasn't a bug in a scenario
that had been passing, it was a scenario that had never once been graded.

### carries its n8n dates

AN END STATE, NOT A FEATURE OF ITS OWN. A mirror wears the workflow's clocks
rather than the sync's, and that holds however the sync started — so it is one
reusable sentence rather than two `Then`s, and any later behaviour producing a
mirror can assert it the same way.

Creation time especially: it is the one clock a later run can never reconstruct,
because after the first sync there is no "before" left to read it from.

### A sync brings the tag's workflows into Nextcloud


actor        | scope
-------------+---------------------
the admin    | one mapping        the card's "Sync from n8n"
the admin    | every mapping      the section's button
the schedule | every mapping      time as the actor

Same pre-state, same post-state. The actor and the scope are the only things
that differ, so they are COLUMNS rather than three scenarios. Whether a run is
synchronous or queued is a mechanism, and is asserted nowhere.

THIS FILE IS THE FIRST SYNC, AND ONLY THAT. Nothing is tracked yet, so whatever
is in n8n is simply a Given. A LATER run only has work to do because something
changed in n8n — and every one of those is a scenario about the change, not
about the sync: a workflow renamed upstream belongs to rename.feature, one
deleted upstream to delete.feature, a tag added or dropped upstream to
tag-sync.feature. The sync is how those arrive, not what they are.

A TAG PER ROW, deliberately. A tag may be mapped once, so every row needs its
own — and distinct tags stop one row's mirrors being read as the next row's
result.

THE DATES ARE AN END STATE, not a feature of their own: a mirror wears the
workflow's clocks rather than the sync's, and that is true however the sync
started. Creation time especially — it is the one clock a later run can never
reconstruct, because after this run there is no "before" left to read it
from. One reusable sentence, so any later behaviour that produces a mirror
can assert it the same way.

## workflows/edit

`features/workflows/edit.feature`

EDITING IS THE BEHAVIOUR; THE PUSH IS HOW IT TRAVELS.

### A local edit reaches its workflow in n8n

This was "the admin clicks Sync to n8n", which described a button rather than
anything anyone wants. Nobody edits a workflow in order to press a button — they
edit it so n8n gets the change, and the app offers three ways for that to happen
(on save, on the button, on the schedule). Those are mechanisms; this is what
they are for.

### A file outside every mapping is never pushed

Its own scenario rather than a second `Then` on the one above: "my edit travels"
and "a file I never mapped does not" are different promises, and a reader looking
for the second should not have to find it inside the first.


## workflows/rename

`features/workflows/rename.feature`

Three-way name agreement: filename stem ⇄ JSON "name" ⇄ n8n name.
The stable link is the workflow ID, so none of these break the connection — which is
what lets a rename be propagated rather than treated as a delete plus a create.

BOTH DIRECTIONS. A rename that starts in Nextcloud is carried to n8n by the listener;
a rename that starts in n8n reaches Nextcloud on the next reconcile, because nothing
in n8n calls us. The scenarios are grouped by which side moved first, since that is
the only thing that changes about them.
LIVE: rename/edit go over WebDAV; the file-locked reconcile runs in
ReconcileNameJob, so the steps drain that job class with the occ worker before
asserting both the file (PROPFIND/GET) and n8n (REST) sides.

### Renaming a workflow in n8n renames the mirrored file

══ RENAMED IN n8n ═════════════════════════════════════════════════════════

The direction with no listener: n8n cannot tell Nextcloud anything, so every one
of these needs a reconcile to become observable. The pull matches by ID and moves
the existing file rather than writing a second one — matching by NAME is exactly
what a rename would defeat.

### A failed propagation never reverts the local rename

THE ASYMMETRY WITH DELETE, AND IT IS DELIBERATE. A delete aborts when n8n
refuses, because the two sides must not disagree about whether something
exists. A name is cosmetic and self-heals, so reverting a rename under the
user's cursor would cost more than the drift does.

## workflows/ignore

`features/workflows/ignore.feature`

Reserved n8n tag — the optional, per-workflow EXCLUDE switch.

A mapping binds ONE n8n tag (ANY name — e.g. "team:flows", "myfoobarflows"; the
"nextcloud:" prefix some examples use is just a convention, NOT required) to a
folder + a mode (`sync` / `link`). That mode is AUTHORITATIVE for every workflow
in the mapping — there is no per-workflow sync/link override. The only reserved
tag the app honours is the exclude:

  n8n:ignore  — exclude this one. Two facets:
                • never-pulled workflow → no Nextcloud file at all;
                • a file already IN a mapped folder → "ignored" mode (it stays put,
                  keeps its id, is archived in n8n, and the sync skips it).

Authority is one-directional. The app NEVER writes n8n:ignore onto workflows in
n8n; it only READS it (if present) as a per-workflow exclude at pull time. You add
it yourself when you want the exception. The Nextcloud-side `n8n:sync` / `n8n:link`
system tags the app stamps on managed files are AUTHORITATIVE + automatic and just
mirror each file's mode (see the Tagging feature / view-workflow.feature) — they are
not an override mechanism.

So n8n:ignore is 100% optional: the mapping does everything on its own; the
n8n-side ignore tag is just the escape hatch to leave one workflow out.

The never-pulled ignore and the in-folder `ignored` mode are live (saga §14.8 B).
The un-tag RESTORE — removing n8n:ignore unarchives the workflow and returns the
file to the mapping's mode — is live too (saga §14.18), driven by a
TagUnassignedEvent listener.

## workflows/tags

`features/workflows/tags.feature`

Bidirectional workflow-tag sync — a workflow's tags and its Nextcloud system
tags are kept as ONE set, so the mirror is as searchable as n8n.

Two label systems, made equal (minus our control tags):

  • n8n tags       — tags on the workflow (`/api/v1/tags`, opaque ids; the
                     workflow GET body echoes `tags: [{id,name},...]`). Written
                     via a SEPARATE call: ensureTag(name)->id, then
                     setWorkflowTags(id, [ids]).
                     THE BODY CAN NEVER CARRY TAGS, ON CREATE OR ON UPDATE — this
                     is read off n8n's own OpenAPI spec, not inferred: both
                     `workflow.yml` and `workflowCreate.yml` are
                     `additionalProperties: false` with `tags: readOnly: true`.
                     `PUT /workflows/{id}/tags` (tag IDS, not names) is the only
                     writer there is. `N8nWorkflowBody`'s writable whitelist omits
                     `tags` for exactly that reason.
  • Nextcloud tags — collaborative SYSTEM TAGS (the coloured pills in Files,
                     searchable via DAV REPORT).

THE RULE OF EQUALITY: after a reconcile a managed workflow's n8n tags and its
Nextcloud system tags hold the same strings, with ONE exclusion — the app's
reserved namespace `n8n:*` (`n8n:sync`, `n8n:link`, `n8n:ignore`, and any future
control tag). Reserved tags are the app's control plane: never pushed to n8n,
never imported from n8n as content.

THREE EDIT SURFACES — the object body is the third: tags are part of the object,
so a sync file's on-disk JSON already has a `tags` array. That makes three
editable places, kept as one set:
  1. n8n tags on the workflow    (edit in n8n → pull)                    — LIVE
  2. the file body `tags` array  (edit the JSON → push)                  — DEFERRED
  3. Nextcloud system-tag pills  (edit the pills → push)                 — LIVE
TODAY the body `tags` array is a DERIVED MIRROR the pull writes; a hand-edit of it
is NOT projected to n8n and self-heals on the next pull. The PILLS are the
authoritative Nextcloud tag surface today (surface 3). In `link` mode the body is a
pointer (not the object), so only surfaces 1 and 3 exist and the pills are a
read-only projection of n8n.

THE THREE SURFACES ARE NOT PEERS — ONLY ONE IS PORTABLE (saga §5.6.3). This is what
decides the model, and it is a fact about the surfaces rather than a preference:

  surface            survives export/re-import?   survives a copy?
  n8n tags           n/a — it IS the remote        n/a
  NC pills           NO — bound to a file id       NO (NC doesn't copy system tags)
  body `tags`        YES — it is bytes in the file YES

So a `.n8n.json` that leaves Nextcloud and comes back carries its tags in exactly
one place: its own body. Nothing else can know them.

AUTHORITY BELONGS TO THE MOMENT, NOT TO A SURFACE. "The JSON is the source of truth"
and "n8n takes precedence" are not in conflict once they are separated by when:

  ADOPTION (a file becomes managed: create / copy / move-in)  → THE BODY WINS
      Nothing else knows. No pills, no metadata, no workflow yet.
  STEADY STATE, no Nextcloud edit                            → n8n WINS
      n8n is the system of record; the pull heals both NC surfaces and a stale
      body loses. A file-vs-n8n disagreement with no NC edit resolves to n8n.
  A DELIBERATE NEXTCLOUD EDIT (a pill toggle, or a body-`tags` edit) → THE EDIT WINS
      The user acted; carry it to n8n.

WHY PICKING A WINNER IS NOT ENOUGH ON ITS OWN: `body {a,b}` vs `n8n {a,b,c}` is the
SAME two sets whether the user deleted `c` from the file or added the `c` pill while
the body sat stale — and the correct answer is opposite in each case. A fixed winner
does not resolve that, it only picks which of two legitimate gestures to break. The
BASELINE is what says who moved (see PROVENANCE below); precedence is then needed
only where there is no baseline at all — which is adoption, and there n8n's rule is
the tiebreak.

NO EXTRA BUTTON FOR TAGS — a pill edit auto-propagates (LIVE, Slice A): adding or
removing a system-tag pill on a managed `sync` file is caught by a dedicated tag
listener (`TagAssignedEvent`/`TagUnassignedEvent` for CONTENT tags, not only the
reserved `n8n:ignore`). Today it reconciles the tag to n8n via the tags-only path
(`setWorkflowTags` → `PUT /workflows/{id}/tags`), NEVER the body PUT — so it is
decoupled from full-file writeback and safe on archived / odd-body workflows.
(DEFERRED, Slice B: the listener would ALSO update the file body's `tags` array in
place with a loop-safe write — re-stamping `n8n_syncedHash` so the `NodeWrittenEvent`
the write emits is recognised as the app's own and does NOT re-push the whole file.
That body lockstep is not wired today; the body self-heals on the next pull instead.)
The reconcile honours the SAME `timing` knob the save-push already uses:
  • `sync`  — reconcile inline during the request (instant, may briefly lock).
  • `async` — enqueue a per-file job the cron worker runs on its next tick.
This is the existing reconcile engine, triggered by the tag event and scoped to the
one file — not a new manual action, and not a global scheduled push (there is NO
scheduled NC→n8n sweep; the only bulk NC→n8n path is the manual "Sync to n8n").

BODY EDITS WOULD RIDE THE SAME PATH AS `name` (DEFERRED, Slice B — saga §5.6.2.3): a
hand-edit of the JSON `tags` array is just a `NodeWrittenEvent`, the very event the
filename/`name` reconcile already listens on. The INTENT is that adding or removing a
tag inside the body becomes a first-class edit: the pills follow the body and the
next push carries the change to n8n. This is NOT wired today (the attempt regressed
the pill path and was reverted); the body is a derived mirror for now.

PULL CHANGE-DETECTION — LIVE (saga §5.11). A pull writes the body only when the
mirror's bytes actually differ from what n8n would write. It used to call
`putContent($body)` UNCONDITIONALLY for every workflow on every pull, so every
mirrored file was reported as modified after every tick — on a 5-minute schedule
that is a folder where nothing is ever older than five minutes, and a real edit is
indistinguishable from the sweep that ran past it.

One branch per workflow, and only the first is new:
  • body identical → SKIP the write. The pills and the baseline are still
                     reconciled — those writes are diff-based and already cost
                     nothing when nothing changed — so a skipped body never means
                     a skipped repair.
  • body differs   → write it (it already carries n8n's `tags`), then reconcile
                     the pills. A tags-only change in n8n IS a body difference,
                     because the body is the n8n row verbatim: the write lands on
                     the `tags` array and leaves the rest byte-identical.

"Differs" is measured against the FILE'S OWN BYTES, not the stamped
`n8n_syncedHash`. The stamp only records what the last sync agreed on; a mirror
that drifted since (a failed push, a hand edit, a partial write) still has to be
healed by the pull, and only comparing the real bytes sees that.

SEARCHABILITY IS MODE-INDEPENDENT: the pull-side systemtag reconcile runs for
BOTH `sync` and `link` files. A `link` file is never pushed, so its tags flow one
way only: n8n → Nextcloud.

PROVENANCE — a new tag from Nextcloud vs a new tag from n8n: when the two sets
differ on a string you cannot tell an ADD on one side from a REMOVE on the other
from the current sets alone. So the app banks the reserved-stripped tag set as of
the last successful sync in `n8n_syncedTags` (the tag analogue of
`n8n_syncedHash`) and three-way-merges against it. Against a single baseline the
merge is DETERMINISTIC — there is no add-vs-remove conflict to break: a tag is
ADDED only if it was not in the baseline (so at least one side newly has it) and
REMOVED only if it was in the baseline (so a side dropped it), and those are
disjoint. Rule: add-on-either-side keeps the tag; REMOVE-ON-EITHER-SIDE drops it
(the side that dropped a baseline tag is the one that changed, so it wins over the
side that left it untouched). Direction (pull vs push) is NOT a merge input — it
only decides which side the merged set is written back to.

MAPPING-TAG PROTECTION (n8n-only): n8n maps a folder BY TAG, so the tag that binds
a workflow to its folder is itself a content tag. It is shown as a pill for
visibility but is PROTECTED: a reconcile FORCE-KEEPS it on both sides, so removing
it from either Nextcloud surface — the pill OR the body `tags` array — never
pushes a tag removal that would unbind the workflow and prune the mirror. Leaving
a mapping is always an EXPLICIT gesture, and there are exactly
two sanctioned forms: (1) move the file out of the folder → `unmapped` (workflow
archived in n8n, restored on move-back), or (2) tag it `n8n:ignore` → `ignored`
(workflow excluded from the mapping, file kept standalone). Removing the mapping
pill AS a deliberate eject is therefore treated as form (2): it is paired with
`n8n:ignore` so the file is KEPT, never silently pruned. This hazard has no Grafana
analogue (Grafana maps by real folders).

PRUNING — minimal in the end without wrecking shared catalogs. Tags exist at two
levels on each side: the ASSIGNMENT (tag is on this workflow/file) and the
DEFINITION (the catalog entry — an n8n `/api/v1/tags` row, or a Nextcloud system
tag). The reconcile prunes ASSIGNMENTS aggressively and both ways: remove-on-either-
side drops the edge, so the mirror never carries a tag the canonical side let go.
That is the pruning that matters, and it is already bi-directional.

DEFINITIONS are deliberately NOT auto-pruned. Neither catalog is ours alone — a
system tag ("urgent") may be pinned on non-workflow files by a human, an n8n tag may
sit on workflows outside any mapping — so deleting a definition because no MANAGED
object uses it would strip it off bystanders. An orphaned definition is cheap and
harmless (a dead pill in the picker, an n8n tag that maps nothing) and is often a
human about to reuse it. So the minimal-in-the-end system is NOT "GC every
unreferenced tag"; it is a perfect edge reconcile plus prune-free minting.

PRUNE-FREE BY CONSTRUCTION: we never mint a throwaway definition. `ensureTag(name)`
reuses an existing catalog entry by name on both sides (idempotent — no duplicates);
reserved `n8n:*` never crosses, so n8n's catalog never grows a control tag; and a
reconcile computes the FINAL merged set FIRST, then writes once (assign/unassign the
winners), so we never create a pill or n8n tag we are about to drop. The baseline
`n8n_syncedTags` is itself kept minimal — reserved-stripped, blank-filtered, deduped,
sorted — and dies with the file, so metadata never leaks.

OPTIONAL DEFINITION SWEEP (planned, opt-in, symmetric): if an admin wants the
catalogs swept, it is an EXPLICIT `occ` command, dry-run first, NEVER on the
reconcile hot path. Its predicate is conservative and identical on both sides: a
definition is a candidate ONLY if it is non-reserved, not a mapping tag, and orphaned
on BOTH sides at once — a tag still used on either side survives. Symmetry is the
whole point: nothing alive anywhere in the pair is ever swept.

ENGINE WIRED, SURFACES 1 & 3 LIVE — SURFACE 2 (BODY) DEFERRED: the tag-reconcile
engine ({@see TagSyncService} + the pure {@see TagMerge} three-way merge) and the
`n8n_syncedTags` baseline key are implemented and unit-tested (saga Ch5 §5.6):
pull mirrors n8n → pills for sync AND link, push writes pills → n8n for sync, the
baseline disambiguates add-vs-remove, the reserved `n8n:*` namespace is excluded,
and the mapping tag is protected. Those n8n↔pills scenarios are LIVE. As of
§5.6.2 Slice A the PILL EDIT IS REACTIVE: adding/removing a content pill on a sync
file is caught by {@see ContentTagListener} and reconciled to n8n on its own — no
"Sync to n8n" click — honouring the same `timing` knob as the body writeback
(`sync` inline, `async` via {@see ReconcileTagsJob}).

SURFACE 2 (edit the JSON `tags` array) IS DEFERRED (saga §5.6.2.3): Slice B was
built (body-canonical push in {@see PushService}) and then REVERTED before merge —
CI caught that its shared-merge refactor regressed the shipping pill path. TODAY the
body `tags` array is a DERIVED MIRROR a pull writes: a hand-edit of it is NOT
projected to n8n and self-heals (is overwritten) on the next pull. The pills are the
authoritative Nextcloud tag surface — re-tag from the pills, not the JSON. The
reconcile engine ({@see TagReconcileService::reconcileFromBody}) is kept, unit-tested
but UNWIRED, for when the feature is picked up as its own `NodeWrittenEvent` trigger
(and it must be verified live before its `@todo` scenarios come off).

Still PLANNED (`@todo` per-scenario): (1) ADOPTION carrying the body's tags into n8n
— a DEFECT today, not merely unbuilt: the tags are silently discarded (saga §5.6.3),
(2) the body↔pills projection scenarios (surface 2), (3) PULL CHANGE-DETECTION
(skip-unchanged / body / tags-only branches), and (4) the reactive eject and the
optional catalog sweep. Shared with the Grafana sibling; per-backend knobs = tag
write path, reserved prefix, protected-tags set.

WHAT IS REALTIME, AND WHAT CANNOT BE:
  pill → n8n         realtime (`timing=sync`) or next tick (`async`)   — LIVE
  file body → n8n    would be realtime on the same NodeWrittenEvent    — PLANNED
  n8n → Nextcloud    scheduled pull only                               — POLL-ONLY
The third is not a gap that can be closed: n8n emits no outbound event on a tag
change. The near-realtime answer stays "build an n8n workflow that pushes to
Nextcloud", the same escape hatch the schedule setting already advertises.

SCOPE — TAG SYNC IS A MAPPED-FOLDER FEATURE: every tag behaviour here (pull mirror,
push, auto-trigger, change-detection) applies ONLY to a file managed by a mapping.
An `unmapped` or `ignored` file is a plain Nextcloud file — its pills are ordinary
system tags with NO n8n side effect — so the machinery must not leak onto it.

KNOWN, NOT SOLVED HERE — ONE WORKFLOW, MANY MAPPINGS: a workflow carrying two
mapping tags is mirrored into two folders (two files, one shared n8n object). A tag
edit on one mirror reaches n8n but the sibling only catches up on its next pull;
converging every mirror of an id in one gesture is future fan-out work (specced
`@todo` at the end, deliberately out of scope for now).

### Applying a set of tags is one gesture

── RULE: A TAG CHANGE IS A NEW SET, NOT A POKE ─────────────────────────────

This file used to spell out six scenarios where it now has three outlines:
"a tag added in n8n", "a tag removed in n8n", "a pill added", "a pill
removed", "a tag typed into the file", "a tag deleted from the file". They
were six sentences for one rule. Nobody adds a tag; a person edits a list and
saves it, and whether that list gained or lost an entry is a property of the
values, not of the behaviour. So the gesture is "the tags are now THIS", the
add/subtract cases are rows of an `Examples` table, and the interesting
combinations — replace the whole set, empty it, tag something that had none —
became reachable at last, because they cost a row rather than a scenario.

WHAT THE SETS DELIBERATELY DO NOT CONTAIN: the mapping tag, and anything in
the reserved `n8n:*` namespace. Neither is a label a person applied; one is
the binding to a folder and the other is this app's control plane. A set that
listed them would be asserting the binding survived rather than asserting
anything about tags, on every single row. They are added back by the arrange
and stripped from every assertion, and the scenarios that are genuinely ABOUT
them name them explicitly.

THE IDS ARE PART OF THE END STATE, NOT A SCENARIO OF THEIR OWN. n8n's API
forces the shape — `tags` is readOnly on both create and update, so a body save
can never carry tags; they go via `PUT /workflows/{id}/tags`, separately,
always. A human editing the JSON writes `{"name": "prod"}` with no id, and that
must work: we resolve the name for n8n and leave the file as typed, then write
n8n's canonical `{id,name}` rows back. So the file is briefly "wrong" in a way
that self-corrects, deliberately — and "the rows are canonical again" is simply
what a settled tag change looks like, whichever surface it started on. It used
to be a `@todo` scenario saying no more than one line of the payoff assertion
now says, at the cost of a whole live run.

THE SURFACES ARE THREE SCENARIOS, NOT THREE ROWS, and that is a rule from
`.github/instructions/gherkin.instructions.md` rather than a preference:
origin is exclusive, so a scenario is `@in-nextcloud` or `@in-n8n` and never
both, and `Examples` rows must be one rule over different inputs. A pill edit
and an n8n edit are different rules with different payoffs. The surface is
therefore the scenario; the set is the input.

TIMING IS NOT IN THE SPEC EITHER, and for the same reason. The app can write a
tag change back during the request or hand it to the worker for its next tick —
that is a knob in our plumbing, not something a person does, and a scenario
named after it was describing the implementation out loud. Both settings end in
the same place, which is the only thing the spec has an opinion about. The
harness pins one so a scenario does not inherit whatever the previous one left
behind. The same goes for "a job was queued": a queue is how the work waits, and
"nothing reached n8n" already says the part that matters, against n8n's own API
rather than against our job list.

WHY NO `When the mapping is pulled` SURVIVES IN THIS FILE. Nobody changes a
tag in order to run a reconcile. n8n emits no outbound event, so a pull is
simply HOW the news of an n8n-side change arrives — mechanism, not behaviour,
and a spec written on it has to be rewritten every time the plumbing moves. It
is folded into the gesture step instead, and the scenarios say only what a
person did and what came of it. The same reasoning retires the `pushed` /
`reconciled` phrasings and the `@unbuilt` catalog-sweep scenarios, whose only
action was "the sweep ran".

### Changing the tags on a link does not change them in n8n

A link is a READ-ONLY projection of n8n's tags: the pills are there so you can
search, but n8n is the only writer. A tag added on a link never pushes (the
reactive reconcile gates on `sync`), and because a link has no push channel
that stray tag would linger forever — so the next sync wipes it, mirroring n8n
exactly. Both halves are asserted in one scenario because they are one rule;
splitting them produced a scenario whose only claim was that nothing happened.

Searchability is asserted here rather than in a scenario of its own. It is the
POINT of mirroring tags at all, and a link is the strongest place to say it:
the file holds no workflow, so its tags are the only thing making it findable.

### Changing the tags on an unmapped file never reaches n8n

── RULE: THE NEXTCLOUD PAIR IS LOCAL; ONLY THE n8n LEG NEEDS A MAPPING ─────

A `.n8n.json` has pills and a `tags` array whether or not it lives in a mapped
folder. Keeping THOSE TWO in step is a Nextcloud-local concern — there is no
remote system involved — so it happens for every workflow file, mapped or not.
Only the third participant, n8n, requires a mapping.

    pills  ⇄  body        always, for any .n8n.json file
    pills/body  →  n8n    only for a managed `sync` file
    n8n  →  pills/body    only for a mapped folder, on a sync

THIS IS WHAT MAKES THE TRANSPORT CASE WORK END TO END. Tags applied while a
file sits outside every mapping are recorded in the body, which is the only
surface that survives being moved, copied, or carried out of Nextcloud — so
when the file is later dropped into a mapped folder, the tags are still there
to seed n8n. That adoption is `workflows/create.feature`'s to own, and it is
specced there; this file only pins the local pair it depends on.

### A reserved n8n: tag never becomes a Nextcloud tag

THE RULE OF EQUALITY has exactly one exclusion: the app's reserved namespace
`n8n:*` (`n8n:sync`, `n8n:link`, `n8n:ignore`, and any future control tag).
Reserved tags are the app's control plane — never pushed to n8n, never
imported from n8n as content.

It is stated here as a tag CHANGE (someone adds `n8n:sync` in n8n) rather than
as an end state of a sync, because that is the moment the exclusion has to
hold. What `n8n:ignore` then DOES is `workflows/ignore.feature`'s subject, not
this file's.

### When both sides moved, neither change is thrown away

PROVENANCE — a new tag from Nextcloud vs a new tag from n8n: when the two sets
differ on a string you cannot tell an ADD on one side from a REMOVE on the
other from the current sets alone. So the app banks the reserved-stripped tag
set as of the last successful sync in `n8n_syncedTags` (the tag analogue of
`n8n_syncedHash`) and three-way-merges against it. Against a single baseline
the merge is DETERMINISTIC — there is no add-vs-remove conflict to break: a
tag is ADDED only if it was not in the baseline (so at least one side newly
has it) and REMOVED only if it was in the baseline (so a side dropped it), and
those are disjoint. Rule: add-on-either-side keeps the tag; REMOVE-ON-EITHER-
SIDE drops it. Direction is NOT a merge input — it only decides which side the
merged set is written back to.

WHY PICKING A WINNER IS NOT ENOUGH ON ITS OWN: `body {a,b}` vs `n8n {a,b,c}`
is the SAME two sets whether the user deleted `c` from the file or added `c`
in Nextcloud while the body sat stale — and the correct answer is opposite in
each case. A fixed winner does not resolve that, it only picks which of two
legitimate gestures to break.

THESE TWO SCENARIOS USE THE DELTA PHRASING (`the tag "prod" is added to the
workflow in n8n`) WHERE EVERY OTHER USES THE SET, and that is load-bearing
rather than sloppy: restating n8n's whole set here would overwrite the
Nextcloud change inside the arrange and the scenario would prove nothing. A
set is the right vocabulary for a gesture; a delta is the right vocabulary for
"and meanwhile, elsewhere".

AUTHORITY BELONGS TO THE MOMENT, NOT TO A SURFACE:

  ADOPTION (a file becomes managed)                → THE BODY WINS
      Nothing else knows. No pills, no metadata, no workflow yet.
  STEADY STATE, no Nextcloud change                → n8n WINS
      n8n is the system of record; a stale body loses.
  A DELIBERATE NEXTCLOUD CHANGE                    → THE CHANGE WINS
      The user acted; carry it to n8n.

### The mapping tag is the binding, not a label anyone may drop

MAPPING-TAG PROTECTION (n8n-only, no Grafana analogue — Grafana maps by real
folders): n8n maps a folder BY TAG, so the tag that binds a workflow to its
folder is itself a content tag. It is shown as a pill for visibility but is
PROTECTED: a reconcile FORCE-KEEPS it on both sides, so removing it from
either Nextcloud surface — the pill OR the body `tags` array — never pushes a
removal that would unbind the workflow and prune the mirror.

THE SCENARIOS SAY WHAT THE APP DOES, NOT WHAT IT MIGHT DO. There is a live
design question about whether a deliberate drop should instead UNSYNC the file
— push one last tag change, then remove the mirror while n8n keeps the rest —
and it is a reasonable design, since nothing is lost when n8n still has the
workflow. It is NOT what the code does, so it is not written as a scenario:
a spec that describes an intention is indistinguishable from one describing a
defect, and this file has been burned by that before. When it is built it
replaces the two protection scenarios; until then they are the truth.

Leaving a mapping is always an EXPLICIT gesture, and there are exactly two
sanctioned forms, neither of which is a tag change: move the file out
(`workflows/move.feature`) or tag it `n8n:ignore` (`workflows/ignore.feature`).

THE n8n SIDE IS THE OPPOSITE, AND THAT ASYMMETRY IS THE POINT. The mapping tag
is what makes a workflow the folder's, so removing it UPSTREAM says the
workflow no longer belongs and the mirror follows. Nextcloud may not drop the
binding; n8n may, because n8n is where membership is decided.

### Dropping a tag sweeps the edge, never the shared catalog

Tags exist at two levels on each side: the ASSIGNMENT (this tag is on this
workflow/file) and the DEFINITION (the catalog entry — an n8n `/api/v1/tags`
row, or a Nextcloud system tag). The reconcile prunes ASSIGNMENTS aggressively
and both ways: remove-on-either-side drops the edge, so the mirror never
carries a tag the canonical side let go.

DEFINITIONS are deliberately NOT auto-pruned. Neither catalog is ours alone —
a system tag ("urgent") may be pinned on non-workflow files by a human, an n8n
tag may sit on workflows outside any mapping — so deleting a definition
because no MANAGED object uses it would strip it off bystanders. An orphaned
definition is cheap and harmless and is often a human about to reuse it.

PRUNE-FREE BY CONSTRUCTION, asserted on the same scenario: `ensureTag(name)`
reuses an existing catalog entry by name on both sides (idempotent — no
duplicates); reserved `n8n:*` never crosses; and a reconcile computes the FINAL
merged set FIRST, then writes once, so we never mint a definition we are about
to drop. That used to be a scenario of its own whose action was "a reconcile
ran" against a state where nothing had changed — it says more as an assertion
on a reconcile that genuinely drops a tag, and costs nothing.

AN OPTIONAL DEFINITION SWEEP is still the plan for admins who want the
catalogs tidied: an EXPLICIT `occ` command, dry-run first, never on the
reconcile hot path, whose predicate is conservative and identical on both
sides — a definition is a candidate ONLY if it is non-reserved, not a mapping
tag, and orphaned on BOTH sides at once. It has no scenarios, because a
command that does not exist cannot have a gesture; the two that existed said
only "the sweep ran" and were retired with the rest of the mechanism `When`s.

### Changing tags on one mirror should converge its sibling (future fan-out)

── one workflow mirrored by several mappings (known, not solved here) ──────
A single n8n workflow can carry two mapping tags at once, and each mapping
mirrors it into its own folder — so the SAME workflow id exists as TWO managed
files in Nextcloud. They share one canonical object in n8n, so an n8n tag is a
property of the workflow, not of either file.

The hazard: change the tags on ONE mirror and n8n now holds the merged set —
but the SIBLING file still shows its old tags until its own mapping is next
synced, and its stale `n8n_syncedTags` baseline could then read a since-agreed
tag as a local remove and bounce it. Converging all mirrors of one id on a tag
change (fan-out by workflow id, not just by file) is the real fix and is
deliberately OUT OF SCOPE; this scenario only PINS THE SHAPE.

A SECOND hazard in the same setup: on the "flows" mirror the OTHER mapping's
tag shows as an ORDINARY content tag — it is not THIS mapping's protected tag
— so dropping it here would unbind the workflow from the other mapping. The
protected set must therefore be the UNION of every mapping tag on the
workflow. That was its own `@unbuilt` scenario and is now recorded here
instead: its action was a push, and a hazard nobody can trigger yet is a note,
not a specification.

### tags.feature — WHAT WAS RETIRED, AND WHY

Twenty-nine scenarios became fourteen. Nothing here was deleted for being
wrong about the app; the entries below were duplicates, mechanisms, or other
files' business.

MECHANISM AS THE ACTION — the largest group, and the rule they broke is the
oldest one in `gherkin.instructions.md`: describe behaviour, not
implementation. `When the "flows" mapping is pulled` / `is pushed` /
`is reconciled` / `When both mappings are pulled` / `When an admin runs the
optional catalog sweep`. Where the scenario had a real gesture underneath, the
sync was folded into it; where the sync WAS the scenario, it went.

  · Push writes Nextcloud content tags into n8n (sync only)
  · Adding a pill pushes the tag to n8n immediately when timing is "sync"
  · Removing a pill removes the tag from n8n on its own
  · A tag added in Nextcloud since the last sync is added in n8n
  · A tag removed in n8n since the last sync is removed in Nextcloud
  · A tag removed in Nextcloud since the last sync is removed in n8n
  · Reconcile never mints a definition it is about to drop
  · An optional catalog sweep keeps any tag still used on either side
  · An optional catalog sweep never removes a reserved or mapping tag
  · One workflow with two mapping tags is mirrored into both mapped folders
  · A sibling mapping's tag is protected on every mirror

END STATE OF SOMETHING ELSE. A mirror wearing its workflow's tags is what a
SYNC leaves behind, not a tag change, so it is asserted where the sync lives:
`connection/sync-now.feature` now seeds its workflows with an ordinary tag and
asserts every mirror wears it. Without that extra tag the assertion would pass
on a mirror that imported nothing at all.

  · Pull mirrors n8n tags onto the Nextcloud file as system tags
  · The reserved namespace is never imported as a content tag  (kept, restated
    as a change made in n8n)
  · Pull mirrors tags even for a link mapping (searchability, not push)
  · A tag added in n8n lands on the link on the next pull  — `@in-n8n` is
    mode-agnostic; the n8n outline covers both modes
  · A content change pulls the new body and then reconciles the tags — a
    CONTENT change, and its tags half survives as `nothing else in the file
    changed` on the n8n outline
  · A tags-only change in n8n reaches the pills and the tags array, and
    nothing else — same

ANOTHER FILE'S GESTURE. Each was already specced where its verb lives; the
only thing missing was an assertion, which was added there.

  · Moving an untracked tagged file into a mapping creates it in n8n with its
    tags → `workflows/create.feature` (adoption) and `workflows/move.feature`
  · The tags an adopted file arrives with come back with real ids →
    `workflows/create.feature`
  · Moving the file out is the sanctioned unmap — it changes no tags →
    `workflows/move.feature`. A move never changes tags, so the assertion is
    "nothing happened" and belongs to the move that could have caused it.
  · Ejecting via n8n:ignore keeps the file instead of pruning it →
    `workflows/ignore.feature`, which already has the same gesture
  · Removing the mapping pill as a deliberate eject is paired with n8n:ignore
    → the same, and see the mapping-tag section above

NEGATIVE-ONLY, WITH SOMEONE ELSE'S ACTION. A save is `workflows/edit.feature`'s
gesture, and "the tags did not move" is its end state, not a tag behaviour.

  · A save that did not touch the tags must not undo a pill edit
  · With no Nextcloud edit, a file that disagrees with n8n loses — the n8n
    outline says exactly this, with a gesture

THE AMBIGUITY THIS FILE WAS BUILT AROUND, kept because it is the reason the
design is what it is. Before a Nextcloud tag change wrote the body too, the
stale body and a deliberate removal were the SAME on-disk state:

    pills "a,b,c"  body "a,b"   → the user deleted `c` from the file  (remove)
    pills "a,b,c"  body "a,b"   → a pill was added, body not rewritten (ignore)

Identical inputs, opposite correct answers. The lockstep removed the only
thing that produced staleness, which is why the body is a first-class surface
here instead of a derived mirror — and why "a save must not undo a tag change"
stopped being a scenario this file needs: there is no stale state left for it
to catch.

## uninstall

`features/uninstall.feature`

Uninstall lifecycle — what happens to the SYSTEM and to the user's DATA when the
app is removed, and that a reinstall reconnects cleanly.

  - SYSTEM: removing the app runs the <uninstall> repair step (UnregisterMimetype),
    which REVERTS the custom-mimetype registration the install wrote into the
    Nextcloud core tree (config/mimetype*.json, core/img/filetypes/n8n.svg,
    core/js/mimetypelist.js) and re-stamps the .n8n.json filecache rows back to
    application/json. The store's clean-uninstall rule is about this shared state.
  - DATA: the app ORPHANS the user's data — it never deletes the .n8n.json files,
    never clears their Files-Metadata, never deletes Team Folders, never touches
    n8n. A sync folder is a full backup, so deleting it would be data loss. To wipe
    the Nextcloud side deliberately, an admin uses Purge first (see purge.feature).

Because the files keep their n8n_id, a reinstall + pull RECONCILES them in place
(matched by id, never duplicated) — the reconnect is free, by design.

The <uninstall> system leg needs a full app remove on a live pod (CI can't drive
it), so it stays skipped; the data-orphan + reinstall-reconnect legs are provable
via disable/re-enable + a pull, which exercises the same metadata-keyed reconcile.
@blocked, NOT @todo, and the missing capability is named: the CI harness can only
disable and enable the app, never remove and reinstall it. No test anyone writes
will pass until that exists, which is exactly the distinction the tag makes.

NOTE THE TAG IS ON THE `Feature:`, so it excludes EVERY scenario below, including
the data-orphan ones a disable/enable could genuinely prove. That is deliberate
but easy to misread: the DATA promise — reinstall reconciles existing files in
place by id with NO duplicates — is already proven LIVE by sync-now.feature
("existing files are updated in place — matched by workflow id, never
duplicated"), and a disable/enable changes nothing about that reconcile, so
re-proving it here would be duplicate coverage of one behaviour in two files.
If this file is ever un-blocked, delete those scenarios rather than run them.
