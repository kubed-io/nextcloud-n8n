# Deletion semantics differ by mode. Mirrors Nextcloud's two-step trash model.
# The matrix here is the contract the delete listener must satisfy.
# Modes (saga Chapter 3 §14): sync / link / unmapped. A file with NO n8n metadata is
# "untracked" (a plain document) — distinct from "unmapped" (a sync file moved out
# of its mapping that still carries its id + an archived n8n workflow).
# LIVE: delete/purge/restore go over WebDAV (incl. the trashbin DAV endpoint);
# DeleteToN8nListener runs synchronously, and the n8n side is asserted over REST.
#
# THE TWO STEPS ARRIVE THROUGH TWO DIFFERENT DOORS, and that is not a style choice:
#   - trash-move (soft) → `BeforeNodeDeletedEvent`, a typed event → DeleteToN8nListener
#   - purge     (hard)  → the legacy `\OCP\Trashbin` `preDelete` hook → TrashPurgeHook
# Nextcloud dispatches NO typed event for a purge. Assuming it did — and
# discriminating the two steps by path prefix — is what left purged workflows alive
# in n8n; see TrashPurgeHook's docblock for the full autopsy.
#
# A TRASH-BYPASSED DELETE ARCHIVES, IT DOES NOT DELETE. With the trashbin disabled
# (or `X-NC-Skip-Trashbin`) only the soft step ever fires, so a `sync` workflow is
# left archived. Deliberate: nothing at that point can tell "on its way to the
# trash" from "gone for good", and an archive that should have been a delete is
# recoverable while the reverse is not.
#
# ── RULE: TWO BINS, AND THEY ARE NOT SYMMETRIC ───────────────────────────────────
#
# Both systems have a reversible bin and an irreversible purge, so a workflow has
# two independent lifecycles that must be read as a PAIR:
#
#     Nextcloud     live file  →  trash        →  purged
#     n8n           live wf    →  archived     →  deleted
#
# The pairing is deliberate and holds in one direction only:
#
#   | Gesture                        | Nextcloud       | n8n                    |
#   |--------------------------------|-----------------|------------------------|
#   | delete a synced file           | → trash         | → archived             |
#   | restore it from the trash      | → live          | → unarchived           |
#   | purge it from the trash        | → gone          | → DELETED (best effort)|
#   | unarchive the workflow in n8n  | (nothing)       | → live                 |
#   | delete the workflow in n8n     | (nothing)       | → gone                 |
#
# NEXTCLOUD DRIVES; n8n DOES NOT DRIVE BACK. The bottom two rows are the asymmetry
# and they are the whole reason this table exists: an n8n-side bin change does NOT
# reach into Nextcloud's trash. Nextcloud's trash is the user's own undo history and
# this app does not reach into it, in either direction — the same "don't lose data"
# rule the purge honours by deleting only what the user themself purged.
#
# WHAT THAT COSTS, STATED PLAINLY: the pull cannot see into the trash at all
# (`SyncService` never mentions it), so a trashed file is invisible to a reconcile.
# Sometimes that is right and sometimes it is a gap, and the difference is the two
# @unbuilt scenarios below — the SAME blindness, benign in one and harmful in the
# other. Worth reading them together.

Feature: Deleting a workflow file
  As a Nextcloud user
  I want delete/trash/restore to do the right thing per mode
  So that removing a file never silently desyncs the two systems

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  @in-nextcloud @gesture @ui
  Scenario: Trashing a sync-mode file archives the workflow
    Given a managed "sync" workflow file
    When I move it to the trash
    Then the workflow is archived (hidden, preserved) in n8n

  # FIXED — AND THE @todo ABOVE HAD ALREADY NAMED BOTH CAUSES. This scenario sat
  # skipped behind a comment that guessed exactly right, twice: the trashbin
  # dispatches no typed Files event, AND the ".dNNNN" suffix defeats the
  # ".n8n.json" gate. Both were true, and either alone was enough to kill the leg.
  # Left as a guess for months, it meant a purged `sync` workflow stayed alive in
  # n8n forever with its file gone — a leak nobody goes looking for.
  # The purge now runs off the legacy `\OCP\Trashbin` `preDelete` hook
  # (TrashPurgeHook) and matches the trashed name with its timestamp suffix
  # (FilenameCodec::isTrashedWorkflowName). Live from here on.
  @in-nextcloud @gesture @ui
  Scenario: Purging a sync-mode file permanently deletes the workflow
    Given a trashed "sync" workflow file
    When I purge it from the trash
    Then the workflow is permanently deleted in n8n

  @in-nextcloud @gesture @ui
  Scenario: Restoring a sync-mode file unarchives the workflow
    Given a trashed "sync" workflow file
    When I restore it from the trash
    Then the workflow is unarchived in n8n

  @in-nextcloud @gesture @ui
  Scenario: Trashing a link only strips the mapping tag
    Given a managed "link" workflow file
    When I move it to the trash
    Then the mapping tag is stripped from the workflow in n8n
    And the workflow itself is not archived or deleted

  @in-nextcloud @gesture @ui
  Scenario: Deleting an untracked workflow file touches nothing in n8n
    Given an untracked ".n8n.json" file
    When I delete it
    Then n8n is not contacted

  # ── unmapped mode (a moved-out sync file: keeps its id, workflow archived) ────
  # Unmapped mode has landed (saga §14.2). An unmapped file's workflow is already
  # archived and has no live mapping, so trash and restore are both n8n no-ops:
  # softDelete/restore fall to the link branch with mapping=null and skip the call.
  # The "left as-is" assertion proves it — the workflow stays present and archived.
  @in-nextcloud @gesture @ui
  Scenario: Trashing an unmapped file is a no-op in n8n (already archived)
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move it to the trash
    Then the trash move succeeds
    And the archived workflow in n8n is left as-is

  # The listener half of this is now fixed (the purge fires — see the sync purge
  # above), but this leg still needs a DECISION, which is why it stays skipped:
  # `DeleteService::hardDelete` is a no-op for anything that is not `sync`, and an
  # unmapped file's mode is `unmapped`. So the hook reaches n8n and then declines
  # to act.
  #
  # The open question is whether it SHOULD act. An unmapped file's workflow is
  # already archived and belongs to no mapping — purging the last Nextcloud copy is
  # arguably the user saying "done with this", but it is also the one case where
  # Nextcloud destroys an n8n object it no longer owns. Not a bug to fix quietly.
  @in-nextcloud @gesture @ui @unbuilt
  Scenario: Purging an unmapped file permanently deletes the archived workflow
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I purge it from the trash
    Then the (archived) workflow is permanently deleted in n8n

  @in-nextcloud @gesture @ui
  Scenario: Restoring an unmapped file from trash touches nothing in n8n
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I restore it from the trash
    Then the archived workflow in n8n is left as-is

    # ══ THE OTHER BIN: CHANGES MADE ON THE n8n SIDE ════════════════════════════
    #
    # Everything above starts in Nextcloud. These start in n8n, and they are where
    # the pull's blindness to the Nextcloud trash actually bites. The pull indexes
    # `$folder->getDirectoryListing()`, and a trashed file is not in the folder —
    # so as far as a reconcile is concerned, a trashed mirror does not exist.

    # NOT WHAT HAPPENS TODAY. The pull finds no file for that id — the trashed one
    # is invisible to it — so it writes a BRAND-NEW file and leaves the trashed copy
    # orphaned. Restore that copy afterwards and TWO files carry the same id, which
    # is the duplicate the reconcile is otherwise careful to avoid.
    #
    # The fix is a trash-aware reconcile: before creating a file for an unseen id,
    # look for a trashed mirror carrying it and restore that instead. The sibling
    # app built exactly this (penpot saga §6.37); it is the piece n8n never got.
  @in-n8n @ui @occ @unbuilt
  Scenario: Unarchiving a workflow in n8n brings its file back out of the trash
    Given a trashed "sync" workflow file
    When the workflow is unarchived in n8n
    And the "nextcloud:alpha" mapping is pulled
    Then the file is back in its mapped folder
    And it holds the workflow's current content
    And only one file carries that workflow's id

    # NOT WHAT HAPPENS TODAY, and the fix is already written elsewhere.
    # `DeleteService::restore()` unarchives through `callIdempotent`, which treats
    # 404 as SUCCESS — so the file comes back carrying a dead id and nothing is
    # created. It is silently detached from n8n with no sign anything is wrong.
    #
    # `MotionService::moveIn()` handles the identical situation correctly: catch the
    # 404, create from the file's content, stamp the fresh id — and it is live at
    # move.feature ("Restoring when the n8n workflow was hard-deleted falls back to
    # create"). Restoring a file whose workflow is gone and moving one in whose
    # workflow is gone are the same problem; only the move path knows it.
  @in-nextcloud @gesture @ui @unbuilt
  Scenario: Restoring a file whose workflow was deleted in n8n gives it a new one
    Given a trashed "sync" workflow file
    And the workflow has been permanently deleted in n8n
    When I restore it from the trash
    Then the file is live in its mapped folder again
    And a workflow in n8n holds its content
    And the file points at that workflow

    # This one the app gets RIGHT — but for a weak reason, which is why it is worth
    # pinning. Nothing DECIDES to leave the orphan: the pull simply cannot see into
    # the trash, the same blindness that makes the first scenario above wrong. A
    # trash-aware reconcile must keep this behaviour deliberately rather than lose
    # it, because Nextcloud's trash is the user's undo history and an n8n-side purge
    # is not permission to empty it.
  @in-n8n @ui @occ @unbuilt
  Scenario: Deleting a workflow in n8n leaves an already-trashed file where it is
    Given a trashed "sync" workflow file
    And the workflow has been permanently deleted in n8n
    When the "nextcloud:alpha" mapping is pulled
    Then the file is still in the Nextcloud trash
    And nothing is restored or pruned because of it

    # The race the scheduled pull makes easy to hit: a user unarchives in n8n, then
    # restores in Nextcloud before a reconcile has run. Unarchiving an already-live
    # workflow must be a no-op, not an error — the same idempotency every other
    # write in this app relies on.
  @in-nextcloud @gesture @ui @unbuilt
  Scenario: Restoring a file whose workflow is already live again is not a conflict
    Given a trashed "sync" workflow file
    And the workflow is already live in n8n again
    When I restore it from the trash
    Then the file is live in its mapped folder again
    And the workflow in n8n is live exactly once
    # and the delete path already rely on.

  # @blocked, not @todo: the code exists (AbortedEventException aborts the NC
  # delete), but this harness has no way to make n8n unreachable for the duration
  # of one request — that is the missing capability, and naming it is what keeps
  # this out of the @todo work queue. A unit test against a mocked N8nClient is
  # the cheaper home if it is ever wanted.
  @in-nextcloud @gesture @ui @blocked
  Scenario: A delete is aborted if n8n is unreachable
    Given a managed "sync" workflow file
    And n8n is unreachable
    When I move it to the trash
    Then the delete is aborted and the file stays in Nextcloud
