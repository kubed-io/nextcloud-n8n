# Notes, decisions and history for this feature: ../AGENTS.md#workflowsmove

Feature: Moving a workflow file is the same workflow leaving and returning
  As a Nextcloud user
  I want moves to mirror as the same workflow in n8n
  So that relocating a file never duplicates or silently desyncs a workflow

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag    | alpha       |
      | folder | Automations |
      | mode   | sync        |
    And a mapping with the following values:
      | tag    | beta      |
      | folder | Pipelines |
      | mode   | sync      |
    And a mapping with the following values:
      | tag    | links    |
      | folder | Pointers |
      | mode   | link     |

  # notes: ../AGENTS.md#the-mappings-in-the-background

  # ── within the same mapping: no n8n change ───────────────────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Move within the same mapping (rename) keeps it managed
    Given a managed "sync" workflow file in "Automations"
    When I rename the file within "Automations"
    Then the file stays in "sync" mode in "Automations"
    And nothing changes in n8n except the name

  @user @in-nextcloud @gesture @ui
  Scenario: Move into a subfolder of the same mapping keeps it managed
    Given a managed "sync" workflow file in "Automations"
    When I move the file into a subfolder of "Automations"
    Then the file stays in "sync" mode in "Automations"
    And nothing changes in n8n

  # ── sync move-out → unmapped + archived ──────────────────────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a sync file out of its mapping unmaps it and archives in n8n
    Given a managed "sync" workflow file in "Automations"
    When I move the file to a folder that is not mapped
    Then the file's mode becomes "unmapped"
    And the file keeps its "n8n_id" and "n8n_versionId"
    And the file's "n8n_mapping" is cleared
    And the workflow is archived (hidden, preserved) in n8n
    And the full workflow JSON is still in the Nextcloud file

  # ── move back in → restore (same workflow, not a new one) ────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving an unmapped file back into a mapping restores the workflow
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move the file into "Pipelines"
    Then the workflow is unarchived in n8n
    And the file's mode becomes "sync" in "Pipelines"
    And the "n8n_id" is unchanged

  @user @in-nextcloud @gesture @ui
  Scenario: Restoring when the n8n workflow was hard-deleted falls back to create
    Given an unmapped workflow file that still carries its "n8n_id"
    And that workflow no longer exists in n8n
    When I move the file into "Pipelines"
    Then a new workflow is created in n8n from the file
    And the file's mode becomes "sync" in "Pipelines"
    # notes: ../AGENTS.md#restoring-when-the-n8n-workflow-was-hard-deleted-falls-back-to-create

  # notes: ../AGENTS.md#moving-a-duplicate-in-under-the-same-name-is-refused-the-workflow-is-already-synced-here
  @user @in-nextcloud @gesture @ui
  Scenario: Moving a duplicate in under the same name is refused (the workflow is already synced here)
    Given a managed "sync" workflow file in "Automations"
    And an unmapped copy of that same workflow with the same "n8n_id" outside any mapping
    When I try to move the unmapped copy into "Automations" under the same name
    Then the move is refused with a message
    And the original synced file is unchanged

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a duplicate in under a different name mints a brand-new workflow
    Given a managed "sync" workflow file in "Automations"
    And an unmapped copy of that same workflow with the same "n8n_id" outside any mapping
    When I move the unmapped copy into "Automations" under a different name
    Then the moved-in file becomes a brand-new workflow in n8n
    And the original synced file is unchanged

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a brand-new workflow file into a mapping creates it
    Given a ".n8n.json" file that was never tracked in n8n
    When I move the file into "Automations"
    Then a matching workflow is created in n8n
    And the file's mode becomes "sync" in "Automations"
    # notes: ../AGENTS.md#moving-a-brand-new-workflow-file-into-a-mapping-creates-it

  # ── leaving a mapping: what is allowed, and what is not ──────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a link out of its mapping is blocked
    Given a managed "link" workflow file in "Pointers"
    When I try to move the file to a folder that is not mapped
    Then the move is refused with a message
    And the file stays in "Pointers"

  # ── moving between two mappings ──────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Moving a workflow to another mapped folder
    Given a managed "<mode>" workflow file in "<source folder>"
    When I move the file into "<destination folder>"
    Then the file no longer has the "<source tag>" tag in n8n nor Nextcloud
    And the file now has the "<destination tag>" tag in n8n and Nextcloud
    And the file's mapping id is updated to the "<destination folder>" mapping
    And the file's mode is "<mode>"

    Examples: out of either kind of mapping, into either kind
      | mode | source folder | source tag | destination folder | destination tag |
      | sync | Automations   | alpha      | Pipelines          | beta            |
      | link | Pointers      | links      | Automations        | alpha           |
      | link | Pointers      | links      | Pipelines          | beta            |

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY.
    # notes: ../AGENTS.md#moving-a-workflow-to-another-mapped-folder

  # ── relocating an already-unmapped file: pure relocation ─────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving an unmapped file between unmapped locations changes nothing
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move the file to another folder that is not mapped
    Then the file stays "unmapped"
    And its "n8n_id" and "n8n_versionId" are unchanged
    And nothing changes in n8n
    # notes: ../AGENTS.md#moving-an-unmapped-file-between-unmapped-locations-changes-nothing
