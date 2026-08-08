# Notes, decisions and history for this feature: ../AGENTS.md#workflowsrename

Feature: Renaming keeps file, JSON, and n8n in agreement
  As a Nextcloud user
  I want renames to propagate everywhere
  So that the file name, its JSON name, and the n8n workflow name never drift

  Background:
    Given the app is installed and enabled

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming the file updates the backend JSON name and n8n
    Given a managed "sync" workflow file named "Old Name.n8n.json"
    When I rename the file to "New Name.n8n.json"
    Then the JSON "name" field inside the file becomes "New Name"
    And the workflow is renamed to "New Name" in n8n

  @user @in-nextcloud @gesture @ui
  Scenario: Editing the JSON name renames the file and updates n8n
    Given a managed "sync" workflow file
    When I edit the file and change the JSON "name" field to "Renamed In JSON"
    Then the file is renamed to "Renamed In JSON.n8n.json"
    And the workflow is renamed to "Renamed In JSON" in n8n

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming never breaks the link
    Given a managed "sync" workflow file with a known "n8n_id"
    When the file is renamed by any of the above means
    Then the "n8n_id" metadata is unchanged

    # notes: ../AGENTS.md#renaming-a-workflow-in-n8n-renames-the-mirrored-file

  @n8n @in-n8n @occ @todo
  Scenario: Renaming a workflow in n8n renames the mirrored file
    Given a managed "sync" workflow file named "Old Name.n8n.json"
    When the workflow is renamed to "New Name" in n8n
    And the "nextcloud:alpha" mapping is pulled
    Then the file is renamed to "New Name.n8n.json"
    And the "n8n_id" metadata is unchanged
    And no second file is created for the same workflow

  @n8n @in-n8n @occ @todo
  Scenario: A rename in n8n reaches a link the same way
    Given a managed "link" workflow file named "Old Name.n8n.json"
    When the workflow is renamed to "New Name" in n8n
    And the "nextcloud:links" mapping is pulled
    Then the file is renamed to "New Name.n8n.json"
    # A link holds a pointer rather than the workflow, but its NAME still mirrors —
    # the filename is how a human finds it, and that is true in either mode.

  @n8n @in-n8n @occ @todo
  Scenario: The app never invents a substitute name
    Given a workflow in n8n whose name is empty
    When the "nextcloud:alpha" mapping is pulled
    Then the file is named after the workflow id
    # Falling back to the id is honest and reversible. Inventing "Untitled" would
    # collide the moment a second nameless workflow appeared.

  @n8n @in-n8n @occ @todo
  Scenario: A renamed workflow keeps its place in a subfolder
    Given a managed "sync" workflow file moved into a subfolder of its mapping
    When the workflow is renamed to "New Name" in n8n
    And the "nextcloud:alpha" mapping is pulled
    Then the file is renamed in the subfolder it was in
    # The pull renames within the file's OWN folder. Yanking it back to the mapping
    # root because the name changed would undo a deliberate user gesture.

    # ══ THE EDGES OF A NEXTCLOUD-SIDE RENAME ═══════════════════════════════════

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Renaming an untracked ".n8n.json" file is not a failure
    Given an untracked ".n8n.json" file outside every mapped folder
    When I rename it
    Then the rename succeeds
    And n8n is not contacted

  @user @in-nextcloud @gesture @ui @todo
  Scenario: A failed propagation never reverts the local rename
    Given a managed "sync" workflow file named "Old Name.n8n.json"
    And n8n is unreachable
    When I rename the file to "New Name.n8n.json"
    Then the file stays renamed in Nextcloud
    And the workflow name in n8n is retried on the next sync
    # notes: ../AGENTS.md#a-failed-propagation-never-reverts-the-local-rename

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A rename to an empty or whitespace-only name is refused
    Given a managed "sync" workflow file
    When I rename the file to a name that is only whitespace
    Then the rename is refused
    And n8n is not contacted
    # n8n requires a name on a workflow, so sending a blank one would 400. Refusing
    # locally keeps the failure where the user can see it.
