# Notes, decisions and history for this feature: AGENTS.md#view-workflow

Feature: Looking at a workflow file
  As someone with workflows mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as workflows rather than as anonymous JSON files

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
    And the "nextcloud:alpha" mapping has been synced
    When the user views the contents of the mapped folder
    Then the mapped folder shows the workflows with the n8n icon
    # ONE SCENARIO, DELIBERATELY. Behat cannot read rendered pixels, so the icon is
    # proven the only way it can be: the file carries the app's own mimetype rather
    # than application/json, and Nextcloud maps that mimetype to the app's glyph.
    # Elaborating past that would be testing Nextcloud's icon renderer.
    # notes: AGENTS.md#a-mapped-folder-shows-its-workflows-as-workflows

  @user @dav
  Scenario Outline: Viewing the DAV properties on a file shows n8n specific details
    Given a mapping with the following values:
      | tag     | <tag>        |
      | folder  | <folder>     |
      | mode    | <mode>       |
      | storage | admin folder |
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

    # `link` stores as "reference" — the literal string "link" is `is_callable()`,
    # which crashes core's PROPFIND. That is the only place in this app where a
    # wire value differs from the name of the thing it carries, so the row spells
    # out both: what the admin chose, and what a DAV client reads back.
    #
    # THE TABLE IS THE FIVE KEYS A MIRROR ARRIVES WITH — what `stampSynced` writes
    # when a file lands. `n8n_syncedTags` is managed too, but the tag reconciler
    # stamps it afterwards and only once there are content tags, so it is not part
    # of what viewing a fresh mirror shows.
    #
    # TWO ROWS, WHERE THERE USED TO BE FOUR. A mapping only ever produces `sync` or
    # `link`; `unmapped` and `ignored` are what a file BECOMES — by being moved out
    # of its folder, or hand-tagged `n8n:ignore` — so neither can be reached from a
    # mapping form, and neither belongs to a scenario shaped like one. Their DAV
    # values are asserted where those behaviours live: open-with.feature and
    # reserved-tags.feature.
    #
    # `set` means present and non-empty — the same claim penpot's file-type
    # scenarios make. A version id and a body hash are opaque by design; pinning a
    # literal would assert the sync engine's internals rather than the fact under
    # test, which is that the app publishes them and a client can read them.
    # notes: AGENTS.md#viewing-the-dav-properties-on-a-file-shows-n8n-specific-details

  @user @dav
  Scenario: What the app manages, only the app changes
    Given a managed workflow file
    When a client tries to change "nc:metadata-n8n_id" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties
    # A REFUSAL SOMEONE CAN PROVOKE, so it earns a scenario: any DAV client can
    # attempt this. The identity of a mirror is the app's to write; a client that
    # could edit it could silently re-point a file at a different workflow.
    # notes: AGENTS.md#what-the-app-manages-only-the-app-changes

  @admin @occ
  Scenario: Listing the workflows n8n holds
    Given n8n has workflows tagged "nextcloud:alpha"
    When the admin lists the workflows tagged "nextcloud:alpha"
    Then the listing names each of those workflows
    # THE OTHER WAY TO LOOK, and the one with no UI at all. `occ` reads n8n
    # directly rather than reading the mirror, which is what makes it useful when
    # the two disagree: "is it missing from the folder, or missing from n8n?" is
    # the first question anyone asks, and this answers the second half without
    # trusting the first.
    # notes: AGENTS.md#listing-the-workflows-n8n-holds

  @admin @occ
  Scenario: Viewing one workflow n8n holds
    Given n8n has workflows tagged "nextcloud:alpha"
    When the admin views one of those workflows by its id
    Then the workflow's JSON is printed
    # The id comes from the listing above — which is the whole reason the two are
    # here together rather than as one scenario about "the CLI".
    # notes: AGENTS.md#viewing-one-workflow-n8n-holds

  # n8n_mode is indexed, so "find every sync / unmapped / ignored file" is a fast
  # query. @blocked, and the missing capability is named: there is no proven DAV
  # REPORT search over `nc:metadata-*` to drive this against. Confirm that exists
  # and this becomes an ordinary @todo.
  @user @dav @blocked
  Scenario: Finding workflows by their mode
    Given a "sync" workflow file and a "link" workflow file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-n8n_mode" is "sync"
    Then only the sync file is returned
