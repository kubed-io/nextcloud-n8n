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

  # notes: ../AGENTS.md#a-link-cannot-be-deleted-from-nextcloud
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Deleting a link is refused
    Given a workflow file in "Pointers"
    When I try to move it to the trash
    Then the delete is refused with a message
    And the file stays in "Pointers"
    And the workflow in n8n is "live and untouched"

  # notes: ../AGENTS.md#a-link-leaves-when-its-workflow-does
  @n8n @in-n8n @ui @occ @unbuilt
  Scenario: Archiving a workflow in n8n removes its link entirely
    Given a workflow file in "Pointers"
    When someone archives the workflow in n8n
    Then the file is gone from "Pointers"
    And the file is not in the Nextcloud trash

  # notes: ../AGENTS.md#a-trash-is-aborted-if-n8n-is-unreachable
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: A trash is aborted if n8n is unreachable
    Given a workflow file in "Automations"
    And n8n is unreachable
    When I try to move it to the trash
    Then the trash is aborted and the file stays in Nextcloud
