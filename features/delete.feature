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

Feature: Deleting a workflow file
  As a Nextcloud user
  I want delete/trash/restore to do the right thing per mode
  So that removing a file never silently desyncs the two systems

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

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
  Scenario: Purging a sync-mode file permanently deletes the workflow
    Given a trashed "sync" workflow file
    When I purge it from the trash
    Then the workflow is permanently deleted in n8n

  Scenario: Restoring a sync-mode file unarchives the workflow
    Given a trashed "sync" workflow file
    When I restore it from the trash
    Then the workflow is unarchived in n8n

  Scenario: Trashing a link only strips the mapping tag
    Given a managed "link" workflow file
    When I move it to the trash
    Then the mapping tag is stripped from the workflow in n8n
    And the workflow itself is not archived or deleted

  Scenario: Deleting an untracked workflow file touches nothing in n8n
    Given an untracked ".n8n.json" file
    When I delete it
    Then n8n is not contacted

  # ── unmapped mode (a moved-out sync file: keeps its id, workflow archived) ────
  # Unmapped mode has landed (saga §14.2). An unmapped file's workflow is already
  # archived and has no live mapping, so trash and restore are both n8n no-ops:
  # softDelete/restore fall to the link branch with mapping=null and skip the call.
  # The "left as-is" assertion proves it — the workflow stays present and archived.
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
  @todo
  Scenario: Purging an unmapped file permanently deletes the archived workflow
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I purge it from the trash
    Then the (archived) workflow is permanently deleted in n8n

  Scenario: Restoring an unmapped file from trash touches nothing in n8n
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I restore it from the trash
    Then the archived workflow in n8n is left as-is

  # Error-path branch — documented but not wired. Forcing a real transport
  # failure mid-DELETE is brittle for an integration test; the cleaner home for
  # this is a unit test against a mocked N8nClient asserting AbortedEventException.
  # Left @todo (CI skips it) as a "bow on top" we can add later.
  @todo
  Scenario: A delete is aborted if n8n is unreachable
    Given a managed "sync" workflow file
    And n8n is unreachable
    When I move it to the trash
    Then the delete is aborted and the file stays in Nextcloud
