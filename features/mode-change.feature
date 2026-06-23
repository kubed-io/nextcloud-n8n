# Changing a managed file's mode between sync and link — a re-mode transition.
#
# Same `n8n:sync` / `n8n:link` vocabulary, triggerable three ways:
#   - Files context menu: a one-click "Toggle n8n mode" action (the easy path).
#   - Nextcloud tags: change the file's system tag n8n:sync <-> n8n:link by hand.
#     (Normally the app keeps that tag matching the mode metadata; a user changing it
#     is read as a request to change the mode — the app then re-asserts everything.)
#   - n8n: add/flip the workflow's override tag; the next pull applies the new mode.
#
# Mutual exclusivity: a managed file carries EXACTLY ONE of n8n:sync / n8n:link. If a
# user manually adds the second without removing the first, the just-added tag is
# taken as the intent — the app transitions to it and strips the other.
#
# The transition rewrites the file to fit its new mode:
#   sync → link : content collapses to the small pointer (id, name, URL); stops pushing.
#                 (No data loss — n8n already holds the full workflow.)
#   link → sync : the full workflow JSON is pulled down into the file; two-way begins.
#
# The workflow identity (`n8n_id`) is preserved across the change — same workflow,
# just presented differently in Nextcloud.
#
# Live: the retag transitions (sync↔link from Nextcloud) and the n8n-side overrides
# (sync↔link applied as a workflow tag in n8n, then pulled). The only `@todo` left is
# the Files context-menu **Toggle** action — a browser click Behat can't drive; it's
# covered by `tests/js/files-helpers.test.js` (Vitest). CI skips `@todo`.

Feature: Changing a managed file between sync and link
  As a user
  I want to flip a workflow file between sync and link by retagging it
  So that I can change how Nextcloud holds a workflow without losing the link to it

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "team:flows"
    And a folder mapped as "link" to the n8n tag "team:links"

  @todo
  Scenario: Toggle mode from the Files context menu
    Given a managed "sync" workflow file in the "team:flows" folder
    When I choose "Toggle n8n mode" from the file's context menu
    Then the file's mode becomes "link"
    And its system tag is exactly "n8n:link" (no "n8n:sync")
    And toggling again returns it to "sync"
    And the workflow's "n8n_id" is unchanged throughout

  Scenario: Manually adding the second mode tag resolves to the just-added one
    Given a managed "sync" workflow file in the "team:flows" folder
    When I add "n8n:link" without removing "n8n:sync"
    Then the file transitions to "link" mode
    And "n8n:sync" is stripped so exactly one mode tag remains

  Scenario: Sync → link from Nextcloud (retag the file)
    Given a managed "sync" workflow file in the "team:flows" folder
    When I change its system tag from "n8n:sync" to "n8n:link"
    Then the file's mode becomes "link"
    And the file content collapses to the link pointer (id, name, URL — not the full JSON)
    And saving the file no longer pushes to n8n
    And the workflow's "n8n_id" is unchanged

  Scenario: Link → sync from Nextcloud (retag the file)
    Given a managed "link" workflow file in the "team:links" folder
    When I change its system tag from "n8n:link" to "n8n:sync"
    Then the file's mode becomes "sync"
    And the full workflow JSON is pulled into the file
    And saving the file now pushes to n8n
    And the workflow's "n8n_id" is unchanged

  Scenario: Sync → link from n8n (override tag, then pull)
    Given a managed "sync" workflow file for a workflow tagged "team:flows"
    When I add "n8n:link" to that workflow in n8n
    And the "team:flows" mapping is pulled
    Then the file's mode becomes "link"
    And the workflow's "n8n_id" is unchanged

  Scenario: Link → sync from n8n (override tag, then pull)
    Given a managed "link" workflow file for a workflow tagged "team:flows" and "n8n:link"
    When I change that workflow's override tag to "n8n:sync" in n8n
    And the "team:flows" mapping is pulled
    Then the file's mode becomes "sync"
    And the full workflow JSON is now in the file
    And the workflow's "n8n_id" is unchanged
