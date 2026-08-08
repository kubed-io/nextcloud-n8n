# Notes, decisions and history for this feature: ../AGENTS.md#workflowsdelete

Feature: Deleting a workflow file
  As a Nextcloud user
  I want delete/trash/restore to do the right thing per mode
  So that removing a file never silently desyncs the two systems

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  @user @in-nextcloud @gesture @ui
  Scenario: Trashing a sync-mode file archives the workflow
    Given a managed "sync" workflow file
    When I move it to the trash
    Then the workflow is archived (hidden, preserved) in n8n

  # notes: ../AGENTS.md#purging-a-sync-mode-file-permanently-deletes-the-workflow
  @user @in-nextcloud @gesture @ui
  Scenario: Purging a sync-mode file permanently deletes the workflow
    Given a trashed "sync" workflow file
    When I purge it from the trash
    Then the workflow is permanently deleted in n8n

  @user @in-nextcloud @gesture @ui
  Scenario: Restoring a sync-mode file unarchives the workflow
    Given a trashed "sync" workflow file
    When I restore it from the trash
    Then the workflow is unarchived in n8n

  @user @in-nextcloud @gesture @ui
  Scenario: Trashing a link only strips the mapping tag
    Given a managed "link" workflow file
    When I move it to the trash
    Then the mapping tag is stripped from the workflow in n8n
    And the workflow itself is not archived or deleted

  @user @in-nextcloud @gesture @ui
  Scenario: Deleting an untracked workflow file touches nothing in n8n
    Given an untracked ".n8n.json" file
    When I delete it
    Then n8n is not contacted

  # notes: ../AGENTS.md#trashing-an-unmapped-file-is-a-no-op-in-n8n-already-archived
  @user @in-nextcloud @gesture @ui
  Scenario: Trashing an unmapped file is a no-op in n8n (already archived)
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move it to the trash
    Then the trash move succeeds
    And the archived workflow in n8n is left as-is

  # notes: ../AGENTS.md#purging-an-unmapped-file-permanently-deletes-the-archived-workflow
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Purging an unmapped file permanently deletes the archived workflow
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I purge it from the trash
    Then the (archived) workflow is permanently deleted in n8n

  @user @in-nextcloud @gesture @ui
  Scenario: Restoring an unmapped file from trash touches nothing in n8n
    Given a trashed unmapped workflow file that still carries its "n8n_id"
    When I restore it from the trash
    Then the archived workflow in n8n is left as-is

    # notes: ../AGENTS.md#restoring-an-unmapped-file-from-trash-touches-nothing-in-n8n

    # notes: ../AGENTS.md#unarchiving-a-workflow-in-n8n-brings-its-file-back-out-of-the-trash
  @n8n @in-n8n @ui @occ @unbuilt
  Scenario: Unarchiving a workflow in n8n brings its file back out of the trash
    Given a trashed "sync" workflow file
    When the workflow is unarchived in n8n
    And the "nextcloud:alpha" mapping is pulled
    Then the file is back in its mapped folder
    And it holds the workflow's current content
    And only one file carries that workflow's id

    # notes: ../AGENTS.md#restoring-a-file-whose-workflow-was-deleted-in-n8n-gives-it-a-new-one
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Restoring a file whose workflow was deleted in n8n gives it a new one
    Given a trashed "sync" workflow file
    And the workflow has been permanently deleted in n8n
    When I restore it from the trash
    Then the file is live in its mapped folder again
    And a workflow in n8n holds its content
    And the file points at that workflow

    # notes: ../AGENTS.md#deleting-a-workflow-in-n8n-leaves-an-already-trashed-file-where-it-is
  @n8n @in-n8n @ui @occ @unbuilt
  Scenario: Deleting a workflow in n8n leaves an already-trashed file where it is
    Given a trashed "sync" workflow file
    And the workflow has been permanently deleted in n8n
    When the "nextcloud:alpha" mapping is pulled
    Then the file is still in the Nextcloud trash
    And nothing is restored or pruned because of it

    # notes: ../AGENTS.md#deleting-a-workflow-in-n8n-leaves-an-already-trashed-file-where-it-is
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Restoring a file whose workflow is already live again is not a conflict
    Given a trashed "sync" workflow file
    And the workflow is already live in n8n again
    When I restore it from the trash
    Then the file is live in its mapped folder again
    And the workflow in n8n is live exactly once
    # and the delete path already rely on.

  # notes: ../AGENTS.md#a-delete-is-aborted-if-n8n-is-unreachable
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: A delete is aborted if n8n is unreachable
    Given a managed "sync" workflow file
    And n8n is unreachable
    When I move it to the trash
    Then the delete is aborted and the file stays in Nextcloud
