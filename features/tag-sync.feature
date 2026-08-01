# Bidirectional workflow-tag sync — a workflow's tags and its Nextcloud system
# tags are kept as ONE set, so the mirror is as searchable as n8n.
#
# Two label systems, made equal (minus our control tags):
#
#   • n8n tags       — tags on the workflow (`/api/v1/tags`, opaque ids; the
#                      workflow GET body echoes `tags: [{id,name},...]`). Written
#                      via a SEPARATE call: ensureTag(name)->id, then
#                      setWorkflowTags(id, [ids]).
#                      THE BODY CAN NEVER CARRY TAGS, ON CREATE OR ON UPDATE — this
#                      is read off n8n's own OpenAPI spec, not inferred: both
#                      `workflow.yml` and `workflowCreate.yml` are
#                      `additionalProperties: false` with `tags: readOnly: true`.
#                      `PUT /workflows/{id}/tags` (tag IDS, not names) is the only
#                      writer there is. `N8nWorkflowBody`'s writable whitelist omits
#                      `tags` for exactly that reason.
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
#   1. n8n tags on the workflow    (edit in n8n → pull)                    — LIVE
#   2. the file body `tags` array  (edit the JSON → push)                  — DEFERRED
#   3. Nextcloud system-tag pills  (edit the pills → push)                 — LIVE
# TODAY the body `tags` array is a DERIVED MIRROR the pull writes; a hand-edit of it
# is NOT projected to n8n and self-heals on the next pull. The PILLS are the
# authoritative Nextcloud tag surface today (surface 3). In `link` mode the body is a
# pointer (not the object), so only surfaces 1 and 3 exist and the pills are a
# read-only projection of n8n.
#
# THE THREE SURFACES ARE NOT PEERS — ONLY ONE IS PORTABLE (saga §5.6.3). This is what
# decides the model, and it is a fact about the surfaces rather than a preference:
#
#   surface            survives export/re-import?   survives a copy?
#   n8n tags           n/a — it IS the remote        n/a
#   NC pills           NO — bound to a file id       NO (NC doesn't copy system tags)
#   body `tags`        YES — it is bytes in the file YES
#
# So a `.n8n.json` that leaves Nextcloud and comes back carries its tags in exactly
# one place: its own body. Nothing else can know them.
#
# AUTHORITY BELONGS TO THE MOMENT, NOT TO A SURFACE. "The JSON is the source of truth"
# and "n8n takes precedence" are not in conflict once they are separated by when:
#
#   ADOPTION (a file becomes managed: create / copy / move-in)  → THE BODY WINS
#       Nothing else knows. No pills, no metadata, no workflow yet.
#   STEADY STATE, no Nextcloud edit                            → n8n WINS
#       n8n is the system of record; the pull heals both NC surfaces and a stale
#       body loses. A file-vs-n8n disagreement with no NC edit resolves to n8n.
#   A DELIBERATE NEXTCLOUD EDIT (a pill toggle, or a body-`tags` edit) → THE EDIT WINS
#       The user acted; carry it to n8n.
#
# WHY PICKING A WINNER IS NOT ENOUGH ON ITS OWN: `body {a,b}` vs `n8n {a,b,c}` is the
# SAME two sets whether the user deleted `c` from the file or added the `c` pill while
# the body sat stale — and the correct answer is opposite in each case. A fixed winner
# does not resolve that, it only picks which of two legitimate gestures to break. The
# BASELINE is what says who moved (see PROVENANCE below); precedence is then needed
# only where there is no baseline at all — which is adoption, and there n8n's rule is
# the tiebreak.
#
# NO EXTRA BUTTON FOR TAGS — a pill edit auto-propagates (LIVE, Slice A): adding or
# removing a system-tag pill on a managed `sync` file is caught by a dedicated tag
# listener (`TagAssignedEvent`/`TagUnassignedEvent` for CONTENT tags, not only the
# reserved `n8n:ignore`). Today it reconciles the tag to n8n via the tags-only path
# (`setWorkflowTags` → `PUT /workflows/{id}/tags`), NEVER the body PUT — so it is
# decoupled from full-file writeback and safe on archived / odd-body workflows.
# (DEFERRED, Slice B: the listener would ALSO update the file body's `tags` array in
# place with a loop-safe write — re-stamping `n8n_syncedHash` so the `NodeWrittenEvent`
# the write emits is recognised as the app's own and does NOT re-push the whole file.
# That body lockstep is not wired today; the body self-heals on the next pull instead.)
# The reconcile honours the SAME `timing` knob the save-push already uses:
#   • `sync`  — reconcile inline during the request (instant, may briefly lock).
#   • `async` — enqueue a per-file job the cron worker runs on its next tick.
# This is the existing reconcile engine, triggered by the tag event and scoped to the
# one file — not a new manual action, and not a global scheduled push (there is NO
# scheduled NC→n8n sweep; the only bulk NC→n8n path is the manual "Sync to n8n").
#
# BODY EDITS WOULD RIDE THE SAME PATH AS `name` (DEFERRED, Slice B — saga §5.6.2.3): a
# hand-edit of the JSON `tags` array is just a `NodeWrittenEvent`, the very event the
# filename/`name` reconcile already listens on. The INTENT is that adding or removing a
# tag inside the body becomes a first-class edit: the pills follow the body and the
# next push carries the change to n8n. This is NOT wired today (the attempt regressed
# the pill path and was reverted); the body is a derived mirror for now.
#
# PULL CHANGE-DETECTION — NOT BUILT (saga §5.6.3). Stated here as the target, and
# every scenario for it below is `@todo`. TODAY `SyncService::writeWorkflow` calls
# `putContent($body)` UNCONDITIONALLY for every workflow on every pull, so an hourly
# pull rewrites every mirrored file and bumps its mtime every hour. This is also why
# a pill added in Nextcloud only reaches the file's `tags` array when the next pull
# rewrites the WHOLE body — the tags-only branch below is what fixes both.
#
# The target: only write what actually changed. Per workflow, compare n8n against the
# local file and take ONE branch:
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
# ENGINE WIRED, SURFACES 1 & 3 LIVE — SURFACE 2 (BODY) DEFERRED: the tag-reconcile
# engine ({@see TagSyncService} + the pure {@see TagMerge} three-way merge) and the
# `n8n_syncedTags` baseline key are implemented and unit-tested (saga Ch5 §5.6):
# pull mirrors n8n → pills for sync AND link, push writes pills → n8n for sync, the
# baseline disambiguates add-vs-remove, the reserved `n8n:*` namespace is excluded,
# and the mapping tag is protected. Those n8n↔pills scenarios are LIVE. As of
# §5.6.2 Slice A the PILL EDIT IS REACTIVE: adding/removing a content pill on a sync
# file is caught by {@see ContentTagListener} and reconciled to n8n on its own — no
# "Sync to n8n" click — honouring the same `timing` knob as the body writeback
# (`sync` inline, `async` via {@see ReconcileTagsJob}).
#
# SURFACE 2 (edit the JSON `tags` array) IS DEFERRED (saga §5.6.2.3): Slice B was
# built (body-canonical push in {@see PushService}) and then REVERTED before merge —
# CI caught that its shared-merge refactor regressed the shipping pill path. TODAY the
# body `tags` array is a DERIVED MIRROR a pull writes: a hand-edit of it is NOT
# projected to n8n and self-heals (is overwritten) on the next pull. The pills are the
# authoritative Nextcloud tag surface — re-tag from the pills, not the JSON. The
# reconcile engine ({@see TagReconcileService::reconcileFromBody}) is kept, unit-tested
# but UNWIRED, for when the feature is picked up as its own `NodeWrittenEvent` trigger
# (and it must be verified live before its `@todo` scenarios come off).
#
# Still PLANNED (`@todo` per-scenario): (1) ADOPTION carrying the body's tags into n8n
# — a DEFECT today, not merely unbuilt: the tags are silently discarded (saga §5.6.3),
# (2) the body↔pills projection scenarios (surface 2), (3) PULL CHANGE-DETECTION
# (skip-unchanged / body / tags-only branches), and (4) the reactive eject and the
# optional catalog sweep. Shared with the Grafana sibling; per-backend knobs = tag
# write path, reserved prefix, protected-tags set.
#
# WHAT IS REALTIME, AND WHAT CANNOT BE:
#   pill → n8n         realtime (`timing=sync`) or next tick (`async`)   — LIVE
#   file body → n8n    would be realtime on the same NodeWrittenEvent    — PLANNED
#   n8n → Nextcloud    scheduled pull only                               — POLL-ONLY
# The third is not a gap that can be closed: n8n emits no outbound event on a tag
# change. The near-realtime answer stays "build an n8n workflow that pushes to
# Nextcloud", the same escape hatch the schedule setting already advertises.
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

    # ══ ADOPTION LIVES IN create-workflow.feature ══════════════════════════════
    #
    # A file arriving in a mapped folder already carrying tags in its body is a
    # CREATION, so it is specced next to every other way a workflow comes into
    # existence — not here. This file owns what happens to a workflow's tags once
    # it is managed.
    #
    # Worth knowing while reading the rest of this file, because it is the one
    # moment the body outranks everything: at adoption there are no pills, no
    # baseline, and no workflow, so the body is the only record of the tags. Today
    # they are silently discarded (saga §5.6.3) — see create-workflow.feature.

    # ══ THE MAP: FOUR SURFACES, THREE DIRECTIONS, AND ONE AMBIGUITY ════════════
    #
    # Everything about tag sync becomes obvious once the four surfaces are written
    # out side by side, so the scenarios in this section do exactly that. Each one
    # states the WHOLE tag state before and after, in one step:
    #
    #     the tag state is n8n "…" / pills "…" / body "…" / agreed "…"
    #
    #   • n8n     — the workflow's tags. The remote system of record.
    #   • pills   — the Nextcloud system tags on the mirror file. Searchable, and
    #               the surface a user actually clicks.
    #   • body    — the `tags` array inside the `.n8n.json`. THE ONLY PORTABLE ONE:
    #               it survives an export, a copy, a trip out of Nextcloud and back.
    #   • agreed  — `n8n_syncedTags`, the set n8n and Nextcloud last agreed on. Not
    #               a surface a user can see; it is what tells an ADD from a REMOVE.
    #
    # Reading the four columns is the point. A row where `body` disagrees with
    # `pills` is the entire open problem, and the scenarios below are arranged to
    # show exactly when that can happen.

    # ── DIRECTION 1: n8n → Nextcloud ────────────────────────────────────────────
    # The only direction that is fully built and fully live. n8n is authoritative;
    # a pull carries its tags to BOTH Nextcloud surfaces and re-stamps `agreed`.

  @unbuilt
  Scenario: A tag added in n8n reaches both Nextcloud surfaces
    Given the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    When the tag "c" is added to the workflow in n8n
    And the "flows" mapping is pulled
    Then the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"
    # All four move together, so nothing is left disagreeing. This is what makes
    # direction 1 the easy one: the pull rewrites the body wholesale, so it cannot
    # leave a stale copy behind.

  @unbuilt
  Scenario: A tag removed in n8n is removed from both Nextcloud surfaces
    Given the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"
    When the tag "c" is removed from the workflow in n8n
    And the "flows" mapping is pulled
    Then the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    # `agreed` is what makes this a REMOVE rather than "Nextcloud has an extra tag":
    # `c` was in the baseline, so exactly one side dropped it, and that side wins.

    # ── DIRECTION 2: a pill → n8n (LIVE — and the one that creates the problem) ──
    # Slice A. Adding or removing a pill on a managed `sync` file carries the change
    # to n8n on its own. It deliberately does NOT touch the file body.

  @unbuilt
  Scenario: A pill added in Nextcloud reaches n8n and leaves the body behind
    Given the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    When the admin adds the Nextcloud system tag "c" to the file
    Then the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b" / agreed "a,b,c"
    #                                                   ^^^^^^^^^ THE STALE BODY.
    # Three of four surfaces moved. The body did not, because Slice A's contract is
    # "carry the pill, leave the body alone" and the body self-heals on the next
    # pull. Everything downstream that is hard about tag sync starts on this line.

  @unbuilt
  Scenario: A pill removed in Nextcloud reaches n8n and leaves the body behind
    Given the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"
    When the admin removes the Nextcloud system tag "c" from the file
    Then the tag state is n8n "a,b" / pills "a,b" / body "a,b,c" / agreed "a,b"
    # Same shape, opposite direction: now the body carries a tag that no longer
    # exists anywhere else.

    # ── THE ONE THING THAT CAN MAKE THE BODY STALE ──────────────────────────────
    #
    # THIS IS THE SCENARIO TO READ IF NOTHING ELSE. The whole difficulty of the
    # third direction is "we cannot tell a body edit from a stale body" — and that
    # is only a problem if the body can BE stale. It can, and there is exactly ONE
    # way to get there. Enumerating the alternatives is what proves it:
    #
    #   a pull runs                → rewrites the body wholesale   → body is FRESH
    #   the user edits the body    → the body is what they typed    → body is TRUTH
    #   a tag changes in n8n alone → invisible until a pull, which  → body is FRESH
    #                                rewrites the body
    #   a PILL is toggled          → n8n and pills move, body does  → body is STALE
    #                                not                              ↑ only cause
    #
    # So staleness is not a fact of life about mirrors — it is a consequence of ONE
    # design decision, taken in Slice A for a good reason at the time. Which means
    # the ambiguity is not inherent to three-way sync at all, and it can be removed
    # at the source instead of worked around downstream.

  @unbuilt
  Scenario: Only a pill edit can leave the body disagreeing with the pills
    Given the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    When a pull runs
    Then the body agrees with the pills
    When the tag "c" is added to the workflow in n8n and a pull runs
    Then the body agrees with the pills
    When the admin adds the Nextcloud system tag "d" to the file
    Then the body does not agree with the pills
    # Three triggers, one divergence. Fix that one and `body ≠ pills` becomes an
    # unambiguous signal rather than a puzzle.

    # ── DIRECTION 3: the body → n8n (the goal, and what blocks it) ──────────────
    #
    # What "full sync" means: type a tag into the `.n8n.json` and it reaches n8n and
    # the pills. n8n's API forces the shape — `tags` is readOnly on both create and
    # update, so a body save can never carry tags; they go via
    # `PUT /workflows/{id}/tags`, separately, always.
    #
    # The body may be written LOOSELY. A human types `{"name": "prod"}` with no id,
    # and that must work: we resolve the name to an id for n8n and leave the file as
    # typed. The next pull rewrites the array with n8n's canonical `{id,name}` rows.
    # So the file is briefly "wrong" in a way that self-corrects, deliberately.

  @unbuilt
  Scenario: A tag typed into the file reaches n8n and the pills
    Given the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    When the admin adds the tag "c" to the file body and saves
    Then the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"

  @unbuilt
  Scenario: A tag typed into the file by name alone is accepted
    Given the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    And the admin adds the tag "c" to the file body with no id
    When the file is saved
    Then the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"
    And the file body still carries "c" exactly as it was typed
    # The convenience that makes the body worth editing at all. The id arrives on
    # the next pull; until then the file is readable and the sync is correct.

  @unbuilt
  Scenario: A tag deleted from the file is removed from n8n and the pills
    Given the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"
    When the admin removes the tag "c" from the file body and saves
    Then the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    # THE ONE THAT CANNOT BE BUILT SAFELY TODAY, and the reason is the next
    # scenario, not this one.

    # ── THE AMBIGUITY, WRITTEN OUT ──────────────────────────────────────────────
    #
    # Put the stale body from direction 2 next to the removal from direction 3 and
    # they become the SAME on-disk state with two opposite correct answers:
    #
    #     pills "a,b,c"  body "a,b"   → the user deleted `c` from the file  (remove)
    #     pills "a,b,c"  body "a,b"   → a pill was added, body not rewritten (ignore)
    #
    # Identical inputs. This is why "just pick a winner" does not work: precedence
    # chooses which of two legitimate gestures to destroy, it does not tell them
    # apart. A baseline tells you WHO MOVED — but `agreed` is the baseline for the
    # n8n↔pills pair, and it says nothing about what the BODY last held.

  @unbuilt
  Scenario: A save that did not touch the tags must not undo a pill edit
    Given the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    And the admin adds the Nextcloud system tag "c" to the file
    When the admin edits the workflow's nodes and saves, leaving the tags alone
    Then the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b" / agreed "a,b,c"
    # NOTHING MAY HAPPEN HERE. The body reads "a,b" and disagrees with everything,
    # but the user did not touch a tag — they edited nodes. Reading the body as
    # truth on this save pushes a removal of the `c` they added seconds ago.
    #
    # This scenario is the acceptance test for the whole third direction. Any design
    # that cannot pass it is wrong regardless of how well it handles the happy path.

    # ── THE TWO WAYS OUT, AND WHY ONE IS NOW PREFERRED ──────────────────────────
    #
    # A — REMEMBER WHAT THE BODY LAST HELD (`n8n_bodyTags`). A fourth stamp,
    #     updated whenever the app reads or writes the body. `body == n8n_bodyTags`
    #     ⇒ the user did not touch tags (free, no n8n call, and a stale body still
    #     equals its own stamp). Different ⇒ a real edit, applied as a DELTA.
    #     Costs: one more metadata key; the body still visibly lags a pill edit.
    #
    # B — NEVER LET THE BODY GO STALE. A pill edit also rewrites the body's `tags`
    #     array, so `body ≠ pills` can ONLY mean a body edit. No new metadata, and
    #     it makes the surfaces honest rather than reconciling a known lie.
    #     Costs: one guarded `putContent` per pill edit, loop-guarded by re-stamping
    #     `n8n_syncedHash` so the write it triggers is recognised as the app's own.
    #
    # B IS NOW THE RECOMMENDATION, and the reason is the "one cause" scenario above:
    # A treats staleness as a fact to be tracked, B removes the only thing that
    # produces it. B also gets cheaper the moment pull change-detection lands, since
    # a tags-only pull already has to write the body's `tags` array in place — the
    # same operation a pill edit needs. Two features, one mechanism.
    #
    # B was built once and reverted, and it is worth being precise about why: the
    # revert was NOT because lockstep is wrong. The commit also refactored the
    # SHARED merge so the pill path and the body path went through one "read the NC
    # side" step, and that regressed the shipping pill path. The lesson recorded at
    # the time still stands — the body path needs its own entry point and must not
    # touch `reconcilePush` — and it says nothing against the body write itself.

  @unbuilt
  Scenario: A pill edit keeps the file body in step
    Given the tag state is n8n "a,b" / pills "a,b" / body "a,b" / agreed "a,b"
    When the admin adds the Nextcloud system tag "c" to the file
    Then the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"
    And the resulting file write does not push the workflow body to n8n
    # Option B. Compare with "A pill added in Nextcloud reaches n8n and leaves the
    # body behind" above: same gesture, and the only difference is the body column.
    # That one column is the entire fix.

  @unbuilt
  Scenario: With the body kept in step, a save that did not touch tags is free
    Given the tag state is n8n "a,b,c" / pills "a,b,c" / body "a,b,c" / agreed "a,b,c"
    When the admin edits the workflow's nodes and saves, leaving the tags alone
    Then no tag call is made to n8n
    And the tag state is unchanged
    # The pay-off. Because the body was never allowed to go stale, "the body agrees
    # with the pills" is now a reliable no-op test — and the expensive, ambiguous
    # comparison the third direction used to need disappears entirely.

    # ══ STEADY STATE ═══════════════════════════════════════════════════════════

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

  # A link is a READ-ONLY projection of n8n's tags: the pills are there so you can
  # search, but n8n is the only writer. A pill added on a link never pushes (the
  # reactive reconcile gates on sync), and because a link has no push channel that
  # stray pill would linger forever — so the pull wipes it, mirroring n8n exactly.
  Scenario: A pill added on a link is not pushed to n8n (read-only projection)
    Given the push timing is "sync"
    And a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    And the admin adds the Nextcloud system tag "local" to the file
    Then the workflow in n8n is tagged "reports", "prod", and "dns"

  Scenario: A locally-added pill on a link is wiped on the next pull (n8n is the only writer)
    Given a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    And the admin adds the Nextcloud system tag "local" to the file
    And the "reports" mapping is pulled
    Then the file has no content tag "local"
    And the workflow's file has the Nextcloud system tags "prod" and "dns"
    And the file can be found by a Nextcloud tag search for "prod"

  Scenario: A tag added in n8n lands on the link on the next pull (searchable projection)
    Given a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    And the workflow in n8n now also has "urgent"
    And the "reports" mapping is pulled
    Then the workflow's file has the Nextcloud system tags "prod" and "urgent"
    And the file can be found by a Nextcloud tag search for "urgent"

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

  @unbuilt
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

  # ── surface 2: editing the tags array in the file ────────────────────────────
  #
  # DEFERRED (saga §5.6.2.3, redesigned in §5.6.3). The reconcile engine is
  # unit-tested (TagReconcileServiceTest) and the WebDAV body-edit step defs below
  # are written, but the trigger is NOT wired: Slice B was built and reverted
  # because its shared-merge refactor regressed the shipping pill path.
  #
  # THE OPEN PROBLEM, STATED SO IT IS NOT REDISCOVERED: telling "the user edited the
  # tags array" from "the body is merely stale". The body goes stale for exactly one
  # reason — a pill edit updates the pills and n8n and deliberately leaves the file
  # alone (Slice A's contract). So `body ≠ pills` is ambiguous, and reading the body
  # as whole-set truth would push a REMOVAL of the pill the user just added, on an
  # unrelated nodes-only save. Two honest fixes, both recorded in §5.6.3:
  #
  #   A — a change marker (`n8n_bodyTags`): store the tag set the body carried when
  #       the app last read or wrote it. Equal ⇒ the user did not touch tags (free,
  #       no n8n call, and a stale body still equals its own marker). Different ⇒ a
  #       deliberate edit, applied as a DELTA. No extra file writes; the body may lag
  #       visibly until the next pull. ← current lean
  #   B — lockstep: a pill edit also rewrites the body's `tags` array, so the two can
  #       never diverge and `body ≠ pills` unambiguously means a body edit. No new
  #       metadata; costs one guarded putContent per pill edit, and every future
  #       writer of the tag set has to remember to do it.
  #
  # Until one lands, the body `tags` array is a derived mirror — edit the pills, not
  # the JSON. Whichever lands must be VERIFIED LIVE before its @todo comes off; a
  # green unit test was not enough for this one last time.
  @unbuilt
  Scenario: Editing a pill updates the file body's tags array (body is canonical)
    Given the push timing is "sync"
    And a managed "sync" workflow file in "flows" with body tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "flows", "linux", and "urgent"

  @unbuilt
  Scenario: Editing the file body's tags array updates the pills and pushes to n8n
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the file's Nextcloud system tags become "flows", "linux", and "prod"
    And the workflow in n8n is tagged "flows", "linux", and "prod"

  # The killer convenience: a human can add a tag with just its name and never
  # touch an id. Slice B fills n8n's real tag id back into the body for them.
  @unbuilt
  Scenario: A bare {name} tag added in the body gains its n8n id
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the file body's "tags" array becomes "flows", "linux", and "prod"
    And every tag in the file body carries an n8n id

  # Removing a tag from the JSON body itself is a real edit surface — the same
  # NodeWrittenEvent path the `name` key already rides. The body is canonical, so
  # dropping a tag there drops the pill, and the next push drops it in n8n.
  @unbuilt
  Scenario: Removing a tag from the file body's tags array removes the pill
    Given a managed "sync" workflow file in "flows" tagged "flows", "linux", and "old"
    When the admin edits the file body's "tags" array to "flows" and "linux"
    Then the file's Nextcloud system tags become "flows" and "linux"
    And the file has no content tag "old"

  @unbuilt
  Scenario: A tag removed in the file body is removed in n8n on the next push
    Given a managed "sync" workflow file in "flows" tagged "flows", "linux", and "old"
    And the workflow in n8n is tagged "flows", "linux", and "old"
    When the admin edits the file body's "tags" array to "flows" and "linux"
    And the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows" and "linux"

  @unbuilt
  Scenario: Removing the mapping-tag from the file body does not unbind the workflow
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to only "linux"
    Then the file's Nextcloud system tags still include "flows"
    And the file stays mapped to "flows"

  # THE TWO SCENARIOS THAT MAKE THE SURFACE SAFE. Whichever of A/B above is built,
  # these are what prove it did not resurrect the false-removal bug. They are the
  # first tests to write, not the last.

  @unbuilt
  Scenario: A save that did not touch the tags array costs nothing
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the workflow's nodes and saves, leaving the tags array alone
    Then no tag call is made to n8n
    And the file's Nextcloud system tags are unchanged
    # The common case by far. It must be free — no getWorkflow, no setWorkflowTags.

  @unbuilt
  Scenario: A stale tags array never removes a pill the user just added
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    And the admin adds the Nextcloud system tag "urgent" to the file
    When the admin edits the workflow's nodes and saves, leaving the tags array alone
    Then the workflow in n8n is still tagged "flows", "linux", and "urgent"
    And the file still has the Nextcloud system tag "urgent"
    # The body's tags array still reads "flows, linux" — it lags by design, because a
    # pill edit does not rewrite the file. Reading it as truth here would push a
    # removal of the pill the user added seconds ago. THIS IS THE BUG THE WHOLE
    # marker-vs-lockstep decision exists to prevent (saga §5.6.3).

  # n8n's precedence, stated as behaviour rather than as a rule in a comment: with no
  # deliberate Nextcloud edit in play, a disagreement resolves toward n8n and the
  # file's copy loses. This is what "the file is a derived mirror" MEANS.
  @unbuilt
  Scenario: With no Nextcloud edit, a file that disagrees with n8n loses
    Given a managed "sync" workflow file in "flows" whose body's tags array reads "flows" and "linux"
    And the workflow in n8n is tagged "flows", "linux", and "prod"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are "flows", "linux", and "prod"
    And the file body's "tags" array becomes "flows", "linux", and "prod"

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

  @unbuilt
  Scenario: An unchanged workflow is skipped by the pull
    Given a managed "sync" workflow file in "flows" whose body and tags match n8n
    When the "flows" mapping is pulled
    Then the file is not rewritten
    And its Nextcloud system tags are unchanged

  @unbuilt
  Scenario: A content change pulls the new body and then reconciles the tags
    Given a managed "sync" workflow file in "flows" whose workflow body changed in n8n
    When the "flows" mapping is pulled
    Then the file body is updated from n8n
    And the file's Nextcloud system tags match the workflow's n8n tags

  @unbuilt
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

  @unbuilt
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

  @unbuilt
  Scenario: An optional catalog sweep keeps any tag still used on either side
    Given a non-reserved tag "shared" that is orphaned in Nextcloud
    But the tag "shared" is still on a workflow in n8n
    When an admin runs the optional catalog sweep
    Then the "shared" definition is kept on both sides

  @unbuilt
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
  @unbuilt
  Scenario: One workflow with two mapping tags is mirrored into both mapped folders
    Given a folder mapped as "sync" to the n8n tag "flows"
    And a folder mapped as "sync" to the n8n tag "reports"
    And n8n has one workflow tagged both "flows" and "reports"
    When both mappings are pulled
    Then the workflow appears as a file in the "flows" folder
    And the workflow appears as a file in the "reports" folder
    And both files carry the same workflow id

  @unbuilt
  Scenario: Editing tags on one mirror should converge its sibling (future fan-out)
    Given one n8n workflow mirrored as a file in both the "flows" and "reports" folders
    When the admin adds the Nextcloud system tag "urgent" to the "flows" mirror
    Then the workflow in n8n is tagged "flows", "reports", and "urgent"
    And the "reports" mirror should also show the "urgent" pill once its mapping next syncs
    # NOTE: same-request convergence of every mirror of one workflow id is not built
    # yet — for now the sibling catches up on its own next pull, and the app must not
    # bounce the agreed tag when it does.

  @unbuilt
  Scenario: A sibling mapping's tag is protected on every mirror (future cross-mapping guard)
    # On the "flows" mirror the "reports" tag is an ordinary content pill, not this
    # mapping's protected tag, so today a push could drop it and unbind the sibling.
    # The fix is a protected set that is the UNION of all mapping tags on the workflow.
    Given one n8n workflow mirrored as a file in both the "flows" and "reports" folders
    When the admin removes the "reports" pill from the "flows" mirror
    And the "flows" mapping is pushed
    Then the workflow in n8n still carries the "reports" tag
    And the "reports" mirror is still bound to its mapping
