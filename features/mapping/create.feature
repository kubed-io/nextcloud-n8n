# Notes, decisions and history for this feature: ../AGENTS.md#mappingcreate

Feature: Mapping an n8n tag to a Nextcloud folder
  As a Nextcloud admin
  I want to point an n8n tag at a Nextcloud folder with a mode
  So that its workflows mirror into Nextcloud, scriptably (e.g. from a k8s job)

  rules:
  - creating a mapping does not trigger a sync
  - creating a mapping creates its nextcloud folder if it doesn't exist at the moment of creation
  - if the folder is a team folder, the folder is created with the team folder api
  - a link mapping cannot hold workflow files, so unmapped ones already in the folder are purged on accept

  Background:
    Given the app is enabled
    And the n8n base URL points at the test instance
    And the admin has configured the API key

    # ── one fact, one table — the same shape as pre-state or as the action ─────
    # notes: ../AGENTS.md#the-preconditions

  @admin @occ @ui
  Scenario Outline: Creating a new mapping to an n8n tag
    Given the Nextcloud groups "devs" exists
    And an unset field on the mapping form defaults to:
      | mode    | link         |
      | groups  |              |
      | storage | admin folder |
    When the admin submits this mapping:
      | tag     | nextcloud:alpha |
      | folder  | <folder>        |
      | mode    | <mode>          |
      | groups  | <groups>        |
      | storage | <storage>       |
    Then the mapping matches the form, unset fields at their defaults

    Examples: one field at a time, and nothing else
      | folder      | mode | groups     | storage     |
      | Automations |      |            |             |
      | Automations | link |            |             |
      | Automations | sync |            |             |
      | Automations |      | admin      |             |
      | Automations |      | admin,devs |             |
      | Automations |      |            | team folder |

    Examples: and in combination
      | folder      | mode | groups     | storage      |
      | Automations | sync | admin,devs | team folder  |
      | Pipelines   | link | admin      | admin folder |

    # notes: ../AGENTS.md#creating-a-mapping-saves-the-form

  # notes: ../AGENTS.md#a-link-mapping-may-not-be-made-over-workflows-that-already-exist
  @admin @occ @ui
  Scenario: Mapping in link mode over a folder that already holds workflows
    Given a folder "Automations" already exists
    And an unmapped workflow file at "Automations/Drafts/Keeper.n8n"
    When the admin submits this mapping:
      | tag    | nextcloud:alpha |
      | folder | Automations     |
      | mode   | link            |
    And allows the existing unmapped workflows to be purged
    Then the mapping matches the form, unset fields at their defaults
    And no ".n8n" workflows exist under "/Automations" in Nextcloud
    And "Automations/Drafts/Keeper.n8n" left no trash entry

  # notes: ../AGENTS.md#an-n8n-tag-may-only-be-mapped-once
  @admin @occ @ui
  Scenario: An n8n tag may only be mapped once
    Given a mapping with the following values:
      | tag    | nextcloud:alpha |
      | folder | Automations     |
    When the admin submits this mapping:
      | tag    | nextcloud:alpha |
      | folder | Elsewhere       |
      | mode   | sync            |
    Then the mapping is rejected, explaining "already uses the n8n tag"

  # notes: ../AGENTS.md#a-nextcloud-folder-may-only-be-mapped-once
  @admin @occ @ui
  Scenario: A Nextcloud folder may only be mapped once
    Given a mapping with the following values:
      | tag    | nextcloud:alpha |
      | folder | Automations     |
    When the admin submits this mapping:
      | tag    | nextcloud:beta |
      | folder | Automations    |
      | mode   | link           |
    Then the mapping is rejected, explaining "already uses the Nextcloud folder"

  # notes: ../AGENTS.md#without-an-api-key-nothing-can-be-mapped
  @admin @occ @ui
  Scenario: Without an API key, nothing can be mapped
    Given no API key is configured
    When the admin submits this mapping:
      | tag    | nextcloud:alpha |
      | folder | Automations     |
    Then the mapping is rejected, explaining "An API key is not configured yet."
