# Three-way name agreement in sync mode: filename stem ⇄ JSON "name" ⇄ n8n name.
# The stable link is the workflow id, so none of these break the connection.
# LIVE: rename/edit go over WebDAV; the file-locked reconcile runs in
# ReconcileNameJob, so the steps drain that job class with the occ worker before
# asserting both the file (PROPFIND/GET) and n8n (REST) sides.

Feature: Renaming keeps file, JSON, and n8n in agreement
  As a Nextcloud user
  I want renames to propagate everywhere
  So that the file name, its JSON name, and the n8n workflow name never drift

  Background:
    Given the app is installed and enabled

  @in-nextcloud @gesture @ui
  Scenario: Renaming the file updates the backend JSON name and n8n
    Given a managed "sync" workflow file named "Old Name.n8n.json"
    When I rename the file to "New Name.n8n.json"
    Then the JSON "name" field inside the file becomes "New Name"
    And the workflow is renamed to "New Name" in n8n

  Scenario: Editing the JSON name renames the file and updates n8n
    Given a managed "sync" workflow file
    When I edit the file and change the JSON "name" field to "Renamed In JSON"
    Then the file is renamed to "Renamed In JSON.n8n.json"
    And the workflow is renamed to "Renamed In JSON" in n8n

  Scenario: Renaming never breaks the link
    Given a managed "sync" workflow file with a known "n8n_id"
    When the file is renamed by any of the above means
    Then the "n8n_id" metadata is unchanged
