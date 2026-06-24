# How the app reacts to every move a Nextcloud user can make on a workflow file.
# A MOVE mirrors as the SAME workflow moving in n8n — never a duplicate. The
# stable link is the workflow id, so a move out and back in is an archive then a
# restore, not a delete then a create. (COPY is the opposite — always a new
# instance; see copy.feature.)
#
# Model (saga Chapter 2 §14): modes are sync / link / unmapped. "unmapped" is the
# state a sync file enters when moved OUT of its mapped folder: NC keeps the full
# JSON + the workflow id + versionId, clears the mapping, and the workflow is
# archived in n8n. Moving it back into any mapping restores (unarchives) it.
#
# LIVE (saga §14.2, Phase 2): the sync move-out → unmapped + archive, the
# unmapped move-in → restore, within-mapping moves, link move-out refusal, and
# unmapped relocation are wired (MoveGuardListener + MotionListener +
# MotionService) and asserted here over WebDAV (MOVE) + the n8n REST API. The
# hard-deleted restore-fallback and brand-new move-in create are now live too;
# the lone remaining edge is merge-on-collision (an unmapped copy moved in over an
# already-synced file with the same id), which still needs a metadata-by-id lookup.

Feature: Moving a workflow file is the same workflow leaving and returning
  As a Nextcloud user
  I want moves to mirror as the same workflow in n8n
  So that relocating a file never duplicates or silently desyncs a workflow

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"
    And a folder mapped as "sync" to the n8n tag "nextcloud:beta"
    And a folder mapped as "link" to the n8n tag "nextcloud:links"

  # ── within the same mapping: no n8n change ───────────────────────────────────

  Scenario: Move within the same mapping (rename) keeps it managed
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    When I rename the file within the "nextcloud:alpha" folder
    Then the file stays in "sync" mode in the "nextcloud:alpha" mapping
    And nothing changes in n8n except the name

  Scenario: Move into a subfolder of the same mapping keeps it managed
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    When I move the file into a subfolder of the "nextcloud:alpha" folder
    Then the file stays in "sync" mode in the "nextcloud:alpha" mapping
    And nothing changes in n8n

  # ── sync move-out → unmapped + archived ──────────────────────────────────────

  Scenario: Moving a sync file out of its mapping unmaps it and archives in n8n
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    When I move the file to a folder that is not mapped
    Then the file's mode becomes "unmapped"
    And the file keeps its "n8n_id" and "n8n_versionId"
    And the file's "n8n_mapping" is cleared
    And the workflow is archived (hidden, preserved) in n8n
    And the full workflow JSON is still in the Nextcloud file

  # ── move back in → restore (same workflow, not a new one) ────────────────────

  Scenario: Moving an unmapped file back into a mapping restores the workflow
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move the file into the "nextcloud:beta" folder
    Then the workflow is unarchived in n8n
    And the file's mode becomes "sync" in the "nextcloud:beta" mapping
    And the "n8n_id" is unchanged

  # Restore-fallback: the unmapped file kept its id, but the workflow was hard-
  # deleted in n8n in the meantime. moveIn catches the unarchive 404 and recreates
  # from the file we still hold (a fresh id), then re-stamps sync in the target.
  Scenario: Restoring when the n8n workflow was hard-deleted falls back to create
    Given an unmapped workflow file that still carries its "n8n_id"
    And that workflow no longer exists in n8n
    When I move the file into the "nextcloud:beta" folder
    Then a new workflow is created in n8n from the file
    And the file's mode becomes "sync" in the "nextcloud:beta" mapping

  # Merge UX: the unmapped + mapped duplicate (same id) is a fine, intentional
  # state — e.g. an admin restored the workflow in n8n and it synced back into the
  # mapping while the unmapped copy still existed. Moving the unmapped copy back in
  # then collides with the already-synced file; n8n (the synced copy) is the source
  # of truth, so the incoming copy is simply deleted. Feels like a merge.
  # MotionService::moveIn scans the landing folder for a sibling carrying the same
  # n8n_id; on a hit it deletes the incoming copy under the SyncGuard (so n8n is
  # untouched) and leaves the existing synced file as the single source of truth.
  Scenario: Moving an unmapped file in when a synced copy already exists merges (deletes the incoming)
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    And an unmapped copy of that same workflow with the same "n8n_id" outside any mapping
    When I move the unmapped copy into the "nextcloud:alpha" folder
    Then the app sees the existing synced file with the same "n8n_id"
    And the incoming unmapped copy is deleted from Nextcloud
    And the original synced file remains unchanged
    And nothing is restored or duplicated in n8n

  # Move-in create: an untracked file (no id) dragged into a mapping is create-on-
  # land — CreateInN8nListener fires on the NodeRenamedEvent (NC doesn't fire
  # NodeWrittenEvent for a move) and mints the workflow, stamping sync + the mapping.
  Scenario: Moving a brand-new workflow file into a mapping creates it
    Given a ".n8n.json" file that was never tracked in n8n
    When I move the file into the "nextcloud:alpha" folder
    Then a matching workflow is created in n8n
    And the file's mode becomes "sync" in the "nextcloud:alpha" mapping

  # ── link move-out is refused ─────────────────────────────────────────────────

  Scenario: Moving a link out of its mapping is blocked
    Given a managed "link" workflow file in the "nextcloud:links" folder
    When I try to move the file to a folder that is not mapped
    Then the move is refused with a message
    And the file stays in the "nextcloud:links" folder

  # ── relocating an already-unmapped file: pure relocation ─────────────────────

  Scenario: Moving an unmapped file between unmapped locations changes nothing
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move the file to another folder that is not mapped
    Then the file stays "unmapped"
    And its "n8n_id" and "n8n_versionId" are unchanged
    And nothing changes in n8n

  # ── decision cases (saga Chapter 2 §14.2 a–d): documented, not yet designed ─────────
  # These need a design decision before they get concrete Then-steps:
  #   a. sync moved directly mapping→mapping (different tag): re-tag in place vs
  #      eject+reattach vs block. (Currently blocked by MoveGuardListener.)
  #   b. moving into a nested subfolder owned by a different mapping (nearest
  #      enclosing wins) — interaction with case a.
  #   c. link rename within its mapping — does the filename matter, or is the n8n
  #      name authoritative?
  #   d. deleting an unmapped file (it has an id + an archived workflow) — see
  #      delete.feature.
