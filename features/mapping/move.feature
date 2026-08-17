# Notes, decisions and history for this feature: ../AGENTS.md#mappingmove

Feature: Moving a mapped folder
  As a Nextcloud admin
  I want moving a mapped folder to change nothing about the mapping
  So that reorganising folders never quietly disconnects workflows from n8n

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | alpha        |
      | folder  | Automations  |
      | mode    | sync         |
      | storage | admin folder |
    And a folder "Archive" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#a-mapping-follows-the-folder-it-was-pointed-at

    # ── RULE: a mapping holds the folder itself, so a move reaches it ─────────

  @admin @in-nextcloud @gesture @ui @unbuilt
  Scenario: Move the mapped Nextcloud folder
    Given a workflow file in "Automations"
    When I move "Automations" into "Archive"
    Then the mapping's folder is "Archive/Automations"
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id  |
      | n8n_mapping | the mapping's id   |
      | n8n_mode    | the mapping's mode |
    And nothing changes in n8n

    # Nothing is sent to n8n: the mapping names a TAG there, and the Nextcloud
    # folder it pairs with is the one it has always pointed at.

    # ── RULE: a folder that is gone is gone, not re-adopted by name ───────────

  # notes: ../AGENTS.md#a-mapped-folder-that-was-deleted-is-not-re-adopted-by-name
  @admin @in-nextcloud @gesture @ui @unbuilt
  Scenario: A new folder reusing the mapped folder's name is not adopted
    Given a workflow file in "Automations"
    And "Automations" is in the Nextcloud trash
    When I create the folder "Automations"
    Then the mapping does not resolve to the new "Automations"
    And a workflow file created in the new "Automations" is not managed

    # A folder that merely shares the name is a different folder, and adopting it
    # would point the mapping at something nobody chose.
