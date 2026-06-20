# The "admin makes the n8n connection" use case — the prerequisite to everything
# else (the app's "I'm logged in" gate). All existing functionality: the admin
# sets the n8n base URL, pastes the API key, enables the REST API, and clicks
# "Test connection" to confirm the URL + key are valid and the API is reachable.
#
# The asserted feature here is **Test connection** (occ n8n_sync:test-connection,
# the same N8nClient::ping() the admin button runs). Setting the URL / enabling /
# storing the key are prerequisites that get the connection configured; obtaining
# the key itself is out of scope (n8n's job) and handled as CI setup.

Feature: Admin configures the n8n connection
  As a Nextcloud admin
  I want to point the app at my n8n and verify the connection
  So that every sync feature has a valid, tested connection to rely on

  Background:
    Given the app "n8n_sync" is enabled

  Scenario: A configured connection passes the connection test
    Given the n8n base URL is set
    And the n8n API key is set
    And the REST API is enabled
    When I run the connection test
    Then the connection test succeeds

  Scenario: The connection test fails when the API key is wrong
    Given the n8n base URL is set
    And the n8n API key is set to "not-a-real-key"
    And the REST API is enabled
    When I run the connection test
    Then the connection test fails
