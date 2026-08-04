# Notes, decisions and history for this feature: AGENTS.md#reconcile

Feature: Manual per-mapping sync (Sync from / Sync to n8n)
  As a Nextcloud admin
  I want the per-mapping sync buttons to reconcile just that mapping
  So that a folder matches its n8n tag on demand, ignoring everything else

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  @admin @ui @occ
  Scenario: Sync from n8n pulls the tagged workflows into the mapped folder
    Given n8n has workflows tagged "nextcloud:alpha"
    And an unmapped workflow file exists outside every mapping
    When the admin clicks "Sync from n8n" for the "nextcloud:alpha" mapping
    Then each "nextcloud:alpha" workflow appears as a file in the mapped folder
    # The mirror comes into existence here, so its creation time is an end state of
    # this behaviour — and the one clock a later sync can never reconstruct, because
    # after this run there is no "before" left to read it from.
    And each file's creation time is when its workflow was created in n8n
    And existing files are updated in place — matched by workflow id, never duplicated
    And a mapped file whose workflow no longer carries the tag is pruned from the folder
    And the unmapped file is left untouched (it is outside the mapping's scope)

  # notes: AGENTS.md#sync-from-n8n-with-nothing-changed-rewrites-nothing-and-says-so

  @admin @ui @occ
  Scenario: Sync from n8n with nothing changed rewrites nothing and says so
    Given n8n has workflows tagged "nextcloud:alpha"
    And the "nextcloud:alpha" mapping has already been pulled
    When the admin clicks "Sync from n8n" for the "nextcloud:alpha" mapping
    Then the run reports every file as unchanged
    And no file in the mapped folder was rewritten

  @admin @ui @occ
  Scenario: Sync to n8n pushes the mapping's sync files up to n8n
    Given the "nextcloud:alpha" folder has sync workflow files with local changes
    And an unmapped workflow file exists outside every mapping
    When the admin clicks "Sync to n8n" for the "nextcloud:alpha" mapping
    Then each sync file in the folder is pushed to its workflow in n8n
    And the unmapped file is not pushed (it is outside the mapping's scope)
