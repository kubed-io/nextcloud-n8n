# "Admin makes a mapping" — the folder-mapping list in admin settings, driven
# over the CLI (the same operations the Settings panel performs). A mapping binds
# an n8n tag to a Nextcloud folder, with a storage kind (Team Folder vs
# admin-owned) and a mode (sync / backup / link). This covers a representative
# spread of the matrix, not every combination.

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map n8n tags to folders with a storage kind and a mode
  So that I can automate the admin connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled

  Scenario: Add a representative spread of mappings
    When the admin adds these mappings:
      | n8n tag           | folder  | storage     | mode   |
      | nextcloud:alpha   | alpha   | team folder | sync   |
      | nextcloud:bravo   | bravo   | team folder | link   |
      | nextcloud:charlie | charlie | admin       | backup |
      | nextcloud:delta   | delta   | admin       | link   |
    Then there are 4 configured mappings
    And the mapping for tag "nextcloud:alpha" is a "team" folder in "sync" mode
    And the mapping for tag "nextcloud:charlie" is a "admin" folder in "backup" mode
    And the mapping for tag "nextcloud:delta" is a "admin" folder in "link" mode

  Scenario: A sync mapping must declare whether it writes back
    When the admin adds a sync mapping with no writeback for tag "nextcloud:bad"
    Then the mapping is rejected

  Scenario: A link mapping cannot also write back
    When the admin adds a link mapping that also writes back for tag "nextcloud:bad"
    Then the mapping is rejected
