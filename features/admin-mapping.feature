# Notes, decisions and history for this feature: AGENTS.md#admin-mapping

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map n8n tags to folders with a storage kind and a mode
  So that I can automate the admin connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled
    # WHAT AN UNSET FIELD BECOMES IS A FACT ABOUT THE FORM, not about one
    # scenario, so it is declared once here. Stated per-scenario it was silently
    # optional: a scenario asserting "unset fields at their defaults" without
    # declaring any would compare against whatever the step happened to assume.
    #
    # The tag and the folder are the only required fields — leaving either out is
    # a refusal, not a default, and the outline below proves it for each.
    And an unset field on the mapping form defaults to:
      | mode    | link        |
      | groups  |             |
      | storage | team folder |

    # A mapping is one fact, so it is one sentence plus a table of what is in it —
    # the same table whether it is pre-state or the action. A blank cell means the
    # admin left that field alone, so the app's own default applies.
    # notes: AGENTS.md#the-preconditions

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

    # notes: AGENTS.md#creating-a-mapping-saves-the-form

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

    # One scenario, not five: the behaviour is identical every time and the rules
    # are the Examples. Every row is reachable by a human typing into the form or
    # the occ argument.
    # notes: AGENTS.md#a-mapping-the-app-cannot-honour-is-refused-and-says-why

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
    # notes: AGENTS.md#an-n8n-tag-may-only-be-mapped-once

  @admin @occ @ui @unbuilt
  Scenario Outline: What a mapping locks, it locks for a reason
    Given a mapping with the following values:
      | tag    | nextcloud:alpha |
      | folder | alpha           |
    When the admin changes that mapping's <field> to "<value>"
    Then the change is rejected as immutable

    Examples: everything a mapping is, is fixed once it exists
      | field   | value           |
      | tag     | nextcloud:bravo |
      | folder  | elsewhere       |
      | mode    | sync            |
      | storage | admin folder    |

    # notes: AGENTS.md#what-a-mapping-locks-it-locks-for-a-reason

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
    # UNBUILT, AND THE GAP IS REAL: MappingService asserts the tag is unique and
    # says nothing about the folder, so today this is accepted and the two mappings
    # then prune each other's files forever.
    # notes: AGENTS.md#two-mappings-may-not-target-the-same-folder
