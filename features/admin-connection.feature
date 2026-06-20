# The "admin makes the n8n connection" use case — the app's "I'm logged in" gate,
# a prerequisite to every other feature. The admin points the app at n8n (base
# URL), provides the API key, enables the REST API, and tests the connection to
# confirm the URL + key are valid and n8n is reachable.
#
# (Obtaining the API key is out of the app's scope — that's the n8n admin's job;
# in the tests it's provided as setup.)

Feature: Admin configures the n8n connection
  As a Nextcloud admin
  I want to point the app at my n8n and verify the connection
  So that every sync feature has a valid, tested connection to rely on

  Background:
    Given the app is installed and enabled

  Scenario: Set up and verify the connection
    When the admin sets the n8n base URL
    And the admin provides the n8n API key
    And the admin enables the REST API
    And the admin tests the connection
    Then the connection is verified

  Scenario: Testing fails when the API key is wrong
    Given the admin has set the n8n base URL and enabled the REST API
    When the admin provides an invalid API key
    And the admin tests the connection
    Then the connection test reports a failure
