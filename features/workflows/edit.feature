# Notes, decisions and history for this feature: ../AGENTS.md#workflowsedit

Feature: Editing a workflow file
  As someone who keeps workflows in Nextcloud
  I want my edits to reach n8n
  So that the file I edited and the workflow it mirrors do not drift apart

  Background:
    Given the app is connected to n8n

  # notes: ../AGENTS.md#a-local-edit-reaches-its-workflow-in-n8n

  @admin @in-nextcloud @occ @ui
  Scenario: A local edit reaches its workflow in n8n
    Given a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And the "nextcloud:alpha" folder has sync workflow files with local changes
    When the admin pushes to n8n
    Then each sync file in the folder is pushed to its workflow in n8n

  @admin @in-nextcloud @occ @ui
  Scenario: A file outside every mapping is never pushed
    Given a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And the "nextcloud:alpha" folder has sync workflow files with local changes
    And an unmapped workflow file exists outside every mapping
    When the admin pushes to n8n
    Then the unmapped file is not pushed (it is outside the mapping's scope)
    # notes: ../AGENTS.md#a-file-outside-every-mapping-is-never-pushed
