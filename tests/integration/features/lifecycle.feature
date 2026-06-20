# Stage 0 (saga §5): the app installs and uninstalls cleanly on a real Nextcloud.
# A clean uninstall is also an app-store rule. No n8n contact.

Feature: App install lifecycle
  As a Nextcloud admin
  I want the n8n_sync app to enable and disable cleanly
  So that installing or removing it never leaves the instance broken

  Scenario: Enable the app
    When I run occ "app:enable --force n8n_sync"
    Then the occ command succeeds
    And the app "n8n_sync" should be enabled
    And the app "n8n_sync" path resolves

  Scenario: Disable the app
    Given the app "n8n_sync" is enabled
    When I run occ "app:disable n8n_sync"
    Then the occ command succeeds
    And the app "n8n_sync" should not be enabled
