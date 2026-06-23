# Folder mappings are metadata on the folder, so membership is resolved by where
# a file lives. (How the app reacts when you MOVE a file across that boundary is
# in move.feature — a sync file moved out becomes "unmapped"; a link can't leave.)
#
# Live (saga §14.9): the resolver matches the deepest mapped folder that encloses
# a file, so nested mappings work and the nearest enclosing one wins. Each scenario
# lands a real file over WebDAV and reads the resulting n8n_mapping stamp back, so
# these are server-observable assertions of MappingService::resolveForPath.

Feature: Mapping membership is resolved by folder
  As a Nextcloud admin
  I want mappings to be per-folder metadata
  So that membership is predictable and folders can nest

  # Same precondition as every other behavioural feature: the scenarios add a
  # mapping (needs the app enabled) and land a file in it, which fires the
  # create-on-land listener that registers the workflow in n8n and stamps the
  # n8n_mapping we assert on — so we need the full connection, not just enablement.
  Background:
    Given the app is connected to n8n

  Scenario: A file's mapping is the folder it lives in
    Given a folder mapped to the n8n tag "nextcloud:demo"
    When a managed workflow file lives in that folder
    Then the file belongs to the "nextcloud:demo" mapping

  Scenario: A file outside every mapped folder belongs to no mapping
    Given a folder that is not mapped
    When a workflow file lives in that folder
    Then the file belongs to no mapping
    And it is "untracked" if it has no n8n id, or "unmapped" if it carries one

  Scenario: Folder mappings are metadata, so a mapped folder can nest in another
    Given a folder mapped to the n8n tag "nextcloud:outer"
    And a subfolder of it mapped to the n8n tag "nextcloud:inner"
    When a workflow file lives in the subfolder
    Then it belongs to the "nextcloud:inner" mapping, not "nextcloud:outer"
    And the nearest enclosing mapping wins
