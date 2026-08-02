# "Admin makes a mapping" — the folder-mapping list in admin settings, driven
# over the CLI (the same operations the Settings panel performs). A mapping binds
# an n8n tag to a Nextcloud folder, with a storage kind (Team Folder vs
# admin-owned) and a mode. Modes are sync / link (saga Chapter 3 §14; "backup" was
# dropped, "unmapped" is a file state, never a mapping mode). This covers the full
# storage × mode matrix.

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map n8n tags to folders with a storage kind and a mode
  So that I can automate the admin connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled

  @admin @ui
  Scenario: Add the full storage × mode matrix
    When the admin adds these mappings:
      | n8n tag           | folder  | storage     | mode |
      | nextcloud:alpha   | alpha   | team folder | sync |
      | nextcloud:bravo   | bravo   | team folder | link |
      | nextcloud:charlie | charlie | admin       | sync |
      | nextcloud:delta   | delta   | admin       | link |
    Then there are 4 configured mappings
    And the mapping for tag "nextcloud:alpha" is a "team" folder in "sync" mode
    And the mapping for tag "nextcloud:bravo" is a "team" folder in "link" mode
    And the mapping for tag "nextcloud:charlie" is a "admin" folder in "sync" mode
    And the mapping for tag "nextcloud:delta" is a "admin" folder in "link" mode

  # New-model invariant (saga Chapter 3 §14): a mapping's mode is exactly sync or link.
  @admin @ui @occ
  Scenario: A mapping mode must be sync or link
    When the admin adds a mapping with an unknown mode for tag "nextcloud:bad"
    Then the mapping is rejected
