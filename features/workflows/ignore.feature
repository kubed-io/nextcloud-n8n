# Notes, decisions and history for this feature: ../AGENTS.md#workflowsignore

Feature: The n8n:ignore reserved tag excludes individual workflows
  As an n8n admin
  I want to exclude individual workflows with the n8n:ignore tag
  So that one mapping can still leave specific workflows out

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "team:flows"

  @n8n @in-n8n @ui @occ
  Scenario: With no reserved tag, a workflow takes the mapping's mode
    Given n8n has a workflow tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then that workflow's file is in "sync" mode (the mapping mode)

  @n8n @in-n8n @ui @occ
  Scenario: n8n:ignore on a never-pulled workflow creates no file
    Given n8n has a workflow tagged "team:flows" and "n8n:ignore"
    When the "team:flows" mapping is pulled
    Then that workflow is not pulled into Nextcloud
    And no file is created for it

  @user @in-nextcloud @gesture @ui
  Scenario: n8n:ignore on a file already in a mapped folder gives it "ignored" mode
    Given a managed "sync" workflow file in the "team:flows" folder
    When I tag it "n8n:ignore"
    Then the file's mode becomes "ignored"
    And the file stays in the mapped folder and keeps its "n8n_id"
    And the workflow is archived in n8n
    And subsequent pulls/pushes for "team:flows" skip it

  @user @in-nextcloud @gesture @ui
  Scenario: Removing n8n:ignore returns the file to the mapping's mode
    Given a managed "sync" workflow file in the "team:flows" folder
    And I tag it "n8n:ignore"
    When I remove the "n8n:ignore" tag
    Then the file's mode becomes "sync"

  @n8n @in-n8n @ui @occ
  Scenario: A mapping tag needs no "nextcloud:" prefix
    Given a folder mapped as "sync" to the n8n tag "myfoobarflows"
    And n8n has a workflow tagged "myfoobarflows"
    When the "myfoobarflows" mapping is pulled
    Then that workflow's file is created in "sync" mode

  @n8n @in-n8n @ui @occ
  Scenario: The app never writes reserved tags onto n8n workflows
    Given n8n has a workflow tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then the workflow in n8n still carries only its original tags
    And the app has not added any "n8n:sync", "n8n:link", or "n8n:ignore" tag to it
