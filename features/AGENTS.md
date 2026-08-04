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

## This file is being filled in as features are touched

It starts with `admin-mapping`, because that is the file the accompanying change
rewrote. The other fifteen still carry their prose inline.

**That is deliberate, not an oversight.** Moving every essay out at once would be
a 3 000-line diff that nobody can review, across files whose scenarios are not
otherwise changing — and the value of a note is that it is *correct*, which is
only cheap to confirm while you are already reading the behaviour. So: when you
rewrite a feature file, move its prose here and leave a `# notes:` breadcrumb
behind. When you are not touching it, leave it alone.

Ported from `kubed-io/nextcloud-penpot`, where this file grew the same way.

## admin-mapping

`features/admin-mapping.feature`

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

**The defaults are only two.** `team_folder` and `mode` are required by
`Mapping::fromArray()`, so they have no default to test; `nc_groups` defaults to
empty and `use_team_folder` defaults to **true**. That last one is worth
flagging: the Penpot sibling deliberately defaults it to **false**, because
groupfolders is an optional app and a default that fails on a stock Nextcloud is
not a default. This app has not made that change, and the divergence is real
rather than accidental — see the sibling's §C6.35.

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

### Two mappings may not target the same folder

`@unbuilt`, **and the gap is real.** `MappingService` asserts the tag is unique
and says nothing whatsoever about the folder, so today the second mapping is
accepted. Two tags mirroring into one folder interleave their workflow files, and
each mapping-scoped sync prunes what the other just wrote — the folder never
settles.

Written in deliberately the same shape as the tag rule above, because once it is
built the two collapse into one outline with the columns as the difference. The
Penpot sibling already refuses it (`assertFolderUnique`).
