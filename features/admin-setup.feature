# Stage 1 (saga §5): wire the connection config the admin UI would write, via occ,
# WITHOUT any authenticated call to n8n. Prereq for Stage 3's first real round-trip.
#
# Note: api_key is stored sensitive + ICrypto-encrypted; a plain occ value is NOT
# yet usable for auth — Stage 2 ("the token conversation") owns a decrypt-able key.
# This feature only proves the config plumbing.

Feature: Admin setup (no n8n calls)
  As a Nextcloud admin
  I want to configure the n8n connection via occ
  So that the app is ready before any sync runs

  Background:
    Given the app "n8n_sync" is enabled

  Scenario: Configure the n8n connection
    When I set app config "n8n_url" to "http://localhost:5678"
    And I set app config "api_enabled" to "1"
    And I set sensitive app config "api_key" to "placeholder-stage1"
    And I set app config "mappings" to:
      """
      [{"n8n_tag":"nextcloud:itest","team_folder":"itest","nc_groups":["admin"],"mode":"sync","writeback":"two-way","use_team_folder":true}]
      """
    Then app config "n8n_url" is "http://localhost:5678"
    And app config "api_enabled" is "1"
    And app config "mappings" contains "nextcloud:itest"
