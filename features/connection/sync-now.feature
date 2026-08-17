# Notes, decisions and history for this feature: ../AGENTS.md#connectionsync-now

Feature: Syncing every mapping
  As a Nextcloud admin
  I want one sync to bring every mapped folder up to date
  So that the mirror stays true without anyone tending it

  Background:
    Given the app is connected to n8n

  # ── one behaviour, two ways to start it across every mapping ───────────────
  # notes: ../AGENTS.md#sync-now-scope

  @admin @occ @ui
  Scenario Outline: A sync brings the tag's workflows into Nextcloud
    Given a folder mapped as "sync" to the n8n tag "<tag>"
    And n8n has workflows tagged "<tag>", each also carrying "urgent"
    When <actor> syncs <scope>
    Then each "<tag>" workflow appears as a file in the mapped folder
    And each file carries its n8n dates
    And each file carries its n8n metadata
    And each file carries its workflow's tags as Nextcloud tags

    Examples: both ways an instance-wide sync starts
      | actor        | scope         | tag               |
      | the admin    | every mapping | nextcloud:bravo   |
      | the schedule | every mapping | nextcloud:charlie |

    # notes: ../AGENTS.md#carries-its-n8n-dates

  @admin @ui @occ
  Scenario: A sync never touches a file outside every mapping
    Given a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And an unmapped workflow file exists outside every mapping
    When the admin syncs every mapping
    Then the unmapped file is left untouched (it is outside the mapping's scope)
    # notes: ../AGENTS.md#a-sync-never-touches-a-file-outside-every-mapping
