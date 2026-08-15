# Notes, decisions and history for this feature: ../AGENTS.md#workflowscreate

Feature: Create a workflow from Nextcloud
  As a Nextcloud user
  I want to create n8n workflows by making files
  So that I can author workflows without opening the n8n UI

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | nextcloud:demo |
      | folder  | Demo           |
      | mode    | sync           |
      | storage | admin folder   |
    And a mapping with the following values:
      | tag     | nextcloud:pointers |
      | folder  | Pointers           |
      | mode    | link               |
      | storage | admin folder       |
    And a mapping with the following values:
      | tag     | nextcloud:shared |
      | folder  | Shared           |
      | mode    | sync             |
      | storage | team folder      |
      | groups  | admin            |
    And a folder "Scratch" that is not mapped

    # ── RULE: a workflow file in a mapped folder IS a workflow ─────────────────

  @user @in-nextcloud @gesture @ui
  Scenario Outline: New file in a mapped folder becomes a real workflow
    When I create a new ".n8n" file in "<folder>" via the Files "New" menu
    Then a matching workflow is created in n8n
    And the workflow carries the mapping's tag
    And the file holds this DAV metadata:
      | n8n_id         | the workflow's id  |
      | n8n_mapping    | the mapping's id   |
      | n8n_mode       | the mapping's mode |
      | n8n_versionId  | set                |
      | n8n_syncedHash | set                |

    Examples: the folder is the whole input — the Background said what each one is
      | folder   |
      | Demo     |
      | Pointers |
      | Shared   |

  @user @in-nextcloud @gesture @ui
  Scenario: A workflow file created outside any mapped folder stays unmanaged
    When I create a ".n8n" file in "Scratch"
    Then no workflow is created in n8n
    And the file holds no n8n DAV metadata at all
