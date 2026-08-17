# Notes, decisions and history for this feature: ../AGENTS.md#mappingdelete

Feature: Removing a folder mapping
  As a Nextcloud admin
  I want removing a mapping to remove only the mapping
  So that disconnecting the two sides can never cost me a workflow or a file

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | alpha        |
      | folder  | Automations  |
      | mode    | sync         |
      | storage | admin folder |
    And a mapping with the following values:
      | tag     | links        |
      | folder  | Pointers     |
      | mode    | link         |
      | storage | admin folder |

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#removing-a-mapping-removes-only-the-mapping

    # ── RULE: the files stay, and become nobody's ─────────────────────────────

  @admin @in-nextcloud @occ @ui @unbuilt
  Scenario: Remove a sync mapping
    Given a workflow file in "Automations"
    When the admin removes the "alpha" mapping
    Then "Automations" holds the same files it held before
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | cleared           |
      | n8n_mode    | unmapped          |
    And nothing changes in n8n
    And there is exactly 1 configured mapping

    # It keeps its id because the workflow is still there. The file is simply no
    # longer claimed by anything, which is what an unmapped file is.

    # ── RULE: a link has nothing of its own, so it goes with its mapping ──────

  @admin @in-nextcloud @occ @ui @unbuilt
  Scenario: Remove a link mapping
    Given a workflow file in "Pointers"
    When the admin removes the "links" mapping
    Then "Pointers" holds no workflow files
    And nothing changes in n8n
    And there is exactly 1 configured mapping

    # A link is a pointer at something n8n owns. Without the mapping it points
    # nowhere, and there is no content to keep — so it goes, as if never written.
