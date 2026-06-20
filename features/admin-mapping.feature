# "Admin makes a mapping" — the folder-mapping list in admin settings, driven
# over the CLI (occ n8n_sync:add-mapping / list-mappings / remove-mapping), the
# same operations the Settings panel performs. A mapping binds an n8n tag to a
# Nextcloud folder with a storage kind (Team Folder vs admin-owned) and a mode
# (sync / backup / link). Covers a representative spread of the matrix, not every
# combination.
#
# Modes map to the data model: link = reference; sync = sync+two-way;
# backup = sync+readonly (README "Sync Modes").

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to manage n8n tag → folder mappings from the CLI
  So that I can automate the admin connection + mappings (e.g. in k8s)

  Background:
    Given the app "n8n_sync" is enabled

  Scenario: Add a representative spread of mappings
    When I add a mapping:
      """
      {"n8n_tag":"nextcloud:alpha","team_folder":"alpha","nc_groups":["admin"],"mode":"sync","writeback":"two-way","use_team_folder":true}
      """
    And I add a mapping:
      """
      {"n8n_tag":"nextcloud:bravo","team_folder":"bravo","nc_groups":["admin"],"mode":"reference","use_team_folder":true}
      """
    And I add a mapping:
      """
      {"n8n_tag":"nextcloud:charlie","team_folder":"charlie","nc_groups":["admin"],"mode":"sync","writeback":"readonly","use_team_folder":false}
      """
    And I add a mapping:
      """
      {"n8n_tag":"nextcloud:delta","team_folder":"delta","nc_groups":["admin"],"mode":"reference","use_team_folder":false}
      """
    Then the configured mappings include the tag "nextcloud:alpha"
    And the configured mappings include the tag "nextcloud:charlie"
    And there are 4 configured mappings

  Scenario: A sync mapping must declare a writeback mode
    When I try to add a mapping:
      """
      {"n8n_tag":"nextcloud:bad","team_folder":"bad","nc_groups":["admin"],"mode":"sync","use_team_folder":true}
      """
    Then the mapping is rejected

  Scenario: A reference (link) mapping must not declare a writeback
    When I try to add a mapping:
      """
      {"n8n_tag":"nextcloud:bad","team_folder":"bad","nc_groups":["admin"],"mode":"reference","writeback":"two-way","use_team_folder":true}
      """
    Then the mapping is rejected
