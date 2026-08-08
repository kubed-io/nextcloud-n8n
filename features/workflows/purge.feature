# Notes, decisions and history for this feature: ../AGENTS.md#workflowspurge

Feature: Purge the app's restorable files from Nextcloud
  As a Nextcloud admin
  I want a button that removes the workflow files this app created
  So that I can reset the Nextcloud side without ever touching n8n or losing standalone files

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  @admin @ui @occ
  Scenario: Purge deletes the synced file but leaves its workflow in n8n and the mapping intact
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    When the admin purges the Nextcloud files
    Then no managed workflow files remain in the "nextcloud:alpha" folder
    And the workflow still exists in n8n
    And the "nextcloud:alpha" mapping is still configured

  @admin @ui @occ
  Scenario: Purge keeps an unmapped file — a standalone copy is never lost
    Given an unmapped workflow file that still carries its "n8n_id"
    And I remember the unmapped file
    And a managed "sync" workflow file in the "nextcloud:alpha" folder
    When the admin purges the Nextcloud files
    Then no managed workflow files remain in the "nextcloud:alpha" folder
    And the remembered file is left in place

  @admin @ui @occ
  Scenario: Sync from n8n brings the file back after a purge
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    And the admin purges the Nextcloud files
    When the "nextcloud:alpha" mapping is synced
    Then the workflow appears again as a file in the "nextcloud:alpha" folder

  # notes: ../AGENTS.md#purge-keeps-an-ignored-file
  @admin @ui @occ
  Scenario: Purge keeps an ignored file
    Given a managed "ignored" workflow file in the "nextcloud:alpha" folder
    When the admin purges the Nextcloud files
    Then that ignored file is left in place
