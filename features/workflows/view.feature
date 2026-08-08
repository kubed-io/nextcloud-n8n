# Notes, decisions and history for this feature: ../AGENTS.md#workflowsview

Feature: Looking at a workflow file
  As someone with workflows mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as workflows rather than as anonymous JSON files

  Background:
    Given the app is connected to n8n

  # notes: ../AGENTS.md#view-workflow

  @user @ui
  Scenario: A mapped folder shows its workflows as workflows
    Given a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And n8n has workflows tagged "nextcloud:alpha"
    And the "nextcloud:alpha" mapping has been synced
    When the user views the contents of the mapped folder
    Then the mapped folder shows the workflows with the n8n icon
    # notes: ../AGENTS.md#a-mapped-folder-shows-its-workflows-as-workflows

  @user @dav
  Scenario Outline: Viewing the DAV properties on a file shows n8n specific details
    Given a mapping with the following values:
      | tag    | <tag>    |
      | mode   | <mode>   |
      | folder | <folder> |
    And a workflow "<workflow>" mirrored into that folder
    When a WebDAV client requests the file's properties
    Then the response carries the properties the app manages:
      | property                   | value             |
      | nc:metadata-n8n_id         | the workflow's id |
      | nc:metadata-n8n_mapping    | the mapping's id  |
      | nc:metadata-n8n_mode       | <stored mode>     |
      | nc:metadata-n8n_versionId  | set               |
      | nc:metadata-n8n_syncedHash | set               |

    Examples: both modes a mapping can hold
      | mode | stored mode | tag                 | folder    | workflow |
      | sync | sync        | nextcloud:view-sync | bananacat | fuzzler  |
      | link | reference   | nextcloud:view-link | applepie  | wobbler  |

    # notes: ../AGENTS.md#viewing-the-dav-properties-on-a-file-shows-n8n-specific-details

  @user @dav
  Scenario: What the app manages, only the app changes
    Given a managed workflow file
    When a client tries to change "nc:metadata-n8n_id" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties
    # notes: ../AGENTS.md#what-the-app-manages-only-the-app-changes

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
    Given a "sync" workflow file and a "link" workflow file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-n8n_mode" is "sync"
    Then only the sync file is returned
