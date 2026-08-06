# Notes, decisions and history for this feature: AGENTS.md#edit-workflow

Feature: Editing a workflow file
  As someone who keeps workflows in Nextcloud
  I want my edits to reach n8n
  So that the file I edited and the workflow it mirrors do not drift apart

  Background:
    Given the app is connected to n8n

  # EDITING IS THE BEHAVIOUR; THE PUSH IS HOW IT TRAVELS.
  #
  # This scenario used to live in a "Manual per-mapping sync" file as "the admin
  # clicks Sync to n8n", which described the button rather than what anyone was
  # trying to do. Nobody edits a workflow in order to press a button — they edit
  # it so n8n gets the change, and the app offers three ways for that to happen
  # (on save, on the button, on the schedule). Those are mechanisms; this is the
  # behaviour they serve.
  # notes: AGENTS.md#a-local-edit-reaches-its-workflow-in-n8n

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
    # Its own scenario rather than a second Then on the one above: "my edit
    # travels" and "a file I never mapped does not" are different promises, and a
    # reader looking for the second should not have to find it inside the first.
    # notes: AGENTS.md#a-file-outside-every-mapping-is-never-pushed
