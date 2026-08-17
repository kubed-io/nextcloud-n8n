# Notes, decisions and history for this feature: ../AGENTS.md#mappingsync-now

Feature: Syncing one mapping from its card
  As an admin who has just mapped a tag
  I want to sync that one mapping without touching the others
  So that a new mapping fills immediately and a busy instance is not re-walked

  Background:
    Given the app is connected to n8n

  # notes: ../AGENTS.md#syncing-one-mapping-fills-its-folder

  @admin @occ @ui
  Scenario: Syncing one mapping fills its folder
    Given a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And n8n has workflows tagged "nextcloud:alpha", each also carrying "urgent"
    When the admin syncs one mapping
    Then each "nextcloud:alpha" workflow appears as a file in the mapped folder
    And each file carries its n8n dates
    And each file carries its n8n metadata
    And each file carries its workflow's tags as Nextcloud tags

    # notes: ../AGENTS.md#carries-its-n8n-dates
