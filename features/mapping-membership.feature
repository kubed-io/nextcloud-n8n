# Folder mappings are metadata on the folder, so membership is resolved by where
# a file lives. (How the app reacts when you MOVE a file across that boundary is
# in move.feature — most cross-mapping moves are blocked.)

@todo
Feature: Mapping membership is resolved by folder
  As a Nextcloud admin
  I want mappings to be per-folder metadata
  So that membership is predictable and folders can nest

  Scenario: A file's mapping is the folder it lives in
    Given a folder mapped to the n8n tag "nextcloud:demo"
    When a managed workflow file lives in that folder
    Then the file belongs to the "nextcloud:demo" mapping

  Scenario: Folder mappings are metadata, so a mapped folder can nest in another
    Given a folder mapped to the n8n tag "nextcloud:outer"
    And a subfolder of it mapped to the n8n tag "nextcloud:inner"
    When a workflow file lives in the subfolder
    Then it belongs to the "nextcloud:inner" mapping, not "nextcloud:outer"
    And the nearest enclosing mapping wins
