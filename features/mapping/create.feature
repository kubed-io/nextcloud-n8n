# Notes, decisions and history for this feature: ../AGENTS.md#mappingcreate

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map n8n tags to folders with a storage kind and a mode
  So that I can automate the admin connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled
    # notes: ../AGENTS.md#the-preconditions
    And an unset field on the mapping form defaults to:
      | mode    | link         |
      | groups  |              |
      | storage | admin folder |

    # notes: ../AGENTS.md#creating-a-mapping-saves-the-form

  @admin @occ @ui
  Scenario Outline: Creating a mapping saves the form
    Given no n8n tags are mapped
    When the admin maps the tag "nextcloud:alpha" with:
      | folder  | <folder>  |
      | mode    | <mode>    |
      | groups  | <groups>  |
      | storage | <storage> |
    Then the mapping matches the form, unset fields at their defaults

    # The storage x mode matrix, one row per combination.

    Examples: every storage, and every mode
      | folder  | mode | groups | storage      |
      | alpha   | sync |        | team folder  |
      | bravo   | link |        | team folder  |
      | charlie | sync |        | admin folder |
      | delta   | link |        | admin folder |

    Examples: and the two fields that have a default
      | folder  | mode | groups | storage |
      | echo    |      |        |         |
      | foxtrot | sync | admin  |         |

    # notes: ../AGENTS.md#creating-a-mapping-saves-the-form

  @admin @occ @ui
  Scenario Outline: A mapping the app cannot honour is refused, and says why
    Given no n8n tags are mapped
    When the admin maps the tag "<tag>" with:
      | folder | <folder> |
      | mode   | <mode>   |
    Then the mapping is rejected
    And the refusal explains "<reason>"
    And there are exactly 0 configured mappings

    Examples: every field that carries a rule of its own
      | tag             | folder | mode  | reason                    |
      |                 | alpha  | sync  | n8n_tag is required       |
      | one,two         | alpha  | sync  | must not contain commas   |
      | nextcloud:alpha |        | sync  | team_folder is required   |
      | nextcloud:alpha | alpha  | bogus | mode must be              |

    # notes: ../AGENTS.md#a-mapping-the-app-cannot-honour-is-refused-and-says-why

  @admin @occ @ui
  Scenario: An n8n tag may only be mapped once
    Given a mapping with the following values:
      | tag    | nextcloud:alpha |
      | folder | alpha           |
    When the admin maps the tag "nextcloud:alpha" with:
      | folder | elsewhere |
    Then the mapping is rejected
    And the refusal explains "already uses the n8n tag"
    And there is exactly 1 configured mapping
    # A tag is what a mapping IS, so mapping it twice would make two mappings mean
    # the same thing and every workflow carrying it would belong to both.
    # notes: ../AGENTS.md#an-n8n-tag-may-only-be-mapped-once


  # notes: ../AGENTS.md#a-folder-inside-another-mappings-folder-may-not-be-mapped
  @admin @occ @ui @unbuilt
  Scenario Outline: A folder inside another mapping's folder may not be mapped
    Given a mapping with the following values:
      | tag    | nextcloud:alpha |
      | folder | alpha           |
    When the admin maps the tag "nextcloud:beta" with:
      | folder | <folder> |
    Then the mapping is rejected
    And the refusal explains "already inside"
    And there is exactly 1 configured mapping

    Examples: at any depth, and in either direction
      | folder            |
      | alpha/nested      |
      | alpha/deep/nested |

  @admin @occ @ui @unbuilt
  Scenario: Two mappings may not target the same folder
    Given a mapping with the following values:
      | tag    | nextcloud:alpha |
      | folder | shared          |
    When the admin maps the tag "nextcloud:bravo" with:
      | folder | shared |
    Then the mapping is rejected
    And the refusal explains "already"
    And there is exactly 1 configured mapping
    # notes: ../AGENTS.md#two-mappings-may-not-target-the-same-folder
