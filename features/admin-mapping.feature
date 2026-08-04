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

  @decision
  Scenario: There is no way to change a mapping except its groups
    # @decision, NOT @unbuilt: there is no operation here to test, and that is the
    # whole design. Immutability is not enforced by guards that reject a change —
    # it is enforced by the API SHAPE. `MappingService::updateGroups()` takes an id
    # and groups, the PUT endpoint takes `nc_groups` and nothing else, and there is
    # no update command at all. A caller cannot express a change to the tag, the
    # folder, the storage backend or the mode, so there is no rejection to observe.
    #
    # This used to be four `When`s in a row against a full-mapping update() that
    # guarded exactly ONE field — so the tag, the folder and the mode really were
    # editable, and the card PUT all of them on every save.
    #
    # To change any of them: remove the mapping and add it again. That makes the
    # migration cost visible rather than hiding it behind a dropdown.
    # notes: AGENTS.md#there-is-no-way-to-change-a-mapping-except-its-groups

  @admin @occ @ui
  Scenario Outline: The groups a mapped folder is shared with can be changed
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | tag     | nextcloud:alpha |
      | folder  | <folder>        |
      | groups  | design,admin    |
      | storage | <storage>       |
    When the admin changes that mapping's groups to "<groups>"
    Then the mapping's groups are "<groups>"

    # THE FOLDER NAME DIFFERS PER STORAGE KIND ON PURPOSE. Removing a mapping
    # deletes nothing, so a folder outlives the mapping that made it — and a later
    # row reusing the name would inherit a folder of the wrong kind.

    Examples: on a Team Folder
      | folder                  | storage      | groups             |
      | Groups On A Team Folder | team folder  | design,admin,sales |
      | Groups On A Team Folder | team folder  | design             |
      | Groups On A Team Folder | team folder  | sales              |
      | Groups On A Team Folder | team folder  |                    |

    Examples: and on an admin-owned folder
      | folder                   | storage      | groups             |
      | Groups On A Plain Folder | admin folder | design,admin,sales |
      | Groups On A Plain Folder | admin folder | design             |
      | Groups On A Plain Folder | admin folder | sales              |
      | Groups On A Plain Folder | admin folder |                    |

    # NARROWING AND CLEARING ARE THE POINT. The old code only ever added: it wrote
    # the listed groups and left the rest alone, so a group could be granted and
    # never revoked, and "set the groups to nothing" silently did nothing at all.
    # notes: AGENTS.md#the-groups-a-mapped-folder-is-shared-with-can-be-changed

  @admin @occ @ui @team-folder
  Scenario: Groups are read from the folder, not from the mapping
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | tag     | nextcloud:alpha  |
      | folder  | Shared Elsewhere |
      | groups  | design           |
      | storage | team folder      |
    When the Team Folder "Shared Elsewhere" is shared with the group "sales" outside this app
    Then the mapping's groups are "design,sales"
    # THE REASON THE WHOLE CHANGE EXISTS. Three apps in this family can map to one
    # folder; while each stored its own list, every sync stamped that list over the
    # others' and they fought forever, none of them wrong. Reading the groups off
    # the folder makes the folder the single answer, so all three — and the Files
    # UI, and occ — can edit the same sharing without contending.
    # notes: AGENTS.md#groups-are-read-from-the-folder-not-from-the-mapping

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
