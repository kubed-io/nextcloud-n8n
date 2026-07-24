# Bidirectional workflow-tag sync — a workflow's tags and its Nextcloud system
# tags are kept as ONE set, so the mirror is as searchable as n8n.
#
# Two label systems, made equal (minus our control tags):
#
#   • n8n tags       — tags on the workflow (`/api/v1/tags`, opaque ids; the
#                      workflow GET body echoes `tags: [{id,name},...]`). Written
#                      via a SEPARATE call: ensureTag(name)->id, then
#                      setWorkflowTags(id, [ids]) — the body PUT does not accept
#                      tags (n8n rejects the field; `N8nWorkflowBody`'s writable
#                      whitelist omits it).
#   • Nextcloud tags — collaborative SYSTEM TAGS (the coloured pills in Files,
#                      searchable via DAV REPORT).
#
# THE RULE OF EQUALITY: after a reconcile a managed workflow's n8n tags and its
# Nextcloud system tags hold the same strings, with ONE exclusion — the app's
# reserved namespace `n8n:*` (`n8n:sync`, `n8n:link`, `n8n:ignore`, and any future
# control tag). Reserved tags are the app's control plane: never pushed to n8n,
# never imported from n8n as content.
#
# THREE EDIT SURFACES — the object body is the third: tags are part of the object,
# so a sync file's on-disk JSON already has a `tags` array. That makes three
# editable places, kept as one set:
#   1. n8n tags on the workflow    (edit in n8n → pull)
#   2. the file body `tags` array  (edit the JSON → push)
#   3. Nextcloud system-tag pills  (edit the pills → push)
# The FILE BODY is the canonical object; the PILLS are a listener-kept projection.
# Editing either Nextcloud surface updates the other and pushes to n8n; a pull
# writes n8n's tags into the body and reconciles the pills. In `link` mode the body
# is a pointer (not the object), so only surfaces 1 and 3 exist and the pills are a
# read-only projection of n8n.
#
# NO EXTRA BUTTON FOR TAGS — a pill edit auto-propagates: adding or removing a
# system-tag pill on a managed `sync` file is caught by a dedicated tag listener
# (`TagAssignedEvent`/`TagUnassignedEvent` for CONTENT tags, not only the reserved
# `n8n:ignore`). The listener does two things, and neither is a manual sync:
#   a. It updates the file body's `tags` array IN PLACE with a loop-safe write — it
#      re-stamps `n8n_syncedHash` to the new body so the `NodeWrittenEvent` the write
#      emits is recognised as the app's own and does NOT re-push the whole file. A
#      tag change must never masquerade as a body edit.
#   b. It reconciles the ONE tag to n8n via the tags-only path
#      (`setWorkflowTags` → `PUT /workflows/{id}/tags`), NEVER the body PUT — so it is
#      decoupled from full-file writeback and safe on archived / odd-body workflows.
# Both steps honour the SAME `timing` knob the save-push already uses:
#   • `sync`  — reconcile inline during the request (instant, may briefly lock).
#   • `async` — enqueue a per-file job the cron worker runs on its next tick.
# This is the existing reconcile engine, triggered by the tag event and scoped to the
# one file — not a new manual action, and not a global scheduled push (there is NO
# scheduled NC→n8n sweep; the only bulk NC→n8n path is the manual "Sync to n8n").
#
# BODY EDITS RIDE THE SAME PATH AS `name`: a hand-edit of the JSON `tags` array is
# just a `NodeWrittenEvent`, the very event the filename/`name` reconcile already
# listens on. So ADDING or REMOVING a tag inside the body is a first-class edit:
# the pills follow the body (add a pill / drop a pill), and the next push carries
# the change to n8n (a removed body tag is a removed n8n tag) — subject to the
# mapping-tag protection below.
#
# PULL CHANGE-DETECTION — the scheduled (and manual) pull only writes what actually
# changed, so an hourly n8n→NC pull does not churn every file. Per workflow it
# compares n8n against the local file and takes ONE branch:
#   • body identical AND tags identical → SKIP: nothing to do, next workflow.
#   • body differs                      → write the new body (it already carries
#                                         n8n's `tags`), then reconcile the pills.
#   • only the tags differ              → run the tag path only: update the pills and
#                                         ensure the body `tags` array carries them,
#                                         re-stamp the baseline — WITHOUT rewriting an
#                                         otherwise-identical body.
# "Different" is measured against the stamped `n8n_syncedHash` (body) and
# `n8n_syncedTags` (baseline), the same markers the three-way merge already keeps.

#
# SEARCHABILITY IS MODE-INDEPENDENT: the pull-side systemtag reconcile runs for
# BOTH `sync` and `link` files. A `link` file is never pushed, so its tags flow one
# way only: n8n → Nextcloud.
#
# PROVENANCE — a new tag from Nextcloud vs a new tag from n8n: when the two sets
# differ on a string you cannot tell an ADD on one side from a REMOVE on the other
# from the current sets alone. So the app banks the reserved-stripped tag set as of
# the last successful sync in `n8n_syncedTags` (the tag analogue of
# `n8n_syncedHash`) and three-way-merges against it. Against a single baseline the
# merge is DETERMINISTIC — there is no add-vs-remove conflict to break: a tag is
# ADDED only if it was not in the baseline (so at least one side newly has it) and
# REMOVED only if it was in the baseline (so a side dropped it), and those are
# disjoint. Rule: add-on-either-side keeps the tag; REMOVE-ON-EITHER-SIDE drops it
# (the side that dropped a baseline tag is the one that changed, so it wins over the
# side that left it untouched). Direction (pull vs push) is NOT a merge input — it
# only decides which side the merged set is written back to.
#
# MAPPING-TAG PROTECTION (n8n-only): n8n maps a folder BY TAG, so the tag that binds
# a workflow to its folder is itself a content tag. It is shown as a pill for
# visibility but is PROTECTED: a reconcile FORCE-KEEPS it on both sides, so removing
# it from either Nextcloud surface — the pill OR the body `tags` array — never
# pushes a tag removal that would unbind the workflow and prune the mirror. Leaving
# a mapping is always an EXPLICIT gesture, and there are exactly
# two sanctioned forms: (1) move the file out of the folder → `unmapped` (workflow
# archived in n8n, restored on move-back), or (2) tag it `n8n:ignore` → `ignored`
# (workflow excluded from the mapping, file kept standalone). Removing the mapping
# pill AS a deliberate eject is therefore treated as form (2): it is paired with
# `n8n:ignore` so the file is KEPT, never silently pruned. This hazard has no Grafana
# analogue (Grafana maps by real folders).
#
# PRUNING — minimal in the end without wrecking shared catalogs. Tags exist at two
# levels on each side: the ASSIGNMENT (tag is on this workflow/file) and the
# DEFINITION (the catalog entry — an n8n `/api/v1/tags` row, or a Nextcloud system
# tag). The reconcile prunes ASSIGNMENTS aggressively and both ways: remove-on-either-
# side drops the edge, so the mirror never carries a tag the canonical side let go.
# That is the pruning that matters, and it is already bi-directional.
#
# DEFINITIONS are deliberately NOT auto-pruned. Neither catalog is ours alone — a
# system tag ("urgent") may be pinned on non-workflow files by a human, an n8n tag may
# sit on workflows outside any mapping — so deleting a definition because no MANAGED
# object uses it would strip it off bystanders. An orphaned definition is cheap and
# harmless (a dead pill in the picker, an n8n tag that maps nothing) and is often a
# human about to reuse it. So the minimal-in-the-end system is NOT "GC every
# unreferenced tag"; it is a perfect edge reconcile plus prune-free minting.
#
# PRUNE-FREE BY CONSTRUCTION: we never mint a throwaway definition. `ensureTag(name)`
# reuses an existing catalog entry by name on both sides (idempotent — no duplicates);
# reserved `n8n:*` never crosses, so n8n's catalog never grows a control tag; and a
# reconcile computes the FINAL merged set FIRST, then writes once (assign/unassign the
# winners), so we never create a pill or n8n tag we are about to drop. The baseline
# `n8n_syncedTags` is itself kept minimal — reserved-stripped, blank-filtered, deduped,
# sorted — and dies with the file, so metadata never leaks.
#
# OPTIONAL DEFINITION SWEEP (planned, opt-in, symmetric): if an admin wants the
# catalogs swept, it is an EXPLICIT `occ` command, dry-run first, NEVER on the
# reconcile hot path. Its predicate is conservative and identical on both sides: a
# definition is a candidate ONLY if it is non-reserved, not a mapping tag, and orphaned
# on BOTH sides at once — a tag still used on either side survives. Symmetry is the
# whole point: nothing alive anywhere in the pair is ever swept.
#
# ENGINE WIRED, SURFACES 1, 2 & 3 LIVE: the tag-reconcile engine
# ({@see TagSyncService} + the pure {@see TagMerge} three-way merge) and the
# `n8n_syncedTags` baseline key are implemented and unit-tested (saga Ch5 §5.6):
# pull mirrors n8n → pills for sync AND link, push writes pills → n8n for sync, the
# baseline disambiguates add-vs-remove, the reserved `n8n:*` namespace is excluded,
# and the mapping tag is protected. Those n8n↔pills scenarios are LIVE. As of
# §5.6.2 Slice A the PILL EDIT IS REACTIVE: adding/removing a content pill on a sync
# file is caught by {@see ContentTagListener} and reconciled to n8n on its own — no
# "Sync to n8n" click — honouring the same `timing` knob as the body writeback
# (`sync` inline, `async` via {@see ReconcileTagsJob}). And as of §5.6.2 Slice B the
# BODY EDIT IS REACTIVE TOO: {@see PushService::push} intercepts before the tag-less
# REST `PUT`, and {@see TagReconcileService::reconcileFromBody} reconciles the file's
# JSON `tags` array to n8n (body-canonical) — a bare `{"name":"foo"}` is created on
# n8n and rewritten in place with its real `{id,name}`; a pill edit locksteps the
# body the same way. Both are unit-tested ({@see TagReconcileServiceTest}). The
# body↔pills integration scenarios below have their WebDAV step defs written but
# stay `@todo` until the reactive body write is verified end-to-end against a live
# n8n (the pill→n8n reconcile is already green; the body lockstep + body-canonical
# push await a live re-test). Still PLANNED (`@todo` per-scenario): (1) the loop-guard
# "silent body update pushes no whole file" assertion, (2) PULL CHANGE-DETECTION
# (skip-unchanged / body / tags-only branches), and (3) the reactive eject and the
# optional catalog sweep. Shared with the Grafana sibling; per-backend
# knobs = tag write path, reserved prefix, protected-tags set.
#
# SCOPE — TAG SYNC IS A MAPPED-FOLDER FEATURE: every tag behaviour here (pull mirror,
# push, auto-trigger, change-detection) applies ONLY to a file managed by a mapping.
# An `unmapped` or `ignored` file is a plain Nextcloud file — its pills are ordinary
# system tags with NO n8n side effect — so the machinery must not leak onto it.
#
# KNOWN, NOT SOLVED HERE — ONE WORKFLOW, MANY MAPPINGS: a workflow carrying two
# mapping tags is mirrored into two folders (two files, one shared n8n object). A tag
# edit on one mirror reaches n8n but the sibling only catches up on its next pull;
# converging every mirror of an id in one gesture is future fan-out work (specced
# `@todo` at the end, deliberately out of scope for now).

Feature: A workflow's tags and its Nextcloud system tags stay one set
  As an n8n admin browsing workflows in Nextcloud
  I want each workflow's n8n tags mirrored as Nextcloud system tags and back
  So that the mirror is as searchable as n8n and I can re-tag from either side

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "flows"

  Scenario: Pull mirrors n8n tags onto the Nextcloud file as system tags
    Given n8n has a workflow tagged "flows", "dns", and "linux"
    When the "flows" mapping is pulled
    Then the workflow's file has the Nextcloud system tags "dns" and "linux"
    And the file can be found by a Nextcloud tag search for "linux"

  Scenario: The reserved namespace is never imported as a content tag
    Given n8n has a workflow tagged "flows", "linux", and "n8n:sync"
    When the "flows" mapping is pulled
    Then the workflow's file has the Nextcloud system tag "linux"
    And the file has no content tag "n8n:sync"

  Scenario: Pull mirrors tags even for a link mapping (searchability, not push)
    Given a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    Then the workflow's file has the Nextcloud system tags "prod" and "dns"
    And the file can be found by a Nextcloud tag search for "prod"

  Scenario: Push writes Nextcloud content tags into n8n (sync only)
    Given a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    And the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"
    And the reserved "n8n:*" tags are not written to n8n

  # ── a pill edit auto-propagates (no manual button), honouring the timing knob ───
  # PLANNED: a content-tag pill change on a sync file updates the body silently and
  # reconciles that ONE tag to n8n on its own — no "Sync to n8n" click required.
  #
  # LIVE (Slice A, §5.6.2): the pill→n8n reconcile is wired reactively via
  # ContentTagListener, honouring the same `timing` knob as the body writeback
  # (`sync` inline, `async` via ReconcileTagsJob). Slice A carries the pill to n8n and
  # converges the pills; it does NOT yet rewrite the file body's `tags` array (that is
  # Slice B), so the body-array assertions stay in the @todo projection scenarios below.

  Scenario: Adding a pill pushes the tag to n8n immediately when timing is "sync"
    Given the push timing is "sync"
    And a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the workflow in n8n is tagged "flows", "linux", and "urgent" without a manual push
    And the workflow's file has the Nextcloud system tag "urgent"

  Scenario: Adding a pill queues the tag push when timing is "async"
    Given the push timing is "async"
    And a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then a tag-reconcile job is queued for the file
    And the workflow in n8n is still tagged only "flows" and "linux"
    When the background queue runs
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"

  @todo
  Scenario: The silent body update for a tag edit does not re-push the whole file
    Given a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "flows", "linux", and "urgent"
    And the resulting file write is recognised as the app's own and pushes no workflow body

  Scenario: Removing a pill removes the tag from n8n on its own
    Given the push timing is "sync"
    And a managed "sync" file last synced with tags "flows", "linux", and "old"
    When the admin removes the Nextcloud system tag "old" from the file
    Then the workflow in n8n is tagged "flows" and "linux" without a manual push
    And the file has no content tag "old"

  # Surfaces 2 & 3 (the body↔pills projection) ARE wired now (Slice B) — the engine
  # is unit-tested in TagReconcileServiceTest and the WebDAV body-edit step defs are
  # written (below). They stay @todo until the reactive body write is verified
  # end-to-end against a live n8n: the pill→n8n reconcile is green (see the Slice A
  # scenarios above), but the body-array lockstep + body-canonical push need a live
  # re-test before we assert them in CI. Flip off @todo once that passes.
  @todo
  Scenario: Editing a pill updates the file body's tags array (body is canonical)
    Given the push timing is "sync"
    And a managed "sync" workflow file in "flows" with body tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "flows", "linux", and "urgent"

  @todo
  Scenario: Editing the file body's tags array updates the pills and pushes to n8n
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the file's Nextcloud system tags become "flows", "linux", and "prod"
    And the workflow in n8n is tagged "flows", "linux", and "prod"

  # The killer convenience: a human can add a tag with just its name and never
  # touch an id. Slice B fills n8n's real tag id back into the body for them.
  @todo
  Scenario: A bare {name} tag added in the body gains its n8n id
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the file body's "tags" array becomes "flows", "linux", and "prod"
    And every tag in the file body carries an n8n id

  # Removing a tag from the JSON body itself is a real edit surface — the same
  # NodeWrittenEvent path the `name` key already rides. The body is canonical, so
  # dropping a tag there drops the pill, and the next push drops it in n8n.
  @todo
  Scenario: Removing a tag from the file body's tags array removes the pill
    Given a managed "sync" workflow file in "flows" tagged "flows", "linux", and "old"
    When the admin edits the file body's "tags" array to "flows" and "linux"
    Then the file's Nextcloud system tags become "flows" and "linux"
    And the file has no content tag "old"

  @todo
  Scenario: A tag removed in the file body is removed in n8n on the next push
    Given a managed "sync" workflow file in "flows" tagged "flows", "linux", and "old"
    And the workflow in n8n is tagged "flows", "linux", and "old"
    When the admin edits the file body's "tags" array to "flows" and "linux"
    And the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows" and "linux"

  @todo
  Scenario: Removing the mapping-tag from the file body does not unbind the workflow
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to only "linux"
    Then the file's Nextcloud system tags still include "flows"
    And the file stays mapped to "flows"

  Scenario: A tag added in Nextcloud since the last sync is added in n8n
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n still has only "flows" and "linux"
    When the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"

  Scenario: A tag removed in n8n since the last sync is removed in Nextcloud
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the workflow in n8n now has only "flows" and "linux"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are exactly "flows" and "linux"

  # ── pull change-detection: only write what changed ─────────────────────────────
  # PLANNED: an hourly pull must not churn every file — it takes exactly one branch
  # per workflow based on what actually differs from the stamped baseline.

  @todo
  Scenario: An unchanged workflow is skipped by the pull
    Given a managed "sync" workflow file in "flows" whose body and tags match n8n
    When the "flows" mapping is pulled
    Then the file is not rewritten
    And its Nextcloud system tags are unchanged

  @todo
  Scenario: A content change pulls the new body and then reconciles the tags
    Given a managed "sync" workflow file in "flows" whose workflow body changed in n8n
    When the "flows" mapping is pulled
    Then the file body is updated from n8n
    And the file's Nextcloud system tags match the workflow's n8n tags

  @todo
  Scenario: A tags-only change in n8n updates the pills and the body without rewriting it
    Given a managed "sync" workflow file in "flows" whose body matches n8n
    But the workflow in n8n gained the tag "prod" since the last sync
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags include "prod"
    And the file body's "tags" array includes "prod"
    And the rest of the body is unchanged

  Scenario: A tag removed in Nextcloud since the last sync is removed in n8n
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the admin removes the Nextcloud system tag "old" from the file
    And the workflow in n8n still has "flows", "linux", and "old"
    When the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows" and "linux"
    And the "old" tag is gone from n8n

  Scenario: Independent changes on both sides both survive a reconcile
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n now also has "prod"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "flows", "linux", "urgent", and "prod"

  Scenario: An add on one side and an unrelated remove on the other both apply
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n now has only "flows" and "linux"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "flows", "linux", and "urgent"
    And the "old" tag is gone from both sides

  # ── mapping-tag protection (the n8n-only hazard) ──────────────────────────────

  Scenario: Removing the mapping-tag pill alone does not unbind the workflow
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin removes the Nextcloud system tag "flows" from the file
    And the "flows" mapping is reconciled
    Then the file still carries the "flows" system tag
    And the workflow in n8n still carries the "flows" tag
    And the file is still bound to the "flows" mapping

  # Move-out is the sanctioned unmap, and it is TAG-NEUTRAL: the unmap path only
  # archives the workflow in n8n — it never touches tags. So the n8n workflow keeps
  # its "flows" tag and the file keeps its "flows" pill; nothing is pushed or pruned.
  # Once the file is `unmapped` it is a plain Nextcloud file (see the scope scenarios
  # below), so tag-sync simply no longer applies to it.
  Scenario: Moving the file out is the sanctioned unmap — it changes no tags
    Given a managed "sync" workflow file in "flows" tagged "flows"
    When the file is moved out of the "flows" mapped folder
    Then the file becomes "unmapped"
    And the file still carries the "flows" system tag
    And the workflow in n8n still carries the "flows" tag

  # An unmapped file is just a Nextcloud file. Tag sync is a MAPPED-folder feature, so
  # the auto-trigger listener and the push/pull tag reconcile must all no-op on an
  # unmapped (or ignored) file — editing its pills is a plain Nextcloud tag change with
  # NO n8n side effect. This keeps the mapped-folder tag machinery from leaking onto
  # files it no longer owns.
  @todo
  Scenario: Editing tags on an unmapped file has no n8n tag-sync side effect
    Given a workflow file that has become "unmapped"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then no tag push to n8n is triggered
    And no tag-push job is queued
    And the tag is just a plain Nextcloud system tag on the file

  Scenario: Ejecting via n8n:ignore keeps the file instead of pruning it
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin tags the file "n8n:ignore"
    And the "flows" mapping is reconciled
    Then the file becomes "ignored"
    And the file is kept as a standalone copy, not pruned
    And "n8n:ignore" is never written to n8n as a content tag

  @todo
  Scenario: Removing the mapping pill as a deliberate eject is paired with n8n:ignore
    # The planned reactive gesture: dropping the binding tag on purpose means "take
    # this out of the mapping" — so the app marks it ignored rather than silently
    # pruning the mirror on the next pull.
    Given a managed "sync" workflow file in "flows" tagged "flows"
    When the admin removes the mapping-tag pill "flows" as an eject gesture
    Then the file is tagged "n8n:ignore" and becomes "ignored"
    And the file is kept, not pruned

  # ── pruning: edges are swept, catalog definitions are not ─────────────────────

  Scenario: A dropped tag is pruned from the mirror edge, not from the shared catalog
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the Nextcloud system tag "old" is also pinned on an unrelated non-workflow file
    When the admin removes the "old" pill from the workflow file
    And the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows" and "linux"
    And the "old" system-tag definition still exists
    And the unrelated file still carries the "old" pill

  Scenario: Reconcile never mints a definition it is about to drop
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the workflow in n8n now has only "flows" and "linux"
    When the "flows" mapping is reconciled
    Then no new tag definition is created on either side

  @todo
  Scenario: An optional catalog sweep keeps any tag still used on either side
    Given a non-reserved tag "shared" that is orphaned in Nextcloud
    But the tag "shared" is still on a workflow in n8n
    When an admin runs the optional catalog sweep
    Then the "shared" definition is kept on both sides

  @todo
  Scenario: An optional catalog sweep never removes a reserved or mapping tag
    Given the reserved definition "n8n:sync" and the mapping-tag definition "flows" exist
    When an admin runs the optional catalog sweep
    Then the "n8n:sync" definition is kept
    And the "flows" mapping-tag definition is kept

  # ── one workflow mirrored by several mappings (known, not solved here) ─────────
  # A single n8n workflow can carry two mapping tags at once (e.g. "flows" AND
  # "reports"), and each mapping mirrors it into its own folder — so the SAME
  # workflow id exists as TWO managed files in Nextcloud. They share one canonical
  # object in n8n, so an n8n tag is a property of the workflow, not of either file.
  #
  # The hazard: edit the pills on ONE mirror and push, and n8n now holds the merged
  # tag set — but the SIBLING file (same id, other folder) still shows its old pills
  # until its own mapping is next pulled, and its stale `n8n_syncedTags` baseline
  # could then read a since-agreed tag as a local remove and bounce it. Converging
  # all mirrors of one id on a tag edit (fan-out by workflow id, not just by file) is
  # the real fix and is deliberately OUT OF SCOPE for now; these scenarios only
  # PIN THE SHAPE so the future work has a target and the current behaviour is known.
  #
  # A SECOND hazard in the same setup: on the "flows" mirror the OTHER mapping's tag
  # ("reports") shows as an ORDINARY content pill — it is not THIS mapping's protected
  # tag (protection is per-mapping, `[mapping tag]`) — so dropping it here would push a
  # removal that unbinds the workflow from the "reports" mapping and prunes that mirror.
  # The protected set must therefore be the UNION of every mapping tag on the workflow,
  # not just the current mapping's. Also future fan-out work, pinned `@todo` below.
  @todo
  Scenario: One workflow with two mapping tags is mirrored into both mapped folders
    Given a folder mapped as "sync" to the n8n tag "flows"
    And a folder mapped as "sync" to the n8n tag "reports"
    And n8n has one workflow tagged both "flows" and "reports"
    When both mappings are pulled
    Then the workflow appears as a file in the "flows" folder
    And the workflow appears as a file in the "reports" folder
    And both files carry the same workflow id

  @todo
  Scenario: Editing tags on one mirror should converge its sibling (future fan-out)
    Given one n8n workflow mirrored as a file in both the "flows" and "reports" folders
    When the admin adds the Nextcloud system tag "urgent" to the "flows" mirror
    Then the workflow in n8n is tagged "flows", "reports", and "urgent"
    And the "reports" mirror should also show the "urgent" pill once its mapping next syncs
    # NOTE: same-request convergence of every mirror of one workflow id is not built
    # yet — for now the sibling catches up on its own next pull, and the app must not
    # bounce the agreed tag when it does.

  @todo
  Scenario: A sibling mapping's tag is protected on every mirror (future cross-mapping guard)
    # On the "flows" mirror the "reports" tag is an ordinary content pill, not this
    # mapping's protected tag, so today a push could drop it and unbind the sibling.
    # The fix is a protected set that is the UNION of all mapping tags on the workflow.
    Given one n8n workflow mirrored as a file in both the "flows" and "reports" folders
    When the admin removes the "reports" pill from the "flows" mirror
    And the "flows" mapping is pushed
    Then the workflow in n8n still carries the "reports" tag
    And the "reports" mirror is still bound to its mapping
