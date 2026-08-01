<!--
  SPDX-FileCopyrightText: 2026 Kelly Ferrone
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# How the feature files are organised

`features/*.feature` is this app's **specification**. It is written before the code
and kept true after it — documentation that happens to execute, not a test-naming
convention.

This file is the authority on layout and tags. The review checklist that follows
from it lives in [`.github/instructions/gherkin.instructions.md`](../.github/instructions/gherkin.instructions.md);
where the two disagree, this file wins.

## The organising rule: a feature is a BEHAVIOUR, not a mechanism

Files are named for **what a person did**, never for the kind of thing they did it
to. Renaming a workflow file and renaming a mapped folder are both *renames* and
belong in one file, so a reader comparing them sees one table instead of hunting
two.

The failure this prevents is silent: two files describing one behaviour drift
apart, and nobody reads two files to answer one question.

| File | Owns |
|---|---|
| `create-workflow.feature` | A workflow coming into existence, on either side |
| `copy.feature` | Duplicating a workflow file, and what the copy is *not* |
| `move.feature` | A file changing folder — into a mapping, out of one, between two |
| `rename.feature` | A file or workflow changing name, and the filename↔`name` reconcile |
| `delete.feature` | Everything that removes a workflow: both trash steps, and who owns each |
| `purge.feature` | The admin's deliberate wipe of the Nextcloud side |
| `tag-sync.feature` | A workflow's tags, across all three surfaces |
| `reserved-tags.feature` | The `n8n:*` control plane — `n8n:ignore` and the mode pills |
| `mapping-membership.feature` | Which files a mapping owns, and what "unmapped" means |
| `file-type.feature` | A mirror as a first-class file type: mimetype, icon, DAV props |
| `open-with.feature` | What clicking a mirror does |
| `admin-connection.feature` | Reaching n8n at all: URL, key, and how failure reads |
| `admin-mapping.feature` | Creating, editing, and removing a mapping |
| `reconcile.feature` | What a sync run does *as a run*: completeness, idempotency, what it reports |
| `lifecycle.feature` | Install and enable |
| `uninstall.feature` | Removal, and what survives it |

**A scenario describing a behaviour another file owns is a defect**, even when it
passes. Move it.

## Tags are an index, not decoration

A scenario carries tags on **one line, directly above `Scenario:`** — axis tags first,
status last: `@in-nextcloud @gesture @unbuilt`. A tag on its own line separated by
comments binds to the wrong scenario, so keep them together.

The point is that `behat --tags` becomes a query. *"Everything a user can do from the
Files app"*, *"everything that starts in n8n"*, *"everything the scheduled job does"* —
each is one filter rather than a grep and a guess.

### Origin — where the action happened

| Tag | Meaning |
|---|---|
| `@in-nextcloud` | Someone acted in Nextcloud. The payoff is what reached n8n. |
| `@in-n8n` | The workflow changed in n8n (a human, another client, n8n itself). The payoff is what reached Nextcloud, and a sync is implied. |

A scenario with **neither** never crosses the boundary: configuration, a refusal, or a
local-only surface like the mimetype or the opener menu. That absence is information —
do not invent an origin to fill the column.

### Channel — how it was triggered

| Tag | Meaning |
|---|---|
| `@gesture` | A Files-app action: create, rename, move, copy, delete, restore, upload, toggling a pill. Driven over WebDAV, which is what a browser sends. |
| `@occ` | A CLI command. |
| `@admin` | The admin settings panel. |
| `@scheduled` | The timed job, with no human present. |

More than one is normal and correct: a scenario that toggles a pill and then runs a
push is `@gesture @occ`. Tag what the scenario actually does, not what it is "about".

### `sync` vs `link` is NOT an axis

The tempting move is to write every behaviour twice, once per mode. Don't — the modes
only diverge in one direction. An `@in-n8n` scenario is mode-agnostic: a workflow
renamed or deleted in n8n reaches Nextcloud the same way either way, and a `link`
simply has no bytes to update. Only `@in-nextcloud` scenarios branch, because a link is
a read-only projection.

The test: can you write the restriction as a sentence starting *"A link…"*? If yes it
is a rule and deserves its own scenario. If the mode makes no difference to the
outcome, leave it out.

## Status tags — four of them, and only one is a backlog

The most useful question you can ask a spec is **"what is built but untested?"**.
One tag cannot answer it, and for a long time this repo had one.

| Tag | Means | What to do about it |
|---|---|---|
| *(none)* | Runs in CI. | Keep it green. |
| `@todo` | **The code exists; only the test is missing.** | Write the test. |
| `@unbuilt` | A spec awaiting code. | Build the feature. |
| `@blocked` | Real behaviour this harness cannot reach. | Extend the harness — or accept it. |
| `@decision` | Records a deliberate absence. There is no operation. | Nothing, ever. |

**`behat --tags @todo` is the work queue.** Anything else in that bucket is noise,
so the rules below are about keeping it honest.

### `@blocked` must NAME the missing capability

A `@blocked` that does not say what is missing is a `@todo` nobody checked. The
ones that exist here are: **no browser** (the Files-app menu surface), **no way to
make n8n unreachable mid-request**, **no app remove/reinstall in CI** (the harness
can only disable and enable), and **no proven DAV REPORT search over
`nc:metadata-*`**.

If the stated reason stops being true, the tag is stale and the scenario is
probably promotable. That is not hypothetical: `file-type.feature` skipped its
`link` row for "link integration is uncertain" while `delete.feature` and
`move.feature` were both arranging a `link` file and running green.

### `@unbuilt` vs `@todo` is about the CODE, not the test

If `lib/` cannot do the thing, it is `@unbuilt` — no matter how well specified it
is. Marking unbuilt work `@todo` inflates the backlog with items no test could
ever pass, which is exactly what makes the queue worth ignoring.

### `@decision` is a permanent absence, not "nothing happened"

`@decision` records that a capability **does not and will not exist** ("there is no
scheduled Nextcloud→n8n sweep"). It is *not* for an operation whose outcome is that
nothing was sent — "n8n is never contacted" is ordinary behaviour, testable by
absence, and several such scenarios run in CI today.

Read the `Then`, not the `When`: the outcome of an operation, or the absence of the
operation itself?

### A `@todo` that FAILS is a finding, not a status

Legitimate — but it must say so in a comment, or it is indistinguishable from one
nobody has written yet.

## `Rule:` is NOT available — verified, not assumed

Behat's parser rejects the keyword (`Expected Step, but got text: "  Rule: …"`) and
there is no newer Behat to move to. Business rules are comment banners:

```gherkin
  # ── RULE: a link is never pushed ──────────────────────────────────────────
```

**Never suggest converting these to `Rule:` blocks.**

## Scenario Outline: an input, or a different rule?

`Examples` is right when the rows are **one rule over different inputs** and the
outcome is identical for every row. It is wrong when the rows are **different rules
sharing a shape** — that hides asymmetries, which is where bugs live.

The test: can you write the rows as a list of *values*, or only as a list of
*sentences*? Values → `Examples`. Sentences → separate scenarios.

## Wording is an API

Every step line is a function signature, so the vocabulary is deliberately small
and parameterised. Read `tests/integration/bootstrap/Steps/` before inventing a
phrasing — **two wordings for one idea are two functions to maintain and two ways
for the same assertion to drift.**

The reverse is fine and intentional: one function may answer to several phrasings,
because Gherkin ignores the keyword when matching. `the "flows" mapping is pulled`
and `the team has been mirrored` can be the same step read two honest ways.

**Setup says what IS TRUE, not who did what to make it true.** `Given the admin
runs a pull` reads as though an admin were permanently on call before every gesture
a user makes. That is not the system being described.

## Where the binding lives

A scenario is only real if a step definition matches every one of its lines.

| What | Where |
|---|---|
| The scenarios | `features/*.feature` (repo root — they are docs) |
| The step definitions | `tests/integration/bootstrap/Steps/*.php` |
| The context that composes them | `tests/integration/bootstrap/FeatureContext.php` |
| Transports (occ, WebDAV, the n8n API) | `tests/integration/bootstrap/Support/` |
| What CI actually runs | `tests/integration/behat.dist.yml` |

CI runs `--strict`, so **an undefined step in an untagged scenario fails the
build.** That is the safety net: a scenario with no status tag is claiming to be
live, and CI enforces the claim.

A new `*Steps.php` that nobody `use`d in `FeatureContext` is silently dead.
