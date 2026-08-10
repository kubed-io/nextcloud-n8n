# Notes, decisions and history for this feature: ../AGENTS.md#workflowsrestore

Feature: Restoring a workflow file from the trash
  As a Nextcloud user
  I want a restore to undo exactly what the trashing did
  So that changing my mind costs nothing on either side

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

    # ── RULE: a restore is the trashing, undone ────────────────────────────────
    # notes: ../AGENTS.md#a-restore-is-the-trashing-undone

  @user @in-nextcloud @gesture @ui
  Scenario: Restoring a sync file unarchives its workflow
    Given a workflow file in "Automations"
    And the file is in the trash
    When I restore it from the trash
    Then the workflow in n8n is "live, unarchived"
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id  |
      | n8n_mapping | the mapping's id   |
      | n8n_mode    | the mapping's mode |

  # notes: ../AGENTS.md#restoring-a-file-that-left-its-mapping-reaches-nothing
  @user @in-nextcloud @gesture @ui
  Scenario: Restoring a file that had already left its mapping reaches nothing
    Given an unmapped workflow file that still carries its "n8n_id"
    And the file is in the trash
    When I restore it from the trash
    Then the workflow in n8n is "archived, hidden but preserved"

    # ── RULE: the world may have moved while the file sat in the trash ─────────
    # notes: ../AGENTS.md#the-world-may-have-moved-while-the-file-sat-in-the-trash

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Restoring a file whose workflow was deleted in n8n gives it a new one
    Given a workflow file in "Automations"
    And the file is in the trash
    And the workflow has been permanently deleted in n8n
    When I restore it from the trash
    Then a matching workflow is created in n8n
    And the file holds this DAV metadata:
      | n8n_id      | its own, not the one it arrived with |
      | n8n_mapping | the mapping's id                     |
      | n8n_mode    | the mapping's mode                   |

  # notes: ../AGENTS.md#restoring-a-file-whose-workflow-is-already-live-again
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Restoring a file whose workflow is already live again is not a conflict
    Given a workflow file in "Automations"
    And the file is in the trash
    And the workflow is already live in n8n again
    When I restore it from the trash
    Then the workflow in n8n is "live, unarchived"
    And there is exactly one file for that workflow

  # notes: ../AGENTS.md#a-link-comes-back-when-its-workflow-does
  @n8n @in-n8n @ui @occ
  Scenario: Unarchiving the workflow in n8n brings its link back
    Given a workflow file in "Pointers"
    And someone has archived the workflow in n8n
    When someone unarchives the workflow in n8n
    Then the file is back in "Pointers"
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id  |
      | n8n_mapping | the mapping's id   |
      | n8n_mode    | the mapping's mode |

  # notes: ../AGENTS.md#unarchiving-a-workflow-in-n8n-brings-its-file-back-out-of-the-trash
  @n8n @in-n8n @ui @occ @unbuilt
  Scenario: Unarchiving a workflow in n8n brings its file back out of the trash
    Given a workflow file in "Automations"
    And the file is in the trash
    When someone unarchives the workflow in n8n
    Then the file is back in "Automations"
    And there is exactly one file for that workflow
