# Reserved n8n tag — the optional, per-workflow EXCLUDE switch.
#
# A mapping binds ONE n8n tag (ANY name — e.g. "team:flows", "myfoobarflows"; the
# "nextcloud:" prefix some examples use is just a convention, NOT required) to a
# folder + a mode (`sync` / `link`). That mode is AUTHORITATIVE for every workflow
# in the mapping — there is no per-workflow sync/link override. The only reserved
# tag the app honours is the exclude:
#
#   n8n:ignore  — exclude this one. Two facets:
#                 • never-pulled workflow → no Nextcloud file at all;
#                 • a file already IN a mapped folder → "ignored" mode (it stays put,
#                   keeps its id, is archived in n8n, and the sync skips it).
#
# Authority is one-directional. The app NEVER writes n8n:ignore onto workflows in
# n8n; it only READS it (if present) as a per-workflow exclude at pull time. You add
# it yourself when you want the exception. The Nextcloud-side `n8n:sync` / `n8n:link`
# system tags the app stamps on managed files are AUTHORITATIVE + automatic and just
# mirror each file's mode (see the Tagging feature / file-type.feature) — they are
# not an override mechanism.
#
# So n8n:ignore is 100% optional: the mapping does everything on its own; the
# n8n-side ignore tag is just the escape hatch to leave one workflow out.
#
# The never-pulled ignore and the in-folder `ignored` mode are live (saga §14.8 B).
# The un-tag RESTORE — removing n8n:ignore unarchives the workflow and returns the
# file to the mapping's mode — is live too (saga §14.18), driven by a
# TagUnassignedEvent listener.

Feature: The n8n:ignore reserved tag excludes individual workflows
  As an n8n admin
  I want to exclude individual workflows with the n8n:ignore tag
  So that one mapping can still leave specific workflows out

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "team:flows"

  @in-n8n @ui @occ
  Scenario: With no reserved tag, a workflow takes the mapping's mode
    Given n8n has a workflow tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then that workflow's file is in "sync" mode (the mapping mode)

  @in-n8n @ui @occ
  Scenario: n8n:ignore on a never-pulled workflow creates no file
    Given n8n has a workflow tagged "team:flows" and "n8n:ignore"
    When the "team:flows" mapping is pulled
    Then that workflow is not pulled into Nextcloud
    And no file is created for it

  Scenario: n8n:ignore on a file already in a mapped folder gives it "ignored" mode
    Given a managed "sync" workflow file in the "team:flows" folder
    When I tag it "n8n:ignore"
    Then the file's mode becomes "ignored"
    And the file stays in the mapped folder and keeps its "n8n_id"
    And the workflow is archived in n8n
    And subsequent pulls/pushes for "team:flows" skip it

  Scenario: Removing n8n:ignore returns the file to the mapping's mode
    Given a managed "sync" workflow file in the "team:flows" folder
    And I tag it "n8n:ignore"
    When I remove the "n8n:ignore" tag
    Then the file's mode becomes "sync"

  @in-n8n @ui @occ
  Scenario: A mapping tag needs no "nextcloud:" prefix
    Given a folder mapped as "sync" to the n8n tag "myfoobarflows"
    And n8n has a workflow tagged "myfoobarflows"
    When the "myfoobarflows" mapping is pulled
    Then that workflow's file is created in "sync" mode

  @in-n8n @ui @occ
  Scenario: The app never writes reserved tags onto n8n workflows
    Given n8n has a workflow tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then the workflow in n8n still carries only its original tags
    And the app has not added any "n8n:sync", "n8n:link", or "n8n:ignore" tag to it
