# Copying a workflow file. Where a MOVE is "the same workflow" (see move.feature),
# a COPY is ALWAYS a brand-new instance. A copy never inherits the original's n8n
# identity — its metadata (n8n_id, versionId, mapping, mode) is stripped the moment
# it is copied. Copy is therefore the single safest point to strip metadata:
# whatever the source was (sync, link, unmapped), the copy starts clean.
#
# Nextcloud distinguishes copy from move at the event layer (NodeCopiedEvent vs
# NodeRenamedEvent), which is what lets us treat them oppositely.

Feature: Copying a workflow file always makes a new instance
  As a Nextcloud user
  I want a copy to be a fresh workflow, never a hijack of the original
  So that duplicating a file is safe and predictable

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  @user @in-nextcloud @gesture @ui
  Scenario: Copy within a mapped sync folder becomes a new workflow in n8n
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    When I copy the file within the "nextcloud:alpha" folder
    Then the copy carries no inherited "n8n_id"
    And the copy is registered as a NEW workflow in n8n with its own id
    And the original file and workflow are unchanged
    And there are now two distinct workflows in n8n

  @user @in-nextcloud @gesture @ui
  Scenario: Copy to outside any mapping is a plain untracked file
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    When I copy the file to a folder that is not mapped
    Then the copy has no n8n metadata
    And no workflow is created in n8n for the copy
    And the copy is treated as a plain document

  @user @in-nextcloud @gesture @ui
  Scenario: Copy of an unmapped file strips its metadata wherever it lands
    Given an unmapped workflow file that still carries its "n8n_id"
    When I copy the file to a folder that is not mapped
    Then the copy has no n8n metadata
    And the original unmapped file keeps its "n8n_id"

  @user @in-nextcloud @gesture @ui
  Scenario: Copy of an unmapped file into a mapping becomes a new workflow
    Given an unmapped workflow file that still carries its "n8n_id"
    When I copy the file into the "nextcloud:alpha" folder
    Then the copy carries no inherited "n8n_id"
    And the copy is registered as a NEW workflow in n8n with its own id
    And the original unmapped file's workflow is not restored or duplicated
