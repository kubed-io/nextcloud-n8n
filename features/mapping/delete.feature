# Notes, decisions and history for this feature: ../AGENTS.md#mappingdelete

Feature: Removing a mapping tears down the connection without ever touching n8n
  As a Nextcloud admin
  I want removing a mapping to keep whatever each file's mode made worth keeping
  So that disconnecting the two sides can never cost me a workflow or a file

  Background:
    Given the app is connected to n8n
    And the following mappings were made:
      | tag   | folder      | mode | storage      |
      | alpha | Automations | sync | team folder  |
      | links | Pointers    | link | admin folder |

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: teardown keeps whatever the mode made worth keeping ─────────────
    # notes: ../AGENTS.md#removing-a-mapping-keeps-what-the-mode-made-worth-keeping

  @admin @in-nextcloud @occ @ui
  Scenario: Removing a sync mapping leaves its workflows behind, unmapped
    Given the following items in the mappings:
      | path                          |
      | /Automations/Fleet Health.n8n |
      | /Automations/Coast/Tides.n8n  |
    When the admin removes the "alpha" mapping
    Then the "alpha" mapping is no longer configured
    And "Automations" holds the same files it held before
    And "Automations/Coast/Tides.n8n" holds:
      | n8n_id      | the workflow's id |
      | n8n_mapping | cleared           |
      | n8n_mode    | unmapped          |
    And the workflows still carry the "alpha" tag in n8n
    And the "Automations" folder outlives the mapping

    # A sync file holds the workflow itself and may be the last copy of it.
    # Disconnecting is administrative; destroying an archive on the way past is not.

  @admin @in-nextcloud @occ @ui
  Scenario: Removing a link mapping takes its workflows with it
    Given the following items in the mappings:
      | path                        |
      | /Pointers/Pinned.n8n        |
      | /Pointers/Coast/Latency.n8n |
    When the admin removes the "links" mapping
    Then the "links" mapping is no longer configured
    And "Pointers" holds no workflow files
    And the workflows still carry the "links" tag in n8n
    And the "Pointers" folder outlives the mapping

    # A link is a pointer whose only meaning was the mapping, so once the mapping
    # is gone there is nothing left for it to be.
