# Notes, decisions and history for this feature: ../AGENTS.md#workflowspurge

Feature: Emptying the trash is the only permanent delete
  As a Nextcloud user
  I want the workflow to go only when I say I mean it
  So that nothing is destroyed in n8n by a gesture I can still undo

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | nextcloud:alpha |
      | folder  | Automations     |
      | mode    | sync            |
      | storage | admin folder    |
    And a mapping with the following values:
      | tag     | nextcloud:links |
      | folder  | Pointers        |
      | mode    | link            |
      | storage | admin folder    |
    And a folder "Scratch" that is not mapped

    # ── RULE: only what Nextcloud owned is Nextcloud's to destroy ──────────────
    # notes: ../AGENTS.md#only-what-nextcloud-owned-is-nextclouds-to-destroy

  @user @in-nextcloud @gesture @ui
  Scenario: Purging a sync file permanently deletes its workflow
    Given a workflow file in "Automations"
    And the file is in the Nextcloud trash
    And the workflow is in n8n's archive
    When I purge it from the trash
    Then the workflow in n8n is "gone, permanently deleted"

  # notes: ../AGENTS.md#an-unmapped-file-is-just-a-file
  @user @in-nextcloud @gesture @ui
  Scenario: Purging a file that left its mapping leaves n8n alone
    Given an unmapped workflow file that still carries its "n8n_id"
    And the file is in the Nextcloud trash
    And the workflow is in n8n's archive
    When I purge it from the trash
    Then the workflow in n8n is "archived, hidden but preserved"

  # notes: ../AGENTS.md#a-workflow-deleted-in-n8n-purges-its-trashed-file
  @n8n @in-n8n @ui @occ
  Scenario: A workflow deleted in n8n purges its trashed file
    Given a workflow file in "Automations"
    And the file is in the Nextcloud trash
    And the workflow is in n8n's archive
    When the workflow is permanently deleted in n8n
    Then the file is gone from the Nextcloud trash
