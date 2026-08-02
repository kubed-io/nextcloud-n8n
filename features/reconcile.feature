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

  # ── RULE: a run that changes nothing changes nothing ──────────────────────────
  # Two things are NOT behaviours, and both were nearly written up as if they were.
  #
  # A file's "modified" time is a RESULT, not a gesture. Editing, moving, copying and
  # renaming all move it, each already owned by the file that owns the gesture. A
  # scenario asserting "the mtime moved after an edit" specifies Nextcloud, not this
  # app, and has to invent an actor to do it.
  #
  # The RECONCILER is likewise the *how*, not the *what*. The scheduled pull is a
  # machine that makes n8n-origin behaviours show up in Nextcloud; "renamed in n8n"
  # is the behaviour, the reconciler is merely how it arrives — which is why those
  # scenarios live with their behaviour and carry `@in-n8n`, not here.
  #
  # What is left is genuinely this file's, and genuinely not automatic: the admin
  # presses the button when nothing has changed, and the run must leave every file
  # exactly as it found it. It matters because the same run is what the schedule
  # fires — a write performed unconditionally is performed forever, and a folder
  # where everything was modified seconds ago says nothing about what changed.
  #
  # The negative control lives with the behaviour that supplies it: "a content change
  # in n8n DOES rewrite the mirror" is tag-sync.feature's, so this rule cannot be
  # satisfied by a pull that has simply stopped writing.

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
