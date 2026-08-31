# Notes, decisions and history for this feature: ../AGENTS.md#workflowscreate

Feature: Creating a workflow, from either side
  As someone who works in both Nextcloud and n8n
  I want a new workflow to exist on both sides however it was made
  So that where I happened to create it is not a decision I have to make

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
      | Shared   |

  # notes: ../AGENTS.md#a-link-mapping-authors-nothing
  @user @in-nextcloud @gesture @ui
  Scenario: Creating a workflow in a link-mapped folder is refused
    When I try to create a new ".n8n" file in "Pointers" via the Files "New" menu
    Then the creation is refused with a message

    # A link folder is filled from its tag in n8n, so authoring into one could never
    # produce the workflow it looks like — the rule copy and move already enforce.

  @user @in-nextcloud @gesture @ui
  Scenario: A workflow file created outside any mapped folder stays unmanaged
    When I create a ".n8n" file in "Scratch"
    Then no workflow is created in n8n
    And the file holds no n8n DAV metadata at all

    # ── RULE: a tagged workflow in n8n IS a file ──────────────────────────────
    # notes: ../AGENTS.md#a-tagged-workflow-in-n8n-is-a-file

  @n8n @in-n8n @gesture @ui
  Scenario Outline: New workflow tagged in n8n becomes a real file
    When someone creates a workflow in n8n
    And someone tags it "<tag>" in n8n
    Then a matching file is created in "<folder>"
    And the file holds this DAV metadata:
      | n8n_id         | the workflow's id |
      | n8n_mapping    | the mapping's id  |
      | n8n_mode       | <mode>            |
      | n8n_versionId  | set               |
      | n8n_syncedHash | set               |

    Examples: every kind of mapping a tag can belong to
      | tag                | folder   | mode |
      | nextcloud:demo     | Demo     | sync |
      | nextcloud:pointers | Pointers | link |
      | nextcloud:shared   | Shared   | sync |
