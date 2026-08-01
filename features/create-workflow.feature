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
    Given the app is connected to n8n

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

    # ══ ADOPTION: A FILE THAT ARRIVES ALREADY CARRYING ITS TAGS ════════════════
    #
    # A workflow can come into existence from a file that was authored elsewhere —
    # exported from another n8n, copied from a sibling, or carried out of Nextcloud
    # and back. Creation is creation, so it lives here rather than in
    # tag-sync.feature; what tag-sync.feature owns is what happens to tags AFTER a
    # file is managed.
    #
    # THE BODY IS THE ONLY SURFACE THAT SURVIVES THE TRIP. Nextcloud's system-tag
    # pills are bound to a file id, so they do not survive an export or a copy; the
    # `tags` array is bytes inside the file. At the moment of adoption there are no
    # pills, no baseline, and no workflow — the body is the ONLY record of what this
    # thing was tagged. That is why it wins here and nowhere else (saga §5.6.3).
    #
    # THIS IS A DEFECT TODAY, NOT MERELY UNBUILT. `CreateService` sends
    # `N8nWorkflowBody::toCreateBody`, whose writable whitelist omits `tags`, so
    # `$created['tags']` is ALWAYS empty and the "additive merge" merges the mapping
    # tag into nothing. Every tag the file arrived with is silently discarded. The
    # docblock claiming "POST /workflows preserves tags the body declared" was wrong
    # twice over: we never declare them, and n8n's schema marks `tags` readOnly on
    # create AND update anyway (`workflowCreate.yml`, `additionalProperties: false`).
    # `PUT /workflows/{id}/tags` is the only writer that exists.
    #
    # Nothing caught it because adoption's tag behaviour had never been written down.

  @unbuilt
  Scenario: A file that arrives with tags in its body carries them into n8n
    Given a folder mapped as "sync" to the n8n tag "nextcloud:demo"
    And a ".n8n.json" file whose body carries the tags "prod", "billing", and "critical"
    When I place that file in the mapped folder
    Then a matching workflow is created in n8n
    And the workflow carries the tags "prod", "billing", "critical", and "nextcloud:demo"
    And the file has the Nextcloud system tags "prod", "billing", and "critical"
    # The mapping tag JOINS them — adoption is additive, never a replace.

  @unbuilt
  Scenario: A file that arrives with no tags adopts with only the mapping tag
    Given a folder mapped as "sync" to the n8n tag "nextcloud:demo"
    And a ".n8n.json" file whose body carries no tags
    When I place that file in the mapped folder
    Then the workflow carries only the "nextcloud:demo" tag
    # Nothing to seed. A missing `tags` array is not an error.

  @unbuilt
  Scenario: A round trip out of Nextcloud and back keeps the workflow's tags
    Given a folder mapped as "sync" to the n8n tag "nextcloud:demo"
    And a managed workflow file tagged "prod" and "billing"
    When the file is copied out of Nextcloud and its workflow is deleted in n8n
    And the copy is placed back in the mapped folder
    Then the workflow recreated in n8n carries the tags "prod", "billing", and "nextcloud:demo"
    # THE CASE THAT DECIDES THE DESIGN. The pills did not survive the trip and n8n
    # no longer holds the workflow. The body is the only carrier left — and it is
    # enough, which is the whole reason adoption reads it.

  @unbuilt
  Scenario: Adoption takes the tags from the body alone
    Given a folder mapped as "sync" to the n8n tag "nextcloud:demo"
    And a ".n8n.json" file whose body carries the tag "prod"
    When I place that file in the mapped folder
    Then the tags are taken from the body alone
    And no existing workflow's tags are read to decide them
    # There is no baseline and no remote counterpart yet, so there is nothing to
    # merge against — the three-way merge does not apply at adoption.
