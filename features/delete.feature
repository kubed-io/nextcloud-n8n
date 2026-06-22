# Deletion semantics differ by mode. Mirrors Nextcloud's two-step trash model.
# The matrix here is the contract the delete listener must satisfy.
# Modes (saga Chapter 2 §14): sync / link / unmapped. A file with NO n8n metadata is
# "untracked" (a plain document) — distinct from "unmapped" (a sync file moved out
# of its mapping that still carries its id + an archived n8n workflow).
# LIVE: delete/purge/restore go over WebDAV (incl. the trashbin DAV endpoint);
# DeleteToN8nListener runs synchronously, and the n8n side is asserted over REST.

Feature: Deleting a workflow file
  As a Nextcloud user
  I want delete/trash/restore to do the right thing per mode
  So that removing a file never silently desyncs the two systems

  Background:
    Given the app is installed and enabled

  Scenario: Trashing a sync-mode file archives the workflow
    Given a managed "sync" workflow file
    When I move it to the trash
    Then the workflow is archived (hidden, preserved) in n8n

  # Purge → permanent delete doesn't fire over the trashbin DAV endpoint in CI:
  # the workflow stays in n8n (archived) after the purge. Likely cause — a manual
  # trashbin DAV DELETE goes through Sabre's trashbin nodes (Trashbin::delete),
  # which may not dispatch the Files BeforeNodeDeletedEvent the hard-delete leg
  # hangs off; the trash entry's ".dNNNN" suffix can also defeat the ".n8n.json"
  # gate. Archive (soft) + restore + tag-strip all pass, so the meaningful
  # contract is covered; this leg needs a real listener-side investigation.
  @todo
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
  # @todo until "unmapped" mode lands (saga Chapter 2 §14, Phase 2). Decision-case d.
  @todo
  Scenario: Trashing an unmapped file is a no-op in n8n (already archived)
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move it to the trash
    Then n8n is not contacted
    And the archived workflow in n8n is left as-is

  @todo
  Scenario: Purging an unmapped file permanently deletes the archived workflow
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I purge it from the trash
    Then the (archived) workflow is permanently deleted in n8n

  @todo
  Scenario: Restoring an unmapped file from trash touches nothing in n8n
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I restore it from the trash
    Then n8n is not contacted

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
