# Deletion semantics differ by mode. Mirrors Nextcloud's two-step trash model.
# The matrix here is the contract the delete listener must satisfy.
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

  Scenario: Purging a sync-mode file permanently deletes the workflow
    Given a trashed "sync" workflow file
    When I purge it from the trash
    Then the workflow is permanently deleted in n8n

  Scenario: Restoring a sync-mode file unarchives the workflow
    Given a trashed "sync" workflow file
    When I restore it from the trash
    Then the workflow is unarchived in n8n

  Scenario Outline: Backup and link modes only manage the mapping tag
    Given a managed "<mode>" workflow file
    When I move it to the trash
    Then the mapping tag is stripped from the workflow in n8n
    And the workflow itself is not archived or deleted

    Examples:
      | mode   |
      | backup |
      | link   |

  Scenario: Deleting an unmapped workflow file touches nothing in n8n
    Given an unmapped ".n8n.json" file
    When I delete it
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
