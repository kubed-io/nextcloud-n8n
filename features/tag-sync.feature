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
# BODY EDITS RIDE THE SAME PATH AS `name`: a hand-edit of the JSON `tags` array is
# just a `NodeWrittenEvent`, the very event the filename/`name` reconcile already
# listens on. So ADDING or REMOVING a tag inside the body is a first-class edit:
# the pills follow the body (add a pill / drop a pill), and the next push carries
# the change to n8n (a removed body tag is a removed n8n tag) — subject to the
# mapping-tag protection below.

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
# ENGINE WIRED, SCENARIOS PENDING STEPS: the tag-reconcile engine
# ({@see TagSyncService} + the pure {@see TagMerge} three-way merge) and the
# `n8n_syncedTags` baseline key are implemented and unit-tested (saga Ch5 §5.6):
# pull mirrors n8n → pills for sync AND link, push writes pills → n8n for sync, the
# baseline disambiguates add-vs-remove, the reserved `n8n:*` namespace is excluded,
# and the mapping tag is protected. This feature stays @todo — CI skips it — until
# the integration step definitions and the live body↔pills projection listener
# (surfaces 2 and 3) land. Shared with the Grafana sibling; per-backend knobs = tag
# write path, reserved prefix, protected-tags set.

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

  # Surfaces 2 & 3 (the live body↔pills projection listener) are not wired yet, so
  # a hand-edit of the JSON `tags` array is not reflected onto the pills or pushed.
  # These scenarios are the spec for that PLANNED work.
  @todo
  Scenario: Editing a pill updates the file body's tags array (body is canonical)
    Given a managed "sync" workflow file in "flows" with body tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "flows", "linux", and "urgent"

  @todo
  Scenario: Editing the file body's tags array updates the pills and pushes to n8n
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the file's Nextcloud system tags become "flows", "linux", and "prod"
    And when the "flows" mapping is pushed the workflow in n8n is tagged "flows", "linux", and "prod"

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

  Scenario: Moving the file out is the sanctioned unmap (the tag is left to the unmap path)
    Given a managed "sync" workflow file in "flows" tagged "flows"
    When the file is moved out of the "flows" mapped folder
    Then the file becomes "unmapped"
    And the workflow's "flows" tag is handled by the unmap path, not the tag sync

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
