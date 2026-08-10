# Notes, decisions and history for this feature: ../AGENTS.md#workflowsrename

Feature: Renaming keeps the file, the JSON, and n8n in agreement
  As a Nextcloud user
  I want a rename made anywhere to reach the other two places
  So that the file name, its JSON name, and the n8n workflow name never drift

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | nextcloud:alpha |
      | folder  | Automations     |
      | mode    | sync            |
      | storage | admin folder    |
    And a mapping with the following values:
      | tag     | nextcloud:links |
      | folder  | Pointers        |
      | mode    | link            |
      | storage | admin folder    |

    # ── RULE: a name is one value living in three places ───────────────────────
    # notes: ../AGENTS.md#a-name-is-one-value-living-in-three-places

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming the file carries the new name into the JSON and n8n
    Given a workflow file named "Old Name.n8n.json" in "Automations"
    When I rename the file to "New Name.n8n.json"
    Then the name is "New Name" in the filename, the JSON, and n8n
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | the mapping's id  |

  @user @in-nextcloud @gesture @ui
  Scenario: Editing the name inside the file renames the file and the workflow
    Given a workflow file named "Old Name.n8n.json" in "Automations"
    When I change the JSON "name" field to "New Name"
    Then the name is "New Name" in the filename, the JSON, and n8n
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | the mapping's id  |

  # notes: ../AGENTS.md#a-rename-made-in-n8n-reaches-the-mirrored-file
  @n8n @in-n8n @occ @todo
  Scenario Outline: A rename made in n8n reaches the mirrored file
    Given a workflow file named "Old Name.n8n.json" in "<folder>"
    When the workflow is renamed to "New Name" in n8n
    Then the name is "New Name" in the filename, the JSON, and n8n
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | the mapping's id  |
    And there is exactly one file for that workflow

    Examples: a link's body is a pointer, but its NAME still mirrors
      | folder      |
      | Automations |
      | Pointers    |

    # ── RULE: a workflow always has a name, so the app never has to invent one ──
    # notes: ../AGENTS.md#a-workflow-always-has-a-name

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A rename to a blank name is refused
    Given a workflow file named "Old Name.n8n.json" in "Automations"
    When I rename the file to a name that is only whitespace
    Then the rename is refused
    And the name is "Old Name" in the filename, the JSON, and n8n

  @n8n @in-n8n @occ @todo
  Scenario: A workflow that arrives from n8n with no name is filed under its id
    Given n8n holds a workflow tagged "nextcloud:alpha" whose name is empty
    When the "nextcloud:alpha" mapping is synced
    Then the file is named after the workflow id
    # Falling back to the id is honest and reversible. Inventing "Untitled" would
    # collide the moment a second nameless workflow appeared.
