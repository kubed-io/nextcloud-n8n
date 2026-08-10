# Notes, decisions and history for this feature: ../AGENTS.md#workflowsmove

Feature: Moving a workflow file is the same workflow leaving and returning
  As a Nextcloud user
  I want moves to mirror as the same workflow in n8n
  So that relocating a file never duplicates or silently desyncs a workflow

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | alpha        |
      | folder  | Automations  |
      | mode    | sync         |
      | storage | admin folder |
    And a mapping with the following values:
      | tag     | beta        |
      | folder  | Pipelines   |
      | mode    | sync        |
      | storage | team folder |
      | groups  | admin       |
    And a mapping with the following values:
      | tag     | links        |
      | folder  | Pointers     |
      | mode    | link         |
      | storage | admin folder |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a mapping owns its whole subtree, so moving inside it is nothing ──

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a file into a subfolder of its own mapping changes nothing
    Given a workflow file in "Automations"
    When I move the file into a subfolder of "Automations"
    Then the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | the mapping's id  |
      | n8n_mode    | the mapping's mode |
    And nothing changes in n8n

    # ── RULE: leaving a mapping ────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a sync file out of its mapping unmaps it and archives in n8n
    Given a workflow file in "Automations"
    When I move the file into "Scratch"
    Then the file holds this DAV metadata:
      | n8n_id        | the workflow's id |
      | n8n_versionId | set               |
      | n8n_mode      | unmapped          |
      | n8n_mapping   | cleared           |
    And the workflow is archived (hidden, preserved) in n8n
    And the full workflow JSON is still in the Nextcloud file

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a link out of its mapping is blocked
    Given a workflow file in "Pointers"
    When I try to move the file into "Scratch"
    Then the move is refused with a message
    And the file stays in "Pointers"

    # ── RULE: arriving in a mapping — the same workflow, or a new one ───────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving an unmapped file back into a mapping restores the workflow
    Given a workflow file in "Automations"
    And I have moved it out to "Scratch"
    When I move the file into "Pipelines"
    Then the workflow is unarchived in n8n
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | the mapping's id  |
      | n8n_mode    | the mapping's mode |

  # notes: ../AGENTS.md#restoring-when-the-n8n-workflow-was-hard-deleted-falls-back-to-create
  @user @in-nextcloud @gesture @ui
  Scenario: Restoring when the n8n workflow was hard-deleted falls back to create
    Given a workflow file in "Automations"
    And I have moved it out to "Scratch"
    And that workflow no longer exists in n8n
    When I move the file into "Pipelines"
    Then a matching workflow is created in n8n
    And the file holds this DAV metadata:
      | n8n_id      | its own, not the one it arrived with |
      | n8n_mapping | the mapping's id                     |
      | n8n_mode    | the mapping's mode                   |

  # notes: ../AGENTS.md#moving-a-duplicate-in-mints-a-brand-new-workflow
  @user @in-nextcloud @gesture @ui
  Scenario: Moving a duplicate in mints a brand-new workflow
    Given a workflow file in "Automations"
    And an unmapped copy of that same workflow with the same "n8n_id" in "Scratch"
    When I move the unmapped copy into "Automations" under a different name
    Then the file holds this DAV metadata:
      | n8n_id      | its own, not the one it arrived with |
      | n8n_mapping | the mapping's id                     |
      | n8n_mode    | the mapping's mode                   |
    And the original is unchanged

  # notes: ../AGENTS.md#the-tags-a-file-arrives-with-are-the-tags-its-workflow-ends-up-with
  @user @in-nextcloud @gesture @ui
  Scenario Outline: A file arriving from outside every mapping becomes a workflow there
    Given a workflow file in "Scratch" whose tags are "<tags>"
    When I move the file into "<destination>"
    Then a matching workflow is created in n8n
    And the file holds this DAV metadata:
      | n8n_id      | set                |
      | n8n_mapping | the mapping's id   |
      | n8n_mode    | the mapping's mode |
    And the workflow's normal tags are "<tags>" in n8n and in Nextcloud
    And the workflow carries the mapping's tag

    Examples: the tags it arrived carrying are the tags it ends up with
      | destination | tags                    |
      | Automations | prod, billing, critical |
      | Pipelines   | prod                    |
      | Pointers    |                         |

    # ── RULE: between two mappings, the binding follows the folder ─────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Moving a workflow to another mapped folder rebinds it
    Given a workflow file in "<source>"
    When I move the file into "<destination>"
    Then the file holds this DAV metadata:
      | n8n_id      | the workflow's id  |
      | n8n_mapping | the mapping's id   |
      | n8n_mode    | the mapping's mode |
    And the workflow carries the mapping's tag, and no other mapping's

    Examples: out of either kind of mapping, into either kind
      | source      | destination |
      | Automations | Pipelines   |
      | Pointers    | Automations |
      | Pointers    | Pipelines   |

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY.
    # notes: ../AGENTS.md#moving-a-workflow-to-another-mapped-folder
