# Notes, decisions and history for this feature: AGENTS.md#uninstall

@blocked
Feature: Uninstall reverts the system and reinstall reconnects the data
  As a Nextcloud admin
  I want removing the app to leave Nextcloud clean and reinstalling to just resync
  So that uninstalling is safe and never costs me data or creates duplicates

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  # ── system cleanup (needs a live app remove — @todo in CI) ────────────────────
  @blocked
  Scenario: Removing the app reverts the custom mimetype registration
    Given the app registered the "application/n8n+json" mimetype on install
    When the app is removed
    Then the mimetype mapping for "n8n.json" is gone from the Nextcloud config
    And the n8n icon is removed from the core filetype icons
    And a ".n8n.json" file resolves to "application/json" again

  # ── data is orphaned, never deleted ───────────────────────────────────────────
  @admin @ui
  Scenario: Disabling the app leaves the workflow files (and their identity) in place
    Given the "nextcloud:alpha" folder has managed sync workflow files
    When the admin disables the app
    Then the ".n8n.json" files are still in the folder
    And each file still carries its "n8n_id" metadata

  # ── reinstall reconnects with no duplicates (the headline) ────────────────────
  @admin @ui @occ
  Scenario: Re-enabling and syncing reconciles the existing files without duplicates
    Given the "nextcloud:alpha" folder has managed sync workflow files
    And the admin disables and then re-enables the app
    When the admin clicks "Sync from n8n" for the "nextcloud:alpha" mapping
    Then each existing file is updated in place, matched by its "n8n_id"
    And no file gains a " (2)" collision-suffixed duplicate
