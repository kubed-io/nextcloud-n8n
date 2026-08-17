# Notes, decisions and history for this feature: ../AGENTS.md#connectionsync-now

Feature: Syncing every mapping
  As a Nextcloud admin
  I want one sync, in either direction, across every mapping at once
  So that the mirror stays true without anyone tending it — and so I can declare
  Nextcloud the source of truth on the day something has gone wrong in n8n

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

    # ── RULE: the other direction — Nextcloud is declared the source of truth ──

  # notes: ../AGENTS.md#a-sync-to-n8n-makes-n8n-match-nextcloud
  @admin @occ @ui
  Scenario: A sync to n8n makes n8n match Nextcloud
    Given a folder mapped as "sync" to the n8n tag "nextcloud:echo"
    And its files hold nodes and tags that never reached n8n
    And one of its workflows was changed in n8n after its file was written
    When the admin syncs every mapping to n8n
    Then each workflow in n8n holds its file's nodes
    And each workflow in n8n carries its file's tags
    And each workflow in n8n still carries the mapping's tag
    And each file holds this DAV metadata:
      | n8n_id         | the workflow's id  |
      | n8n_mapping    | the mapping's id   |
      | n8n_mode       | the mapping's mode |
      | n8n_versionId  | n8n's current one  |
      | n8n_syncedHash | the file's hash    |
