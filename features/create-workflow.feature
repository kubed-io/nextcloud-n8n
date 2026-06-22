# Creating workflows from Nextcloud. These scenarios are the human-readable spec
# for the "author in NC, live in n8n" flow. LIVE: a .n8n.json written over WebDAV
# into a mapped folder fires NodeWrittenEvent → CreateInN8nListener → the workflow
# appears in n8n. The n8n side is asserted over its REST API; the NC stamp over
# DAV PROPFIND of nc:metadata-n8n_id.

Feature: Create a workflow from Nextcloud
  As a Nextcloud user
  I want to create n8n workflows by making files
  So that I can author workflows without opening the n8n UI

  Background:
    Given the app is installed and enabled
    And the admin has set the n8n base URL and enabled the REST API
    And the admin provides the n8n API key

  Scenario: New file in a mapped sync folder becomes a real workflow
    Given a folder mapped as "sync" to the n8n tag "nextcloud:demo"
    When I create a new ".n8n.json" file in that folder via the Files "New" menu
    Then a matching workflow is created in n8n
    And the workflow carries the "nextcloud:demo" tag
    And the file is stamped with the workflow's "n8n_id"

  Scenario: A workflow file created outside any mapped folder stays unmanaged
    Given a folder that is not mapped
    When I create a ".n8n.json" file in that folder
    Then no workflow is created in n8n
    And the file has no "n8n_id" metadata
    And the file is treated as a plain document (unmapped state)
