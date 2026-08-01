# Purge — an admin-only button beside "Sync from/to n8n" and "Test connection"
# (also `occ n8n_sync:purge`) that removes the workflow files THIS APP created and
# nothing else. It deletes every **restorable** managed file — `sync` and `link`,
# whose workflow is still live + tagged in n8n — across all mappings, and:
#   - never contacts n8n (the delete runs under SyncGuard so it can't mirror out);
#   - leaves the mappings configured;
#   - leaves the custom mimetype registration alone (that is uninstall's job).
#
# It deliberately KEEPS files a "Sync from n8n" could not bring back, so purge can
# never cost you data: `unmapped` files (moved out of a mapping, archived in n8n —
# a standalone copy / template you kept), `ignored` files, and untracked `.n8n.json`
# (a plain document the app never created).
#
# Driven headlessly through `occ n8n_sync:purge` ({@see \OCA\N8nSync\Command\Purge}).
# Two intended flows: purge → "Sync from n8n" (everything reappears), and
# purge → uninstall (Nextcloud looks like the app was never there).

Feature: Purge the app's restorable files from Nextcloud
  As a Nextcloud admin
  I want a button that removes the workflow files this app created
  So that I can reset the Nextcloud side without ever touching n8n or losing standalone files

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  @occ
  Scenario: Purge deletes the synced file but leaves its workflow in n8n and the mapping intact
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    When the admin purges the Nextcloud files
    Then no managed workflow files remain in the "nextcloud:alpha" folder
    And the workflow still exists in n8n
    And the "nextcloud:alpha" mapping is still configured

  @occ
  Scenario: Purge keeps an unmapped file — a standalone copy is never lost
    Given an unmapped workflow file that still carries its "n8n_id"
    And I remember the unmapped file
    And a managed "sync" workflow file in the "nextcloud:alpha" folder
    When the admin purges the Nextcloud files
    Then no managed workflow files remain in the "nextcloud:alpha" folder
    And the remembered file is left in place

  @occ
  Scenario: Sync from n8n brings the file back after a purge
    Given a managed "sync" workflow file in the "nextcloud:alpha" folder
    And the admin purges the Nextcloud files
    When the admin clicks "Sync from n8n" for the "nextcloud:alpha" mapping
    Then the workflow appears again as a file in the "nextcloud:alpha" folder

  # An `ignored` file is one the user excluded ON PURPOSE — it keeps its id and its
  # place — so the purge must walk past it. Was @todo for want of an arrange; the
  # arrange existed all along, it just silently ignored the mode it was handed and
  # produced a `sync` file, which would have made this scenario assert the opposite
  # of its own Given.
  @occ
  Scenario: Purge keeps an ignored file
    Given a managed "ignored" workflow file in the "nextcloud:alpha" folder
    When the admin purges the Nextcloud files
    Then that ignored file is left in place
