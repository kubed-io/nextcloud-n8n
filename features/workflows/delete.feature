# Notes, decisions and history for this feature: ../AGENTS.md#workflowsdelete

Feature: Trashing a workflow file
  As a Nextcloud user
  I want the trash to mean the same thing on both sides
  So that removing a file never silently desyncs the two systems

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
    And a folder "Scratch" that is not mapped

    # ── RULE: the trash is reversible, so trashing is too ──────────────────────
    # notes: ../AGENTS.md#the-trash-is-reversible-so-trashing-is-too

  @user @in-nextcloud @gesture @ui
  Scenario: Trashing a sync file archives its workflow
    Given a workflow file in "Automations"
    When I move it to the trash
    Then the workflow in n8n is "archived, hidden but preserved"

  # notes: ../AGENTS.md#trashing-a-link-leaves-its-workflow-alone
  @user @in-nextcloud @gesture @ui
  Scenario: Trashing a link leaves its workflow alone
    Given a workflow file in "Pointers"
    When I move it to the trash
    Then the workflow in n8n is "live and untouched"

  # notes: ../AGENTS.md#trashing-a-file-that-already-left-its-mapping-reaches-nothing
  @user @in-nextcloud @gesture @ui
  Scenario: Trashing a file that already left its mapping reaches nothing
    Given a workflow file in "Automations"
    And I have moved it out to "Scratch"
    When I move it to the trash
    Then the workflow in n8n is "archived, hidden but preserved"

  # notes: ../AGENTS.md#a-trash-is-aborted-if-n8n-is-unreachable
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: A trash is aborted if n8n is unreachable
    Given a workflow file in "Automations"
    And n8n is unreachable
    When I try to move it to the trash
    Then the trash is aborted and the file stays in Nextcloud
