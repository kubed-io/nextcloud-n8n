# Notes, decisions and history for this feature: AGENTS.md#mapping-membership

Feature: Mapping membership is resolved by folder
  As a Nextcloud admin
  I want mappings to be per-folder metadata
  So that membership is predictable and folders can nest

  # Same precondition as every other behavioural feature: the scenarios add a
  # mapping (needs the app enabled) and land a file in it, which fires the
  # create-on-land listener that registers the workflow in n8n and stamps the
  # n8n_mapping we assert on — so we need the full connection, not just enablement.
  Background:
    Given the app is connected to n8n

  @user @in-nextcloud @gesture @ui
  Scenario: A file's mapping is the folder it lives in
    Given a folder mapped to the n8n tag "nextcloud:demo"
    When a managed workflow file lives in that folder
    Then the file belongs to the "nextcloud:demo" mapping

  @user @in-nextcloud @gesture @ui
  Scenario: A file outside every mapped folder belongs to no mapping
    Given a folder that is not mapped
    When a workflow file lives in that folder
    Then the file belongs to no mapping
    And it is "untracked" if it has no n8n id, or "unmapped" if it carries one

  @admin @ui @occ
  Scenario: A sync never touches a file outside every mapping
    Given a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And an unmapped workflow file exists outside every mapping
    When the admin syncs every mapping
    Then the unmapped file is left untouched (it is outside the mapping's scope)
    # THE SCOPE OF A SYNC IS A MEMBERSHIP QUESTION, so it is answered here rather
    # than in a file about syncing. "Which files does this mapping own" is what
    # this file exists to say; a sync simply acts on that answer.
    #
    # It moved here from a scenario about the sync button, where it was one Then
    # among four — so "an unmapped file is out of scope" could only fail as part
    # of "the button worked", and never named itself.
    # notes: AGENTS.md#a-sync-never-touches-a-file-outside-every-mapping

  Scenario: Folder mappings are metadata, so a mapped folder can nest in another
    Given a folder mapped to the n8n tag "nextcloud:outer"
    And a subfolder of it mapped to the n8n tag "nextcloud:inner"
    When a workflow file lives in the subfolder
    Then it belongs to the "nextcloud:inner" mapping, not "nextcloud:outer"
    And the nearest enclosing mapping wins
