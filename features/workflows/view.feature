# Notes, decisions and history for this feature: ../AGENTS.md#workflowsview

Feature: Looking at a workflow file
  As someone with workflows mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as workflows rather than as anonymous JSON files

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

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#view-workflow

    # ── RULE: a mirror reads as a workflow, not as the JSON it happens to be ───

  @user @ui
  Scenario: A mapped folder shows its workflows as workflows
    Given a workflow file in "Automations"
    And a workflow file in "Automations"
    When I open "Automations" in the Files app
    Then the mapped folder shows the workflows with the n8n icon
    # notes: ../AGENTS.md#a-mapped-folder-shows-its-workflows-as-workflows

    # ── RULE: a client can read what the app knows about the file ──────────────

  @user @dav
  Scenario Outline: Viewing the DAV properties on a file shows n8n specific details
    Given a workflow file in "<folder>"
    When a WebDAV client requests the file's properties
    Then the file holds this DAV metadata:
      | n8n_id         | the workflow's id  |
      | n8n_mapping    | the mapping's id   |
      | n8n_mode       | the mapping's mode |
      | n8n_versionId  | set                |
      | n8n_syncedHash | set                |

    Examples: both modes a mapping can hold
      | folder      |
      | Automations |
      | Pointers    |

    # notes: ../AGENTS.md#viewing-the-dav-properties-on-a-file-shows-n8n-specific-details

    # ── RULE: what n8n holds is readable without a mirror ──────────────────────

  @admin @occ
  Scenario: Listing the workflows n8n holds
    Given n8n has workflows tagged "nextcloud:alpha"
    When the admin lists the workflows tagged "nextcloud:alpha"
    Then the listing names each of those workflows
    # notes: ../AGENTS.md#listing-the-workflows-n8n-holds

  @admin @occ
  Scenario: Viewing one workflow n8n holds
    Given n8n has workflows tagged "nextcloud:alpha"
    When the admin views one of those workflows by its id
    Then the workflow's JSON is printed
    # The id comes from the listing above — which is the whole reason the two are
    # here together rather than as one scenario about "the CLI".
    # notes: ../AGENTS.md#viewing-one-workflow-n8n-holds

  # notes: ../AGENTS.md#finding-workflows-by-their-mode
  @user @dav @blocked
  Scenario: Finding workflows by their mode
    Given a workflow file in "Automations"
    And a workflow file in "Pointers"
    When a DAV REPORT searches for files where "nc:metadata-n8n_mode" is "sync"
    Then only the file in "Automations" is returned
