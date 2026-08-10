# Notes, decisions and history for this feature: ../AGENTS.md#workflowsedit

Feature: Editing a workflow
  As someone who keeps workflows in Nextcloud
  I want an edit made on either side to reach the other
  So that the file I edited and the workflow it mirrors do not drift apart

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

    # ── RULE: an edit to a mirror is an edit to the workflow ───────────────────
    # notes: ../AGENTS.md#a-local-edit-reaches-its-workflow-in-n8n

  @user @in-nextcloud @gesture @ui
  Scenario: Editing a workflow file reaches its workflow in n8n
    Given a workflow file in "Automations"
    When I edit the file's nodes and save
    Then the workflow in n8n holds the file's nodes
    And the file holds this DAV metadata:
      | n8n_id         | the workflow's id  |
      | n8n_mapping    | the mapping's id   |
      | n8n_mode       | the mapping's mode |
      | n8n_versionId  | n8n's current one  |
      | n8n_syncedHash | the file's hash    |

  # notes: ../AGENTS.md#a-file-outside-every-mapping-is-never-pushed
  @user @in-nextcloud @gesture @ui
  Scenario: Editing a file outside every mapping reaches nothing
    Given an unmapped workflow file that still carries its "n8n_id"
    When I edit the file's nodes and save
    Then the workflow in n8n still holds the nodes it had
    And the workflow in n8n is "archived, hidden but preserved"

    # ── RULE: an edit made in n8n reaches the mirror, dates and all ────────────
    # notes: ../AGENTS.md#an-edit-made-in-n8n-reaches-the-mirror

  # notes: ../AGENTS.md#a-sync-holds-the-workflow-a-link-holds-a-pointer
  @n8n @in-n8n @ui @occ
  Scenario: Editing a workflow in n8n rewrites its sync mirror
    Given a workflow file in "Automations"
    When someone edits the workflow's nodes in n8n
    Then the file holds the workflow's nodes as n8n has them
    And the file holds this DAV metadata:
      | n8n_id         | the workflow's id                     |
      | n8n_mapping    | the mapping's id                      |
      | n8n_mode       | the mapping's mode                    |
      | n8n_versionId  | n8n's current one                     |
      | n8n_syncedHash | the file's hash                       |
      | Modified       | when the workflow last changed in n8n |

  @n8n @in-n8n @ui @occ
  Scenario: Editing a workflow in n8n leaves its link a pointer
    Given a workflow file in "Pointers"
    When someone edits the workflow's nodes in n8n
    Then the file holds a pointer:
      | $schema | n8n.reference/v1         |
      | id      | the workflow's id        |
      | name    | the workflow's name      |
      | url     | a deep link to it in n8n |
    And the file holds this DAV metadata:
      | n8n_id         | the workflow's id                     |
      | n8n_mapping    | the mapping's id                      |
      | n8n_mode       | the mapping's mode                    |
      | n8n_versionId  | n8n's current one                     |
      | n8n_syncedHash | the file's hash                       |
      | Modified       | when the workflow last changed in n8n |
