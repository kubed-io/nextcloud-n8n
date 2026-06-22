# Reconcile & prune. The move-out lifecycle (move.feature) can create a transient
# duplicate: a sync file moved out becomes an UNMAPPED copy in NC while its
# workflow is archived in n8n. If someone independently restores that workflow in
# n8n, the mapping's next pull brings it back into the mapped folder — now there
# are TWO Nextcloud files for one workflow id (one mapped, one unmapped).
#
# The reconcile pass (part of the n8n→NC pull) detects this by workflow id and
# prunes the redundant UNMAPPED copy: the n8n-sourced mapped file is authoritative
# and newer. No duplicates survive a sync.
#
# @todo until the reconcile/prune logic lands (saga Chapter 4, Phase 2). CI skips
# @todo so this documents the intended behaviour now and goes live as code lands.

@todo
Feature: Reconcile prunes a duplicate left by a move-out then n8n-side restore
  As a Nextcloud admin
  I want a sync pull to remove redundant unmapped copies
  So that one workflow never ends up as two files

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  Scenario: A pull prunes the unmapped copy when its workflow returns to the mapping
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    And I move the file out to an unmapped location
    # → file is now "unmapped" (keeps its id); workflow archived in n8n
    And that workflow is independently restored (unarchived) in n8n
    And that workflow still carries the "nextcloud:alpha" tag
    When the scheduled pull for "nextcloud:alpha" runs
    Then the workflow appears as a mapped file in the "nextcloud:alpha" folder
    And the redundant unmapped copy is pruned
    And only one Nextcloud file remains for that workflow id

  Scenario: Reconcile leaves a genuinely separate unmapped file alone
    Given an unmapped workflow file whose workflow is still archived in n8n
    When the scheduled pull for "nextcloud:alpha" runs
    Then the unmapped file is not pruned
    And it keeps its "n8n_id"
