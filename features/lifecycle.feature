# Notes, decisions and history for this feature: AGENTS.md#lifecycle

Feature: App install lifecycle
  As a Nextcloud admin
  I want the n8n_sync app to enable, disable and uninstall cleanly
  So that installing or removing it never leaves the instance broken

  @admin @ui
  Scenario: Enabling the app
    When the admin enables the app
    Then the app should be enabled
    And the app is installed correctly
    And ".n8n.json" files are registered as their own file type
    # notes: AGENTS.md#enabling-the-app

  @admin @ui
  Scenario: Disabling the app
    Given the app is enabled
    When the admin disables the app
    Then the app is not enabled

  # @blocked — no app removal. occ enables and disables; removing an app and
  # reinstalling it is a store operation this suite cannot perform.
  # notes: AGENTS.md#removing-the-app
  @admin @blocked
  Scenario: Removing the app
    Given the app is enabled
    When the admin removes the app
    Then ".n8n.json" files are not registered as their own file type
    And the managed workflow files are left where they are, with their metadata
