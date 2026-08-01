# The two manual sync controls in admin settings, each SCOPED TO A MAPPING:
#   - "Sync from n8n" (pull): bring the mapping's tagged workflows into its folder.
#   - "Sync to n8n"   (push): send the mapping's sync files up to n8n.
# Both reconcile the mapped folder against the workflows carrying that mapping's
# tag, and both FULLY IGNORE "unmapped" files — those live outside any mapping, so
# a mapping-scoped sync never sees them. Pruning here is therefore mapping-scoped:
# it only ever concerns files/workflows inside the mapping.
#
# (The "merge" that happens when you MOVE an unmapped file back into a mapping that
# already holds its workflow is a MOVE-time behaviour, not a sync — see
# move.feature. The duplicate state, one unmapped + one mapped with the same id, is
# perfectly fine and intentional; a sync does not touch the unmapped one.)

Feature: Manual per-mapping sync (Sync from / Sync to n8n)
  As a Nextcloud admin
  I want the per-mapping sync buttons to reconcile just that mapping
  So that a folder matches its n8n tag on demand, ignoring everything else

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  @ui @occ
  Scenario: Sync from n8n pulls the tagged workflows into the mapped folder
    Given n8n has workflows tagged "nextcloud:alpha"
    And an unmapped workflow file exists outside every mapping
    When the admin clicks "Sync from n8n" for the "nextcloud:alpha" mapping
    Then each "nextcloud:alpha" workflow appears as a file in the mapped folder
    And existing files are updated in place — matched by workflow id, never duplicated
    And a mapped file whose workflow no longer carries the tag is pruned from the folder
    And the unmapped file is left untouched (it is outside the mapping's scope)

  @ui @occ
  Scenario: Sync to n8n pushes the mapping's sync files up to n8n
    Given the "nextcloud:alpha" folder has sync workflow files with local changes
    And an unmapped workflow file exists outside every mapping
    When the admin clicks "Sync to n8n" for the "nextcloud:alpha" mapping
    Then each sync file in the folder is pushed to its workflow in n8n
    And the unmapped file is not pushed (it is outside the mapping's scope)
