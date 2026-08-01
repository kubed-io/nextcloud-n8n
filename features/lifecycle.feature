# Stage 0 (saga §5): the app installs and uninstalls cleanly on a real Nextcloud.
# A clean uninstall is also an app-store rule. No n8n contact.

Feature: App install lifecycle
  As a Nextcloud admin
  I want the n8n_sync app to enable and disable cleanly
  So that installing or removing it never leaves the instance broken

  @admin
  Scenario: Enabling the app
    When the admin enables the app
    Then the app should be enabled
    And the app is installed correctly

  @admin
  Scenario: Disabling the app
    Given the app is enabled
    When the admin disables the app
    Then the app is not enabled
