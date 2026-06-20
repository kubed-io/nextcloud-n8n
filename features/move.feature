# How the app reacts to every move a Nextcloud user can attempt on a managed
# workflow file. Nextcloud lets you move files anywhere; this documents what our
# MoveGuardListener does in each case. A *block* is a real, tested feature — we
# wrote code to prevent silent desync, so the end-state here is "the move is
# refused with a message", not "it silently works".
#
# Current rule (lib/Listener/MoveGuardListener.php): a managed .n8n.json file may
# only move WITHIN its own mapping (rename, or into a subfolder of the same
# mapped folder). Any move that lands under a *different* mapping, or under no
# mapping, is aborted.
#
# @todo until the move/abort step defs land (saga §5); kept accurate to code so
# it's the spec, not a wish.

@todo
Feature: Moving a managed workflow file
  As a Nextcloud user
  I want clear, safe behaviour when I move a workflow file around
  So that a move never silently breaks the n8n connection

  Background:
    Given a folder mapped to the n8n tag "nextcloud:alpha"
    And a separate folder mapped to the n8n tag "nextcloud:beta"
    And a managed "sync" workflow file in the "nextcloud:alpha" folder

  Scenario: Move within the same mapping (into a subfolder) is allowed
    When I move the file into a subfolder of the "nextcloud:alpha" folder
    Then the move succeeds
    And the file stays managed by the "nextcloud:alpha" mapping

  Scenario: Move out to an unmapped folder is blocked
    When I move the file to a folder that is not mapped
    Then the move is aborted with a message naming the synced folder
    And the file remains in the "nextcloud:alpha" folder

  Scenario: Move from one mapped folder to another is blocked
    When I move the file into the "nextcloud:beta" folder
    Then the move is aborted with a message naming the synced folder
    And the file remains in the "nextcloud:alpha" folder

  Scenario: Move into a nested folder belonging to a different mapping is blocked
    Given a subfolder of the "nextcloud:alpha" folder mapped to "nextcloud:beta"
    When I move the file into that nested "nextcloud:beta" subfolder
    Then the move is aborted with a message naming the synced folder
    And the file remains in its original location
