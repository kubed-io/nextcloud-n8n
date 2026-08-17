# Notes, decisions and history for this feature: ../AGENTS.md#mappingrename

Feature: Renaming a mapped folder
  As a Nextcloud admin
  I want renaming a mapped folder to change nothing about the mapping
  So that reorganising folders never quietly disconnects workflows from n8n

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | alpha        |
      | folder  | Automations  |
      | mode    | sync         |
      | storage | admin folder |
    And a mapping with the following values:
      | tag     | beta        |
      | folder  | Pipelines   |
      | mode    | sync        |
      | storage | team folder |
      | groups  | admin       |

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#a-mapping-follows-the-folder-it-was-pointed-at

    # ── RULE: a mapping holds the folder itself, so a rename reaches it ───────

  @admin @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Rename the mapped Nextcloud folder
    Given a workflow file in "<folder>"
    When the folder "<folder>" is renamed to "<renamed>"
    Then the mapping's folder is "<renamed>"
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id  |
      | n8n_mapping | the mapping's id   |
      | n8n_mode    | the mapping's mode |
    And nothing changes in n8n

    # Nothing is sent to n8n: the mapping names a TAG there, and the Nextcloud
    # folder it pairs with is the one it has always pointed at.

    Examples: the two places a mapped folder gets renamed
      | folder      | renamed    |
      | Automations | Blueprints |
      | Pipelines   | Runbooks   |
