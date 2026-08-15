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

### A folder inside another mapping's folder may not be mapped

NESTING IS REFUSED RATHER THAN RESOLVED, and this replaces a scenario that tried
to resolve it. `workflows/create.feature` used to say a file created in a nested
mapping "belongs to the nearest one" — a rule that had to be invented because
nesting was allowed, and one nobody would predict from the outside. Two mappings
claiming overlapping trees make every file in the overlap ambiguous, and picking
a winner by path depth is arbitrary: it is a rule the app knows and the user does
not.

Refusing at the point of creation is the honest answer. The admin is told at the
moment they can still choose a different folder, rather than discovering months
later that some files went to one mapping and some to another.

THE SIBLING OF `Two mappings may not target the same folder`, and the same
argument one level up: that rule stops two mappings pointing at the SAME folder,
this one stops one pointing INSIDE another. Both exist because a mapping owns a
subtree, not a directory entry.

Grafana is the exception to watch when this is ported: it mirrors recursively, so
a subfolder under a mapped root is a real thing there (`folders/create.feature`)
— but it is part of the parent mapping rather than a mapping of its own, which is
the distinction this rule is drawing.

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
for the "author in NC, live in n8n" flow. LIVE: a .n8n written over WebDAV
into a mapped folder fires NodeWrittenEvent → CreateInN8nListener → the workflow
appears in n8n. The n8n side is asserted over its REST API; the NC stamp over
DAV PROPFIND of nc:metadata-n8n_id.

### The tags a file arrives with are the tags its workflow ends up with

Lives in `workflows/move.feature`, and the move is why. A file arriving in a
mapped folder already tagged has no baseline and no remote counterpart, so there
is nothing to merge against — the three-way merge does not apply, and the body is
the only surface that knows what the file was carrying. Pills are bound to a file
id and are lost on a copy; DAV metadata is stripped by a round trip through
anything that is not Nextcloud. The `tags` array is bytes in the file.

THE MAPPING TAG JOINS what arrived rather than replacing it.

IT USED TO LIVE IN `create.feature`, AND THAT WAS THE MISTAKE. Three scenarios
there described this — one for the arrival, one for the ids coming back, one for a
round trip — and none of them was a create. The gesture in every case was a MOVE
or a COPY; "a workflow is created in n8n" is what the app does about it, which is
implementation. A file moved into a mapping whose workflow still exists is closer
to a re-sync than a create anyway, and the ids differing does not change whose
gesture it is.

THE IDS ARE NOT A SCENARIO EITHER. A tag n8n has never seen has no id, so the body
arrives with bare `{"name": …}` rows and n8n mints them; the first sync writes the
canonical rows back. That is the END STATE of the tags being right, not a separate
behaviour — and "n8n's tag catalog gained an entry" is implementation detail. The
scenario that asserted it was the arrival scenario again with one extra line.

### The copy belongs to where it lands

THE ONLY INPUT IS THE DESTINATION. A copy is always a new instance — never the
original's identity — so what it BECOMES is decided entirely by the folder it
lands in: managed in that mapping's mode, or a plain document if it landed
outside every mapping. Where it came FROM does not enter into it, which is why
the source is an `Examples` column and not a scenario apiece.

Four scenarios used to spell that out one source at a time (within a mapping,
out to nowhere, from unmapped to nowhere, from unmapped into a mapping) and
between them they still missed the cases that would actually catch a bug: a copy
out of a LINK, and a copy into a Team-Folder-backed mapping. Rows are cheap;
scenarios are not.

THE MAPPING TAG IS POST-STATE TOO, AND IT IS THE ONE THAT CHANGES. A workflow
belongs to a mapping by wearing its tag, so a copy landing in another mapping
comes out carrying THAT mapping's tag and not the one it was copied from. Getting
this wrong does not fail loudly: the workflow would wear both tags, be claimed by
two mappings, and be mirrored into two folders as two files sharing one id — the
fan-out hazard, arrived at by accident.

The two Examples blocks split on exactly that. Within one mapping the binding is
simply kept, and the case is dull; across mappings it is REPLACED, and that is the
rule worth reading. Same outline because it is one rule — the copy belongs where
it landed — with the block titles saying which half of it each row exercises.

### A copy carries the tags that travelled in its body

IT IS A SCENARIO AGAIN, AND ONLY BECAUSE OF ITS STATUS. This was folded into the
outline above as an `Examples` column — tags are pre/post state, so they belong to
the scenario that already performs the gesture — and CI rejected it: adoption
reads a file's body tags only in `create.feature`'s `@unbuilt` scenarios, so a
copy today lands carrying nothing but its mapping tag. Asserting the tags on a
LIVE outline asserted a feature nobody has written.

A row cannot be `@unbuilt` while its siblings run, so the claim needs a scenario
of its own until adoption lands. When it does, this folds back into the outline
as the column it wants to be.

The `in Nextcloud` half is the part that will stay hard: Nextcloud does not copy
system tags at all, so the pills a copy ends up with can only come from the app
re-applying them out of the body. That is the same mechanism adoption needs, and
it is why the two are one piece of work rather than two.

TAGS ARE A COLUMN, NOT A SCENARIO — WHEN THE BEHAVIOUR EXISTS. `A copy carries the tags that travelled in
its body` was this same outline with tags asserted instead of metadata — the
gesture, the rule and the arrange were identical. Tags and metadata are PRE AND
POST STATE, so both belong to the scenario that already performs the gesture:
one `Examples` column for what the file carried, one `Then` for where it ended
up. A copy that lands outside every mapping keeps its body tags too, and says so
in the same shape.

### A copy made in Nextcloud is named by Nextcloud

**Who performed the gesture decides who names the result.** A copy made in the
Files app is named by Nextcloud — it has to be, because the bytes it copied would
otherwise collide with the file they came from — and that name is then the copy's
real name in all three places: the filename, the JSON `name`, and the workflow in
n8n.

Left to itself, none of that happened. Copying `Fleet Health.n8n` beside
itself produced, on the live instance:

    file    Fleet Health (1).n8n     <- the only place the copy's name appeared
    JSON    "name": "Fleet Health"   <- the ORIGINAL's name, copied verbatim
    n8n      Fleet Health            <- a second workflow, same name

Three places, two answers. The body cannot be blamed for it: a copy's bytes ARE the
original's, so of course its `name` says the original's name. The only party that
knows a copy happened is the file's name.

**The name it reads is `display`, not `name`.** `FilenameCodec::parse()` returns
both, and they differ by exactly the collision counter. Taking the counter-stripped
one — the field a PULL wants, so a mirror already wearing `(1)` is matched to its
workflow next time — is what let the copy reach n8n under the original's name.
`FilenameCodec::displayName()` exists so that mistake has to be made deliberately.

`CreateService` reads the display name off the filename before the POST, so n8n is
right inside the request. Only the file's own JSON `name` lags, by a tick, fixed by
`ReconcileNameJob` — the copy hook cannot write the file it is holding locks on.

### Nextcloud names the copy, and the extension decides whether that hurts

**Nextcloud's SERVER does not name a copy at all.** WebDAV COPY means "copy to
exactly this path"; if something is already there and `Overwrite: F`, the answer is
a flat 412. The name is chosen in the BROWSER, by `getUniqueName()` from
`@nextcloud/files`, which counts from 1 and puts the counter before the LAST
extension. There is a `suffix` option and no way for an app to pass one, so **the
rule cannot be changed** — the app agrees with it instead. `FilenameCodec::format()`
puts the counter in exactly that position, so a copy is born with the name the codec
would itself have chosen:

    Fleet Health (1).n8n        <- what the client picks, and what we want

#### What it cost when the two disagreed

Under the retired `.n8n.json`, `extname()` answered `.json` and the basename was
`Fleet Health.n8n`, so the client produced `Fleet Health.n8n (1).json` — confirmed
live as `FooBoblicious.n8n (1).json`. That does not end in the app's extension, so
every predicate answered "not ours": no metadata, no workflow in n8n, and a file that
looks managed and is not. Reading it took a `canonicalise()` fold in front of every
predicate, and un-writing it took a rename in `ReconcileNameJob`, one cron tick later.

**And the rename could not be pulled forward.** The chosen name arrives as the COPY's
`Destination` header and Sabre fires `beforeMethod:COPY` while it is still a string,
so a plugin rewriting it really does make the file be born correctly named. It shipped
in the grafana sibling, and the Files app answered *"The file does not exist anymore"*:

```js
await client.copyFile(source, destination)
if (node.dirname === target.path) {                 // copying into the SAME folder
    const { data } = await client.stat(destination) // the path IT chose
    emit('files:node:created', ...)
}
...
if (404 === e.response?.status) throw new Error('The file does not exist anymore')
```

The client stats the destination it picked, and **only when the copy landed in the
folder it came from** — precisely and only the case that collides, so the plugin fired
exactly when the stat would notice. Measured both ways on a live instance:

    intercepting   COPY 201 → STAT 404 → error dialog, no file until a refresh
    deferring      COPY 201 → STAT 207 → correct name one tick later

Neither branch of that trade-off exists now. Nothing intercepts the copy and nothing
renames it afterwards, because the client's name is already right.

#### The same one segment is what gives the file its icon

Nextcloud's detector reads only the last extension (`Detection::detectPath()`,
`strrchr`). `detectPath('Fleet Health.n8n.json')` answered `application/json` and
always did — the custom type came from `updateFilecache('n8n.json')`, a table-wide
`LIKE '%.n8n.json'` UPDATE. Measured on the live instance: a sequential scan of 20,144
filecache rows, ~26ms, on **every write**, from `NodeWrittenListener`, the pull, the
create, and an entire listener (`MimeRestampListener`) that existed for nothing else.
`detectPath('Fleet Health.n8n')` answers `application/n8n+json`, so `RegisterMimetype`
is the whole story and all four call sites are gone.

penpot was never affected: its extension is a single segment, so Nextcloud's counter
lands harmlessly before it and its detection has always been native. This app and the
grafana sibling had both problems; grafana made this cut first.

### A copy made in n8n is named by n8n

The mirror image, and the one exception to *a name is one value living in three
places*.

n8n permits two workflows to share a name — verified on a live instance holding two
called `Emby Items` with different ids — and Nextcloud cannot permit two files in a
folder to share a name. Both constraints are real, and only one of them is
Nextcloud's business. So the counter goes on the FILENAME and stops there: the JSON
`name` and the n8n workflow keep saying the duplicated name, because that is what
their owner called them.

    Fleet Health.n8n      name "Fleet Health"   n8n: Fleet Health
    Fleet Health (1).n8n  name "Fleet Health"   n8n: Fleet Health

Same filename shape as a Nextcloud-side copy, deliberately: Nextcloud's constraint
is satisfied identically either way. What differs is whether the counter travels.
Outward it must — it is the real name. Inward it must not — renaming someone's
workflow because our filesystem is fussy is overreach.

**Which one takes the suffix is knowable, not arbitrary.** The app holds the prior
state: the file already carrying that name had it first, so the arriving workflow is
the one that gets suffixed. Renaming the incumbent instead would move a file the user
never touched.

`NameSyncListener` splits on the same line: a RENAME is the user typing the whole
filename, so the counter travels to the JSON name and to n8n; a WRITE is the user
setting a name, so the counter is stripped before comparing and saving a duplicate's
mirror enqueues nothing.

### The second suffix, and the pull that used to fight it

Three workflows, not two, because **two is the case that passes by accident**: a
counter that only ever reaches `(1)` is indistinguishable from an app that appends a
fixed string, and the second suffix is where an off-by-one would live.

**The word "still" in the last line is doing real work.** Landing three names is the
easy half; KEEPING them is where `SyncService` was wrong. For an existing mirror it
asked for `FilenameCodec::format($displayName, $id, false, 0)` — collision index
zero, unconditionally — so on every single tick both duplicates were told to go and
take the name the first mirror was sitting on.

It "worked" because the move threw and the catch logged `rename skipped
(collision?)`. An exception is not a naming policy, and that log line's own question
mark says nobody was sure it was one. Anything that made the move succeed — the
incumbent deleted in n8n, a differently-ordered pull — and the mirrors would start
swapping names underneath the user.

`desiredMirrorName()` replaces it: prefer the plain name, else the first free
counter, and count the file's OWN current name as free. That last clause is what lets
a legitimate duplicate keep the suffix it has, and it also means a mirror whose twin
was deleted gets its unsuffixed name back instead of wearing a counter forever.

**The second sync that proves it does not get a line of its own.** It is a mechanism
— how the claim is checked, not what the user ends up with — so it lives inside the
`are still named` step, which reads the folder, syncs once more, and checks that
neither the names nor the filenames moved. A scenario saying "and sync again" out
loud would be narrating the app's plumbing; `still` already means the state held.

**And neither arrange could have caught any of this.** `I copy the file into` invented
a fresh random destination, so the suite copied a workflow into the folder it was
already in and never once collided. There was no n8n-side duplicate scenario at all.
Both are fixed here.

### A copy landing outside every mapping is a plain document

WHAT NEXTCLOUD WOULD DO decides the body: it copies BYTES. It does not read them,
edit them, or strip anything out of them. So the app's whole contribution to a
copy landing outside every mapping is what it takes OFF — the identity in the DAV
metadata, which stopped being true the instant the copy existed.

BUT A COPY BREAKS AN INVARIANT WE ALREADY PROMISED, and that is the interesting
part. `pills ⇄ body, always, for any .n8n` (saga §5.10) is a rule this app
made about every workflow file, mapped or not, because that pair needs no remote
system. A copy is the one moment the two provably diverge: Nextcloud copies the
bytes (so the `tags` array comes along) and does NOT copy system tags (so the
copy has no pills). Doing nothing would leave every copy breaking our own rule
the instant it existed.

THE BODY WINS, WHICH IS THE SAME DIRECTION AS ADOPTION. The copy path derives the
pills from the body it inherited. The alternative — strip the body's tags to match
the empty pills — was rejected for a concrete reason: it would destroy the seed a
copy landing IN a mapping is about to need, since adoption reads tags from the
body and the body is the only surface that survives being copied at all. Copy and
adoption are now two uses of one rule rather than two special cases.

That also answers the "breadcrumb" question this scenario used to ask directly. A
copy out of a mapped folder keeps whatever its body held, mapping tag included,
and now wears it as a pill too — not because copy has a special rule about
breadcrumbs, but because it follows the one rule that was already written down.

THE OTHER END CANNOT BE CLOSED THE OBVIOUS WAY, and this is worth writing down
because it looks so obvious. On create, n8n's schema forbids `tags`, so they go up
in a SECOND call — and that call knows exactly what the workflow ends up carrying,
so writing them straight into the file seems like two lines. It is not possible
from there: `createForFile` runs INSIDE the handler for the very write that
created the file, so `putContent()` on the same node hits Nextcloud's lock and the
whole create fails. Tried; it took out every arrange in the suite that lands a
file in a mapped folder, and the failure surfaces as "create-on-land did not run"
rather than as anything about locking.

So a freshly created file's body has no `tags` until the first pull rewrites it
from n8n's canonical row. `pills ⇄ body` still holds in the meantime — both are
empty — which is why the copy path only has to make them agree, not invent
content.

### Adoption takes the tags from the body alone

A file becoming managed has NO baseline and no remote counterpart yet, so there
is nothing to merge against — the three-way merge does not apply at adoption, and
the body is the only surface that knows what the file arrived carrying. Pills are
bound to a file id and are lost on a copy; metadata is stripped by a round trip
through anything that is not Nextcloud. The `tags` array is bytes in the file.

A tag n8n has never seen has no id, so the body arrives with bare `{"name": …}`
rows and n8n mints the ids at adoption; the first sync after that writes the
canonical `{id,name}` rows back. The file being briefly "incomplete" is correct.

THE MAPPING TAG JOINS what arrived rather than replacing it — the workflow is
born with the tags the file brought PLUS the tag that binds it to its folder.

### THE STANDARD THIS FILE SETS

`workflows/create.feature` is the reference for two rules the rest of the suite
is being brought up to.

SPELL THE OBJECTS OUT. A mapping is a thing with a tag, a folder, a mode, a
storage kind and groups — so a scenario that needs one says so in a table, in the
Background, once. `Given a folder mapped as "sync" to the n8n tag "x"` named two
of five fields and derived the folder from the tag by a convention only the step
knew, which is why several scenarios could not say which folder they meant.

Three mappings sit in the Background here rather than one, and that is deliberate
rather than thorough: a `sync` mapping in an admin folder, a `link` mapping, and a
`sync` mapping in a Team Folder. Together they let one outline cover both modes
and both storage kinds without arranging anything per-scenario.

SAY WHERE THE THING IS IN BOTH SYSTEMS. A trashed file has a state in Nextcloud
AND a state in n8n, and a scenario naming only one is hiding half its setup:

    And the file is in the Nextcloud trash
    And the workflow is in n8n's archive

One line per place, each staging its own side. The payoff is that the three trash
files read as a MATRIX — every scenario says where the file is and where the
workflow is, so which combination is under test is visible without inferring it
from the title. `the file is in the trash` left the n8n half to be guessed, and
guessing is how "purging an unmapped file deletes its workflow" got written down.

A `Given` STATES WHAT IS TRUE; IT DOES NOT PERFORM. Past tense is not a
loophole — `I have moved it to the trash` and `I have changed the tags to …` are
actions wearing a disguise, and a scenario with two gestures in it cannot say
which one it is about. The fix is to name the STATE: `the file is in the trash`,
`an unmapped workflow file that still carries its "n8n_id"`. Reaching that state
may well require a gesture
inside the step, and that is the step's business; what the scenario claims is
that the state holds before the action under test.

AND EVERY SCENARIO IS SOMEBODY'S GESTURE. Three scenarios here described tags
arriving with a file, and every one of them was a move or a copy wearing a create
scenario's name — see `The tags a file arrives with are the tags its workflow ends
up with` above. Tags and metadata are PRE AND POST STATE; they are never the
action, and a file's state changing is not a licence to file the scenario under
whichever verb the app happened to run internally.

AND THEN INFER FROM THEM. Because the Background says what each folder IS, a
scenario says only where it put the file — the expected mode, mapping id and
mapping tag all follow. An `Examples` column restating "Pointers is link" is a
second copy that can disagree with the mapping it claims to describe.

METADATA IS PRE/POST STATE, NOT A MENTION. The old assertions were `the file is
stamped with the workflow's "n8n_id"` and `the file carries its n8n metadata` —
the first named one key and checked it non-empty, the second checked nothing a
reader could name. Both pass on a file whose mode is wrong, whose mapping is
another mapping's, or whose hash was never stamped, which is most of what can
actually go wrong. `the file holds:` states the whole managed surface in one
table, and its negative twin names the whole set too: a file outside every
mapping is not ours in ANY respect, which is a stronger claim than "it has no
n8n_id".

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

TRASHING ONLY. The trash is a three-gesture lifecycle and it used to live in one
file: trashing, emptying the trash, and restoring were all "delete", which made
the file thirteen scenarios long and hid that each gesture has its own mirror
image. They are three files now — `delete`, `purge`, `restore` — and reading them
side by side is how you see that a restore undoes a trashing exactly, and that
only a purge is permanent.

### The trash is reversible, so trashing is too

Nothing a trashing does to n8n may be irreversible, because the user has not said
anything irreversible yet. For a `sync` file that means ARCHIVING the workflow —
hidden, preserved, and one call away from coming back.

### A link cannot be deleted from Nextcloud

A LINK IS A READ-ONLY PROJECTION, AND THAT INCLUDES ITS EXISTENCE. The file is a
pointer to a workflow that lives in n8n; n8n decides whether the workflow exists,
so n8n decides whether the pointer does. Deleting the pointer locally is the same
category of gesture as moving one out of its mapping, which is already refused.

THE WAY TO GET RID OF A LINK IS TO ARCHIVE THE WORKFLOW IN n8n. The next sync
finds it gone from the mapping and prunes the file — no trash step in between,
because there is nothing to restore FROM: a trashed link would be a pointer to a
workflow that is still perfectly fine, sitting in a bin. And if the workflow comes
back in n8n, the file is pulled in again like any other link. The round trip works
without Nextcloud remembering anything.

`@unbuilt` — the app currently UNTAGS the workflow on a link delete, which is the
old design: it treated removing the file as removing the membership. That made
the delete a mapping edit in disguise, and left the user with a workflow silently
dropped out of its mapping because they tidied a folder.

IT ALSO COLLAPSES TWO SCENARIOS ELSEWHERE. A link that cannot be trashed cannot
be purged from the trash or restored from it either, so `purge.feature` and
`restore.feature` lost their link rows. One refusal replaces three descriptions of
what a link does in a bin it never reaches.

### workflows/delete — WHAT WAS RETIRED

`Trashing a file that already left its mapping reaches nothing` had a second
gesture hiding in its Given: the file was moved out and THEN trashed, so the
scenario performed two actions and asserted about the first one's side effects.
Stripped back it says "an unmapped file goes to the Nextcloud trash", which is
Nextcloud doing its job — the workflow was archived by the MOVE, and the trashing
reaches nothing because there is nothing left for it to reach.

`Deleting an untracked workflow file touches nothing in n8n` was the same claim
about a file the app never knew.

Neither is a behaviour. Both were the absence of one.

### A link leaves when its workflow does

THE PRUNE IS THE DELETE, and it is the only one a link has. Archiving the
workflow in n8n should take it out of the mapping, so the next sync finds the
mirror orphaned and removes it — not to the trash, GONE.

`@unbuilt`, AND THE REASON IS ONE LINE OF THE PULL. It lists workflows BY TAG
(`eachWorkflow(['nextcloud:links'])`) and archiving does not remove a tag, so an
archived workflow still comes back in the listing and its mirror is never
orphaned. Making this true means the pull has to treat "archived in n8n" as "not
in the mapping" — a second predicate beside the tag, not a bigger change than
that, but a real one. That is the counterpart of
`A link cannot be deleted from Nextcloud`: n8n owns whether the pointer exists,
so n8n is where you say it should stop.

NOT IN THE TRASH IS THE LOAD-BEARING HALF. A trashed link would be a pointer to a
workflow that is still perfectly fine, sitting in a bin — and worse, it would
imply a restore gesture that cannot exist. There is nothing to restore FROM: the
file held no content, and the workflow it pointed at is n8n's business. Bringing
the workflow back in n8n is what brings the file back, and that is a PULL, not a
restore (see `A link comes back when its workflow does`, in `restore.feature`).

### A link comes back when its workflow does

The n8n-side round trip, and it needs nothing from Nextcloud: unarchive the
workflow and the next sync mirrors it in again as an ordinary link, with fresh
metadata. Nextcloud remembered nothing in between, which is exactly why the
delete side must not put the file in the trash.

IT LIVES IN `restore.feature` BECAUSE IT IS A RESTORE, even though nobody
restored anything in Nextcloud. The gesture is n8n-origin and the payoff is what
comes back into the mapped folder.

### A trash is aborted if n8n is unreachable

The delete listener throws `AbortedEventException` when the soft-delete fails, so
Nextcloud keeps the file. That is the right way round: a file still in Nextcloud
with a live workflow is consistent, a file gone from Nextcloud with a live
workflow is a leak nobody will notice.

`@blocked` because this harness has no way to make n8n unreachable mid-request.

### Restoring a file whose workflow is already live again

Someone unarchived the workflow in n8n while the file sat in the trash. The
restore should find its work already done rather than treat it as a conflict —
the end state it wanted is the end state that exists.

### Unarchiving a workflow in n8n brings its file back out of the trash

The n8n-origin twin of a restore. The sync is how the news arrives, not the
behaviour, so it is folded into the gesture.

### An unmapped file is just a file

UNMAPPED MEANS n8n IS NOT INVOLVED. Not "involved a bit less", not "involved for
destructive operations only" — a file that has left its mapping is an ordinary
Nextcloud document that happens to remember an id, and every gesture on it is
Nextcloud's alone.

I HAD THIS BACKWARDS AND WROTE IT DOWN AS A LEAK. The scenario said purging an
unmapped file should permanently delete its archived workflow, on the reasoning
that the workflow was still "the one this file was the source of truth for" and
the user had said the irreversible thing. That is exactly the argument that makes
it dangerous: the file is outside every mapping precisely BECAUSE the user took
it out, and emptying a trash in Nextcloud is not consent to destroy something in
another system that Nextcloud no longer claims to mirror.

`DeleteService::hardDelete` already gets this right — it returns early for any
mode that is not `sync`, so the archived workflow survives. The scenario is LIVE
rather than `@unbuilt`, and it pins the behaviour so nobody "fixes" the early
return into a leak.

THE WAY BACK IS THE MOVE, and it is the only way: moving the file into a mapped
folder unarchives its workflow and re-adopts it (`move.feature`). One gesture
restores the relationship, and nothing else pretends to.

### A workflow deleted in n8n leaves the trashed file alone

A PURGE IN n8n DOES NOT PURGE THE NEXTCLOUD TRASH, and the reason is the one case
that matters: once n8n has destroyed the workflow, the file in the Nextcloud trash
is the LAST COPY OF IT IN EXISTENCE. Reaching in to delete that, on a schedule,
unprompted, is the single most destructive thing this app could do.

"A purge is a purge" is the tempting symmetry and it is wrong here. The penpot
sibling settled this first and states it as a rule about the reconciler's field
of view: it walks the mapped folder's directory listing, so a mirror already in
the trash is not merely spared — it is NOT SEEN AT ALL. A whole class of question
("both trashes hold it and then the remote purges — now what?") stops existing,
because nothing was looking.

THE PRICE IS NAMED THERE TOO: a workflow that comes back while its old mirror
sits in the trash gets a NEW file beside the trashed one, because the pull cannot
re-adopt what it cannot see. That is the trade — a duplicate you can delete,
versus a deletion you cannot undo.

### Moving a duplicate in mints a brand-new workflow


## workflows/restore

`features/workflows/restore.feature`

### A restore is the trashing, undone

Every scenario here is the mirror of one in `delete.feature`, and that is the
point of the file existing: a restore that does not exactly undo its trashing is
the bug this whole lifecycle exists to prevent.

### Restoring a file that left its mapping reaches nothing

Its workflow was archived by the MOVE OUT, not by the trashing — so bringing the
file back out of the trash restores a file, not a mapping membership. Moving it
back into a mapped folder is the gesture that revives the workflow
(`move.feature`), and keeping the two separate is what stops a restore from
silently re-adopting something.

### The world may have moved while the file sat in the trash

A file can sit in the trash for weeks, and n8n does not wait. Three things can
have happened by the time it comes back: the workflow was deleted, it was
restored by someone else, or nothing. The first is a KNOWN GAP — see
`DeleteService::restore`, which treats a 404 as success, so the file returns
carrying a dead id with nothing created. `MotionService::moveIn` already handles
the identical situation correctly, which is why the scenario is `@unbuilt` rather
than speculative: the fix is a known shape.

## workflows/purge

`features/workflows/purge.feature`

### Only what Nextcloud owned is Nextcloud's to destroy

Emptying the trash is the one gesture that reaches n8n irreversibly, and only for
a `sync` file — the one whose workflow Nextcloud was the source of truth for. A
link's workflow is not ours to delete however emphatically the file is removed.

THIS FILE USED TO BE THE ADMIN'S "PURGE NEXTCLOUD FILES" BUTTON, which is gone —
see below. `purge` now means what a user means by it: emptying the trash.

### workflows/purge — THE ADMIN PURGE BUTTON WAS REMOVED

There was an admin action that deleted every managed file this app had created,
across every mapping, in one click. It is gone: the endpoint, the `occ` command,
the service method, the settings button and its scenarios.

WHY. It was too destructive for what it bought. The pitch was "reset the
Nextcloud side, n8n is never touched, get it all back with Sync from n8n" — but
that is only true if every file was a faithful mirror, and the ones that are not
are exactly the ones a user would miss. Anything edited and not yet pushed was
gone. It also had to grow a list of exceptions to be safe at all (keep unmapped
files, keep untracked files, keep anything a pull could not restore), which is
the shape of a feature arguing with itself.

Removing a mapping still cleans up its own files
({@see SyncService::purgeManagedFiles}) — scoped, predictable, and attached to a
gesture that already means "I do not want this mirrored".

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

An edit made on either side reaching the other, and the metadata that proves it
landed.

### A local edit reaches its workflow in n8n

`When the admin pushes to n8n` used to be the action, which is the mistake this
whole pass exists to remove: nobody edits a workflow in order to run a push. The
gesture is editing the file and saving it, and the push is how the change travels.

THE TWO STAMPS ARE THE POINT OF THE METADATA TABLE HERE. `n8n_versionId` and
`n8n_syncedHash` are the app's memory of what the two sides last agreed on, and an
edit is exactly when they must move:

  · a versionId that lags means the next pull thinks n8n is ahead and overwrites
    the edit that just landed;
  · a hash that lags means the next save is read as a fresh edit and pushed again,
    which is the writeback loop the guard exists to prevent.

Neither shows up in a "the edit reached n8n" assertion, which is why they are
stated as post-state rather than trusted.

### An edit made in n8n reaches the mirror

THE OTHER HALF, and the one that was missing entirely. `edit.feature` only ever
described Nextcloud-side edits, so the direction that runs on a schedule — the
one a user is most likely to be surprised by — had no scenario at all.

THE MIRROR WEARS THE WORKFLOW'S CLOCK, and `Modified` is a ROW IN THE METADATA
TABLE rather than a line beside it. It is state the file carries, read in the same
glance as the rest — stating it separately said the same thing twice, in two
shapes. That a mirrored folder whose files all read "modified a few seconds ago"
after every scheduled run is a folder where a real edit is invisible is the reason
it is asserted at all; the clock is a fact about the workflow, not about when our
plumbing ran.

### A sync holds the workflow, a link holds a pointer

The one thing the two modes genuinely differ on, so it is two scenarios rather
than an outline: a sync file's body IS the workflow and must carry the edit; a
link's body is a pointer.

AND THE LINK SAYS WHAT IT HOLDS. `the file does not hold what the workflow holds`
was a negative that never named what IS there, which is a specific documented
shape — an `n8n.reference/v1` pointer with the id, the name and a deep link — so
the scenario states that shape in a table. A negative assertion passes on an empty
file, a truncated file, and a file full of something else entirely.

EVERYTHING ELSE IS THE SAME, INCLUDING THE METADATA — the id, the mapping, the
mode, the version and the hash all move for a link exactly as they do for a sync,
because a link is mirrored just as attentively; it simply mirrors less. Both
scenarios state the full table for that reason. An outline over the mode would
have hidden the difference that matters while pretending the rest was the
question.

### A file outside every mapping is never pushed

An edit to a file the app does not manage reaches nothing, because no listener is
watching it. Stated as the workflow's state rather than "n8n is not contacted":
the observable that matters is n8n being unchanged, and a request-counting
assertion would pass just as happily on a no-op write.

### workflows/edit — WHAT WAS RETIRED

`A workflow nobody edited leaves its mirror untouched` was not a behaviour.
"Nobody edits" is the absence of a gesture, and a scenario cannot be about
something not happening — the run it wrapped is `connection/sync-now.feature`'s
subject, where a sync that finds nothing to do is what a RUN does rather than
what a person did.

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

### A name is one value living in three places

The filename stem, the JSON `name`, and the workflow's name in n8n are three
copies of one value, and the whole feature is about them agreeing. So the payoff
is ONE assertion naming all three, not three assertions naming one each: split
up, a scenario can check two and look complete while the third has drifted, which
is the only failure mode that matters here.

`Renaming never breaks the link` was a fourth version of the same thing — it
renamed a file and asserted the id survived, which is what `the file holds this
DAV metadata` says on every scenario now, as post-state rather than as a scenario
of its own.

THE TWO NEXTCLOUD GESTURES ARE TWO SCENARIOS, not two rows. Renaming the file and
editing the name inside it look like one rule over an input, and they are not:
one is a MOVE event and the other is a WRITE event, they enter the app through
different listeners, and either can break while the other works. An `Examples`
column would hide exactly the asymmetry worth testing.

### A rename made in n8n reaches the mirrored file

NO PULL IN THE `When`, and that is the rule this file kept breaking. `And the
"nextcloud:alpha" mapping is pulled` appeared in four scenarios: the behaviour is
that someone renamed a workflow, and the sync is merely how the news arrives.
Folded into the gesture, as everywhere else.

A LINK RENAMES THE SAME WAY, and it is a row rather than a scenario because
nothing about the outcome differs. A link's BODY is a pointer rather than the
workflow — that is the mode's whole meaning — but its NAME is how a human finds
it in the Files app, and n8n is the only writer of a link's anything. The
Nextcloud-side rename gestures above are `sync`-only for the opposite reason: a
link has no writeback channel to carry them.

### A workflow always has a name

TWO HALVES OF ONE RULE, from opposite ends.

Nextcloud will not let a file be named blank, and n8n requires a name on a
workflow — so a rename to whitespace is refused locally, where the user can see
it, rather than sent to n8n to 400. We follow Nextcloud's rule; there is nothing
to invent.

A workflow that arrives from n8n with an empty name is the other end, and the
only place a nameless thing can enter: `SyncService` falls back to the workflow
ID for the filename. Honest and reversible — inventing "Untitled" would collide
the moment a second nameless workflow appeared.

### rename.feature — WHAT WAS RETIRED, AND WHY

`A failed propagation never reverts the local rename` described a compensating
transaction the app does not have. Nothing rolls a rename back, and nothing
retries it on a schedule, so the scenario specified an imagined design rather
than this one.

`Renaming an untracked ".n8n" file is not a failure` asserted that an app
which is not involved did not get involved. The file is outside every mapping, so
no listener sees it; the scenario tests Nextcloud.

`A renamed workflow keeps its place in a subfolder` asserted something the code
cannot do otherwise. The pull renames with
`$existing->move($existing->getParent()->getPath() . '/' . $desired)` — the parent
is read off the file itself, so "in its own folder" is not a decision the pull
makes, it is the only expression there is. A file could only move during a rename
if someone wrote code to move it, and then the scenario guarding against it would
be new code's test, not this one's.

The comment it carried is worth keeping as a note to whoever touches that line:
yanking a file back to the mapping root because its name changed would undo a
deliberate user gesture, and a mapping owns its whole subtree anyway.

## workflows/ignore — RETIRED (the feature is gone, not just its file)

`n8n:ignore` let you park a workflow file inside a mapped folder that no longer
owned it: the file stayed put, the workflow was ARCHIVED in n8n, and every
subsequent sync stepped around it. It was removed whole — the feature file, the
`ReservedTagResolver`, the `ModeTagListener`, the entire `ModeChangeService`, the
`ignored` file mode, and the skip branches it needed in the pull, the push and
the purge.

WHY IT WENT. It contradicted the app's own premise. This app says a file in a
mapped folder IS the workflow, on both sides; `ignored` invented a third state
where the file sat in the mapping while belonging to neither system properly —
present in Nextcloud, archived in n8n, skipped by every sync. It was a mode that
existed to be excluded from the thing the mode was for.

It also cost more than it looked. `ignored` was a value every mode check had to
know about, so it leaked into the pull index, the prune, the push filter, the
purge predicate, the DAV write guard and the Files-app openers — six places
reasoning about a state that only existed to opt out.

AND THERE WERE ALREADY TWO WAYS OUT, both better:

  · MOVE THE FILE OUT of the mapped folder. It becomes `unmapped`, keeps its
    full JSON, and lives on as an ordinary Nextcloud document. That is
    `workflows/move.feature`, it is built, and it is what someone means by "keep
    this in Nextcloud only".
  · DROP THE MAPPING TAG, which is new here and is the replacement — see the
    tags section below. The file leaves Nextcloud and the workflow stays in n8n
    minus that one tag.

The two are opposites and that is the point: one keeps the Nextcloud copy, the
other keeps the n8n copy. `ignored` kept both and synced neither.

WHAT THE REMOVAL DELETED, so a reader does not go looking for it: the reserved
`n8n:ignore` tag, `WorkflowMetadata::MODE_IGNORED`, `ManagedFile::isIgnored()`,
`$ignoredIds` in the pull, the purge's "keeps an ignored file" scenario, and the
`ignored` rows in `open-with.feature`. The `n8n:sync` / `n8n:link` /
`n8n:unmapped` MODE PILLS went the same way one PR later — see below.

## the n8n: namespace — RETIRED ENTIRELY

The app wrote three pills on every managed file, one per mode: `n8n:sync`,
`n8n:link`, `n8n:unmapped`. They are gone, and with them the whole idea of a
reserved namespace.

WHY. Since the per-file sync↔link toggle was removed, the mapping decides a
file's mode and the file's own `n8n_mode` metadata records it — which is what
every code path actually reads. The pill was a second copy of that truth, kept in
lockstep by the app, editable by nobody, and load-bearing for nothing.

AND IT WAS THE EXPENSIVE COPY. Because the pills sat on the same files as the
user's real tags, every tag path had to carve them back out: `contentTags()`,
`readNcContentTags()`, the merge inputs, the baseline stamp, the body writeback,
and a listener gate that asked "did this change touch a real tag?". That
exclusion WAS most of the fiddly part of tag sync, and it existed only because we
put the pills there. Removing them deleted `RESERVED_PREFIX`, `isReserved()`,
`contentTags()` and `touchesContentTag()` outright: a tag is now just a string on
both sides.

THE UPGRADE IS A DELETION, NOT A FILTER, and this is the part worth reading. A
leftover `n8n:sync` pill would become an ordinary content tag on the next
reconcile and be PUSHED TO n8n — so simply dropping the filter would seed every
mirrored workflow with a tag nobody chose. `Migration\RemoveModePills` deletes
the four retired DEFINITIONS once on upgrade, which removes them from every file
at once and leaves the namespace genuinely empty.

Deleting a tag definition is something this app otherwise refuses to do — a
catalog is shared and a definition may be pinned on files it knows nothing about.
These four are the exception because the app MINTED them and no human chose them.
They are our litter, not someone's label.

WHAT A USER LOSES: the coloured pill that said "this file is synced". The mode is
still on the file as DAV metadata (`view.feature`), the Files-app openers still
branch on it, and the folder a file sits in already says whether it is mapped.
The siblings — `nextcloud-grafana`, `nextcloud-penpot` — are getting the same
treatment; penpot's folder-level `penpot` tag is a different thing (a marker AND
the user's opt-in) and stays.

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
Nextcloud system tags hold the same strings. No exclusions — there used to be a
reserved `n8n:*` namespace for the app's own mode pills, and both the pills and
the namespace are gone (see `the n8n: namespace — RETIRED ENTIRELY` above).

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

So a `.n8n` that leaves Nextcloud and comes back carries its tags in exactly
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

THE THREE SURFACES GET THREE SENTENCES. The payoff was one step asserting all
of them at once, which read as a single sentence containing an "and" — and it
was three checks wearing one name. A settled tag change means the tags are on
the Nextcloud pills, in the file, and in n8n, so a scenario says exactly that,
in three lines, and a failure names the surface that drifted in its own right.

THE IDS ARE NOT ASSERTED ON THE FILE, AND CI IS WHY. Asserting canonical
`{id,name}` rows as the mark of a settled change read well and failed every row
of the file-edit outline: a hand-edited file KEEPS the bare `{"name": …}` rows
the person typed, and only a later sync from n8n rewrites them. That is the
documented design below — the file is briefly "incomplete" in a way that
self-corrects — so the assertion was wrong, not the app. The id shape is
asserted where it is the point instead: on an unmapped file, which has no n8n to
mint one.

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

### Changing the tags on an unmapped file updates the body tags too

── RULE: THE NEXTCLOUD PAIR IS LOCAL; ONLY THE n8n LEG NEEDS A MAPPING ─────

A `.n8n` has pills and a `tags` array whether or not it lives in a mapped
folder. Keeping THOSE TWO in step is a Nextcloud-local concern — there is no
remote system involved — so it happens for every workflow file, mapped or not.
Only the third participant, n8n, requires a mapping.

    pills  ⇄  body        always, for any .n8n file
    pills/body  →  n8n    only for a managed `sync` file
    n8n  →  pills/body    only for a mapped folder, on a sync

A TAG n8n HAS NEVER SEEN HAS NO ID, and the body records it honestly as
`{"name": "urgent"}` with no other keys — which is the positive claim this
scenario makes, and the reason it is not merely "nothing reached n8n". Ids are
minted by n8n at adoption, and the first sync after that rewrites the array with
canonical `{id,name}` rows. The file being briefly "incomplete" is correct
rather than a defect, and it is the one place in this feature where a settled
file legitimately holds a row with no id.

THIS IS WHAT MAKES THE TRANSPORT CASE WORK END TO END. Tags applied while a
file sits outside every mapping are recorded in the body, which is the only
surface that survives being moved, copied, or carried out of Nextcloud — so
when the file is later dropped into a mapped folder, the tags are still there
to seed n8n. That adoption is `workflows/create.feature`'s to own, and it is
specced there; this file only pins the local pair it depends on.

### The mapping tag is the membership, so dropping it leaves

n8n maps a folder BY TAG, so the tag that binds a workflow to its folder is
itself a content tag. It is shown as an ordinary pill, and until now removing it
was REFUSED: a reconcile force-kept it on both sides so no Nextcloud gesture
could ever unbind a workflow.

That refusal is gone, and the gesture is honoured instead:

  1. the tag is removed from the workflow in n8n — the ONLY change made there.
     Every other tag stays, and the workflow is not archived, not deleted;
  2. the mirror is removed from Nextcloud.

NOTHING IS LOST, which is what makes it safe: the workflow is still in n8n
exactly as it was minus one tag, so this is an UNSYNC rather than a delete. The
file is not trashed either — trashing a managed file MEANS something here (it
archives the workflow), and routing an unsync through the trash would fire that.

WHY THE REFUSAL WAS WRONG. It was protecting the user from a gesture they meant.
Removing the tag that puts a workflow in a mapping is the clearest possible way
to say "take it out of the mapping", and the app answered by silently putting the
tag back. The escape hatch it offered instead — hand-apply `n8n:ignore` — was a
whole reserved-tag feature built to work around a refusal, and it has been
removed with it (see `workflows/ignore` above).

IF n8n CANNOT BE TOLD, THE MIRROR IS KEPT. Deleting the file while the workflow
still carries the mapping tag would strand it: the next pull would mirror it
straight back and the user would watch their own gesture undo itself. So the
unbind is all-or-nothing, and a failure leaves everything as it was for the next
sync to retry.

AND THE MERGE MUST NOT RUN AFTERWARDS EITHER, which is subtler and was a real bug
in the first cut of this. Once the mapping tag is missing from the Nextcloud side,
falling through to the ordinary tag merge hands it a set with that tag absent —
and the merge reads that as an ordinary removal and pushes it. The workflow would
leave the mapping anyway, with the mirror still sitting in the folder. A
half-unbind is worse than either outcome, so the gesture is answered exactly once,
whether it succeeded or not.

THE n8n SIDE IS THE SAME GESTURE FROM THE OTHER END. Removing the mapping tag
from the workflow in n8n also ends the mirror — the file is pruned by the pull.
Both directions now say the same thing, which they did not before.

A LINK NEVER REACHES THIS. Its pills are a read-only projection of n8n that the
next pull overwrites, so the reconcile returns before the unbind is considered.

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

THE UNBIND MAKES THIS SHARPER, and it is worth writing down before anyone builds
the fan-out: on the "flows" mirror the OTHER mapping's tag ("reports") is an
ordinary content pill, and dropping a mapping tag now UNBINDS. So a user tidying
tags on one mirror can take the workflow out of a mapping they were not looking
at. Whether the unbind should be scoped to THIS mirror's mapping tag only — or
fan out honestly to both — is the open question, and it is why the unbind reads
the file's own mapping rather than any tag that happens to map something.

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
  · Ejecting via n8n:ignore keeps the file instead of pruning it — and then the
    whole `n8n:ignore` feature went, see `workflows/ignore` above
  · Removing the mapping pill as a deliberate eject is paired with n8n:ignore —
    it IS the eject now, and it needs no reserved tag to say so

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
    core/js/mimetypelist.js) and re-stamps the .n8n filecache rows back to
    application/json. The store's clean-uninstall rule is about this shared state.
  - DATA: the app ORPHANS the user's data — it never deletes the .n8n files,
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


ONE OF THEM IS A TEAM FOLDER, DELIBERATELY. Moving between an admin folder and a
Team Folder is a move across STORAGES, which is the case most likely to break and
the one no other file exercises — groupfolders is installed on every CI leg for
exactly this reason.

A 412 HERE IS NOT A STORAGE PROBLEM, and it cost a cycle to learn: the arrange
used a fixed filename, so the second scenario to move a file into a given folder
hit the first one's leftover and Nextcloud refused the overwrite. The name is
unique per scenario now. Worth remembering, because the failure reads as a
permissions or mount problem and is neither.
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
`.n8n` is a first-class type that we get custom openers) but a distinct
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

## workflows/ignore — RETIRED (the feature is gone, not just its file)

`n8n:ignore` let you park a workflow file inside a mapped folder that no longer
owned it: the file stayed put, the workflow was ARCHIVED in n8n, and every
subsequent sync stepped around it. It was removed whole — the feature file, the
`ReservedTagResolver`, the `ModeTagListener`, the entire `ModeChangeService`, the
`ignored` file mode, and the skip branches it needed in the pull, the push and
the purge.

WHY IT WENT. It contradicted the app's own premise. This app says a file in a
mapped folder IS the workflow, on both sides; `ignored` invented a third state
where the file sat in the mapping while belonging to neither system properly —
present in Nextcloud, archived in n8n, skipped by every sync. It was a mode that
existed to be excluded from the thing the mode was for.

It also cost more than it looked. `ignored` was a value every mode check had to
know about, so it leaked into the pull index, the prune, the push filter, the
purge predicate, the DAV write guard and the Files-app openers — six places
reasoning about a state that only existed to opt out.

AND THERE WERE ALREADY TWO WAYS OUT, both better:

  · MOVE THE FILE OUT of the mapped folder. It becomes `unmapped`, keeps its
    full JSON, and lives on as an ordinary Nextcloud document. That is
    `workflows/move.feature`, it is built, and it is what someone means by "keep
    this in Nextcloud only".
  · DROP THE MAPPING TAG, which is new here and is the replacement — see the
    tags section below. The file leaves Nextcloud and the workflow stays in n8n
    minus that one tag.

The two are opposites and that is the point: one keeps the Nextcloud copy, the
other keeps the n8n copy. `ignored` kept both and synced neither.

WHAT THE REMOVAL DELETED, so a reader does not go looking for it: the reserved
`n8n:ignore` tag, `WorkflowMetadata::MODE_IGNORED`, `ManagedFile::isIgnored()`,
`$ignoredIds` in the pull, the purge's "keeps an ignored file" scenario, and the
`ignored` rows in `open-with.feature`. The `n8n:sync` / `n8n:link` /
`n8n:unmapped` MODE PILLS went the same way one PR later — see below.

## the n8n: namespace — RETIRED ENTIRELY

The app wrote three pills on every managed file, one per mode: `n8n:sync`,
`n8n:link`, `n8n:unmapped`. They are gone, and with them the whole idea of a
reserved namespace.

WHY. Since the per-file sync↔link toggle was removed, the mapping decides a
file's mode and the file's own `n8n_mode` metadata records it — which is what
every code path actually reads. The pill was a second copy of that truth, kept in
lockstep by the app, editable by nobody, and load-bearing for nothing.

AND IT WAS THE EXPENSIVE COPY. Because the pills sat on the same files as the
user's real tags, every tag path had to carve them back out: `contentTags()`,
`readNcContentTags()`, the merge inputs, the baseline stamp, the body writeback,
and a listener gate that asked "did this change touch a real tag?". That
exclusion WAS most of the fiddly part of tag sync, and it existed only because we
put the pills there. Removing them deleted `RESERVED_PREFIX`, `isReserved()`,
`contentTags()` and `touchesContentTag()` outright: a tag is now just a string on
both sides.

THE UPGRADE IS A DELETION, NOT A FILTER, and this is the part worth reading. A
leftover `n8n:sync` pill would become an ordinary content tag on the next
reconcile and be PUSHED TO n8n — so simply dropping the filter would seed every
mirrored workflow with a tag nobody chose. `Migration\RemoveModePills` deletes
the four retired DEFINITIONS once on upgrade, which removes them from every file
at once and leaves the namespace genuinely empty.

Deleting a tag definition is something this app otherwise refuses to do — a
catalog is shared and a definition may be pinned on files it knows nothing about.
These four are the exception because the app MINTED them and no human chose them.
They are our litter, not someone's label.

WHAT A USER LOSES: the coloured pill that said "this file is synced". The mode is
still on the file as DAV metadata (`view.feature`), the Files-app openers still
branch on it, and the folder a file sits in already says whether it is mapped.
The siblings — `nextcloud-grafana`, `nextcloud-penpot` — are getting the same
treatment; penpot's folder-level `penpot` tag is a different thing (a marker AND
the user's opt-in) and stays.

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
Nextcloud system tags hold the same strings. No exclusions — there used to be a
reserved `n8n:*` namespace for the app's own mode pills, and both the pills and
the namespace are gone (see `the n8n: namespace — RETIRED ENTIRELY` above).

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

So a `.n8n` that leaves Nextcloud and comes back carries its tags in exactly
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

THE THREE SURFACES GET THREE SENTENCES. The payoff was one step asserting all
of them at once, which read as a single sentence containing an "and" — and it
was three checks wearing one name. A settled tag change means the tags are on
the Nextcloud pills, in the file, and in n8n, so a scenario says exactly that,
in three lines, and a failure names the surface that drifted in its own right.

THE IDS ARE NOT ASSERTED ON THE FILE, AND CI IS WHY. Asserting canonical
`{id,name}` rows as the mark of a settled change read well and failed every row
of the file-edit outline: a hand-edited file KEEPS the bare `{"name": …}` rows
the person typed, and only a later sync from n8n rewrites them. That is the
documented design below — the file is briefly "incomplete" in a way that
self-corrects — so the assertion was wrong, not the app. The id shape is
asserted where it is the point instead: on an unmapped file, which has no n8n to
mint one.

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

### Changing the tags on an unmapped file updates the body tags too

── RULE: THE NEXTCLOUD PAIR IS LOCAL; ONLY THE n8n LEG NEEDS A MAPPING ─────

A `.n8n` has pills and a `tags` array whether or not it lives in a mapped
folder. Keeping THOSE TWO in step is a Nextcloud-local concern — there is no
remote system involved — so it happens for every workflow file, mapped or not.
Only the third participant, n8n, requires a mapping.

    pills  ⇄  body        always, for any .n8n file
    pills/body  →  n8n    only for a managed `sync` file
    n8n  →  pills/body    only for a mapped folder, on a sync

A TAG n8n HAS NEVER SEEN HAS NO ID, and the body records it honestly as
`{"name": "urgent"}` with no other keys — which is the positive claim this
scenario makes, and the reason it is not merely "nothing reached n8n". Ids are
minted by n8n at adoption, and the first sync after that rewrites the array with
canonical `{id,name}` rows. The file being briefly "incomplete" is correct
rather than a defect, and it is the one place in this feature where a settled
file legitimately holds a row with no id.

THIS IS WHAT MAKES THE TRANSPORT CASE WORK END TO END. Tags applied while a
file sits outside every mapping are recorded in the body, which is the only
surface that survives being moved, copied, or carried out of Nextcloud — so
when the file is later dropped into a mapped folder, the tags are still there
to seed n8n. That adoption is `workflows/create.feature`'s to own, and it is
specced there; this file only pins the local pair it depends on.

### The mapping tag is the membership, so dropping it leaves

n8n maps a folder BY TAG, so the tag that binds a workflow to its folder is
itself a content tag. It is shown as an ordinary pill, and until now removing it
was REFUSED: a reconcile force-kept it on both sides so no Nextcloud gesture
could ever unbind a workflow.

That refusal is gone, and the gesture is honoured instead:

  1. the tag is removed from the workflow in n8n — the ONLY change made there.
     Every other tag stays, and the workflow is not archived, not deleted;
  2. the mirror is removed from Nextcloud.

NOTHING IS LOST, which is what makes it safe: the workflow is still in n8n
exactly as it was minus one tag, so this is an UNSYNC rather than a delete. The
file is not trashed either — trashing a managed file MEANS something here (it
archives the workflow), and routing an unsync through the trash would fire that.

WHY THE REFUSAL WAS WRONG. It was protecting the user from a gesture they meant.
Removing the tag that puts a workflow in a mapping is the clearest possible way
to say "take it out of the mapping", and the app answered by silently putting the
tag back. The escape hatch it offered instead — hand-apply `n8n:ignore` — was a
whole reserved-tag feature built to work around a refusal, and it has been
removed with it (see `workflows/ignore` above).

IF n8n CANNOT BE TOLD, THE MIRROR IS KEPT. Deleting the file while the workflow
still carries the mapping tag would strand it: the next pull would mirror it
straight back and the user would watch their own gesture undo itself. So the
unbind is all-or-nothing, and a failure leaves everything as it was for the next
sync to retry.

AND THE MERGE MUST NOT RUN AFTERWARDS EITHER, which is subtler and was a real bug
in the first cut of this. Once the mapping tag is missing from the Nextcloud side,
falling through to the ordinary tag merge hands it a set with that tag absent —
and the merge reads that as an ordinary removal and pushes it. The workflow would
leave the mapping anyway, with the mirror still sitting in the folder. A
half-unbind is worse than either outcome, so the gesture is answered exactly once,
whether it succeeded or not.

THE n8n SIDE IS THE SAME GESTURE FROM THE OTHER END. Removing the mapping tag
from the workflow in n8n also ends the mirror — the file is pruned by the pull.
Both directions now say the same thing, which they did not before.

A LINK NEVER REACHES THIS. Its pills are a read-only projection of n8n that the
next pull overwrites, so the reconcile returns before the unbind is considered.

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

THE UNBIND MAKES THIS SHARPER, and it is worth writing down before anyone builds
the fan-out: on the "flows" mirror the OTHER mapping's tag ("reports") is an
ordinary content pill, and dropping a mapping tag now UNBINDS. So a user tidying
tags on one mirror can take the workflow out of a mapping they were not looking
at. Whether the unbind should be scoped to THIS mirror's mapping tag only — or
fan out honestly to both — is the open question, and it is why the unbind reads
the file's own mapping rather than any tag that happens to map something.

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
  · Ejecting via n8n:ignore keeps the file instead of pruning it — and then the
    whole `n8n:ignore` feature went, see `workflows/ignore` above
  · Removing the mapping pill as a deliberate eject is paired with n8n:ignore —
    it IS the eject now, and it needs no reserved tag to say so

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
    core/js/mimetypelist.js) and re-stamps the .n8n filecache rows back to
    application/json. The store's clean-uninstall rule is about this shared state.
  - DATA: the app ORPHANS the user's data — it never deletes the .n8n files,
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
