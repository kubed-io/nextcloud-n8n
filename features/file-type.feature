# The custom mimetype makes a workflow a first-class file type: own icon, own
# "editor" (click opens the workflow in n8n), and DAV-exposed metadata.

@todo
Feature: n8n workflow is a first-class file type
  As a Nextcloud user
  I want .n8n.json files to behave like a real, purpose-built file type
  So that they have the right icon, open the right thing, and expose their state

  Scenario: Workflow files get the custom mimetype and n8n icon
    Given a managed workflow file
    Then its mimetype is "application/n8n+json"
    And the Files app shows the n8n icon instead of a generic JSON icon

  Scenario: Clicking a workflow file opens it in n8n
    Given a managed workflow file with a known "n8n_id"
    When I click the file in the Files app
    Then n8n opens at that workflow (not a download, not the text editor)

  Scenario: WebDAV PROPFIND exposes the workflow metadata
    Given a managed workflow file
    When a WebDAV client requests the file's properties (PROPFIND)
    Then the raw XML includes:
      | property                  |
      | nc:metadata-n8n_id        |
      | nc:metadata-n8n_mode      |
      | nc:metadata-n8n_versionId |
      | nc:metadata-n8n_mapping   |
    And those properties are read-only (PROPPATCH cannot change them)
    # n8n_mode is "sync", "reference" (= link, on-the-wire only), or "unmapped";
    # n8n_writeback is gone (saga Chapter 4 — mode is the single source of truth).

  Scenario: The mode property carries the descriptive value
    Given a managed "sync" workflow file
    Then its "nc:metadata-n8n_mode" property is "sync"
