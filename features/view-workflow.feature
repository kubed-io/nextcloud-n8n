# Notes, decisions and history for this feature: AGENTS.md#view-workflow

Feature: Looking at a workflow file
  As someone with workflows mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as workflows rather than as anonymous JSON

  Background:
    Given the app is connected to n8n

  # THIS FILE REPLACED "n8n workflow is a first-class file type", which described a
  # CONSTRUCT — a mimetype, a property set, an index — rather than anything a
  # person does. Each of those is the end state of something else:
  #
  #   the mimetype being registered   is what ENABLING THE APP leaves behind
  #                                   -> lifecycle.feature
  #   the metadata on a file          is what CREATING or SYNCING one leaves behind
  #                                   -> asserted by those behaviours, and shown here
  #
  # What is left is the only part anyone actually performs: looking at the thing.
  # notes: AGENTS.md#view-workflow

  @user @ui
  Scenario: A mapped folder shows its workflows as workflows
    Given a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And n8n has workflows tagged "nextcloud:alpha"
    When the "nextcloud:alpha" mapping is synced
    Then the mapped folder shows the workflows with the n8n icon
    # ONE SCENARIO, DELIBERATELY. Behat cannot read rendered pixels, so the icon is
    # proven the only way it can be: the file carries the app's own mimetype rather
    # than application/json, and Nextcloud maps that mimetype to the app's glyph.
    # Elaborating past that would be testing Nextcloud's icon renderer.
    # notes: AGENTS.md#a-mapped-folder-shows-its-workflows-as-workflows

  @user @dav
  Scenario Outline: Viewing one file over DAV shows what the app manages
    Given a managed workflow file in "<mode>" mode
    When a WebDAV client requests the file's properties
    Then the file carries its n8n metadata
    And its "nc:metadata-n8n_mode" property is "<dav value>"

    Examples: every mode a file can be in
      | mode     | dav value |
      | sync     | sync      |
      | link     | reference |
      | unmapped | unmapped  |
      | ignored  | ignored   |

    # `link` stores as "reference" — the literal string "link" is `is_callable()`,
    # which crashes core's PROPFIND. That is the only place in this app where a
    # wire value differs from the name of the thing it carries.
    # notes: AGENTS.md#viewing-one-file-over-dav-shows-what-the-app-manages

  @user @dav
  Scenario: What the app manages, only the app changes
    Given a managed workflow file
    When a client tries to change "nc:metadata-n8n_id" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties
    # A REFUSAL SOMEONE CAN PROVOKE, so it earns a scenario: any DAV client can
    # attempt this. The identity of a mirror is the app's to write; a client that
    # could edit it could silently re-point a file at a different workflow.
    # notes: AGENTS.md#what-the-app-manages-only-the-app-changes

  # n8n_mode is indexed, so "find every sync / unmapped / ignored file" is a fast
  # query. @blocked, and the missing capability is named: there is no proven DAV
  # REPORT search over `nc:metadata-*` to drive this against. Confirm that exists
  # and this becomes an ordinary @todo.
  @user @dav @blocked
  Scenario: Finding workflows by their mode
    Given a "sync" workflow file and a "link" workflow file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-n8n_mode" is "sync"
    Then only the sync file is returned
