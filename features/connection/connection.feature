# Notes, decisions and history for this feature: ../AGENTS.md#connectionconnection

Feature: Admin configures the n8n connection
  As a Nextcloud admin
  I want to point the app at my n8n and verify the connection
  So that every sync feature has a valid, tested connection to rely on

  Background:
    Given the app is installed and enabled

  @admin @ui
  Scenario: Set up and verify the connection
    When the admin sets the n8n base URL
    And the admin provides the n8n API key
    And the admin tests the connection
    Then the connection is verified

  # notes: ../AGENTS.md#the-connection-test-says-which-of-the-two-key-problems-it-is
  @admin @ui
  Scenario Outline: The connection test says which of the two key problems it is
    Given the admin has set the n8n base URL
    And <the key state>
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test says <the message>

    Examples: the two failures, which have different fixes
      | the key state          | the message           |
      | no API key is set      | the key is not set    |
      | an invalid API key is set | the key was rejected |
