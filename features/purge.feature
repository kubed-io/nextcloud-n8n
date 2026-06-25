# Purge — an admin-only button beside "Sync from/to n8n" and "Test connection"
# that removes the workflow files THIS APP created and nothing else. It deletes
# every **restorable** managed file — `sync` and `link`, whose workflow is still
# live + tagged in n8n — across all mappings, and:
#   - never contacts n8n (the delete runs under SyncGuard so it can't mirror out);
#   - leaves the mappings configured;
#   - leaves the custom mimetype registration alone (that is uninstall's job).
#
# It deliberately KEEPS files a "Sync from n8n" could not bring back, so purge can
# never cost you data:
#   - **unmapped** files — moved out of a mapping, workflow archived in n8n; they
#     are standalone copies (a template you kept), unrelated to any mapping;
#   - **ignored** files — workflow archived + excluded from sync;
#   - **untracked** `.n8n.json` — a plain document the app never created.
#
# So purge is reversible by a plain "Sync from n8n": every sync/link file comes back.
#
# Two intended flows:
#   1. purge → "Sync from n8n" → every workflow reappears as a fresh file.
#   2. purge → uninstall → Nextcloud looks (visibly) like the app was never there.

# Spec-first: the step definitions land once the purge button is verified on a real
# Nextcloud, so the whole feature is @todo for now (behat skips it). The purge LOGIC
# — delete sync/link, keep unmapped/ignored/untracked — is unit-tested in SyncServiceTest.
@todo
Feature: Purge the app's restorable files from Nextcloud
  As a Nextcloud admin
  I want a button that removes the workflow files this app created
  So that I can reset the Nextcloud side without ever touching n8n or losing standalone files

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  Scenario: Purge deletes sync & link files but leaves n8n and the mapping intact
    Given the "nextcloud:alpha" folder has managed sync workflow files
    When the admin clicks "Purge Nextcloud files"
    Then no managed "sync" or "link" files remain in the "nextcloud:alpha" folder
    And the workflows still exist in n8n, unchanged
    And the "nextcloud:alpha" mapping is still configured

  Scenario: Purge keeps an unmapped file (it can't be restored, so it's never lost)
    Given an unmapped workflow file that still carries its "n8n_id"
    When the admin clicks "Purge Nextcloud files"
    Then that unmapped file is left in place

  Scenario: Purge keeps an ignored file
    Given a managed "ignored" workflow file in the "nextcloud:alpha" folder
    When the admin clicks "Purge Nextcloud files"
    Then that ignored file is left in place

  Scenario: Purge leaves an untracked .n8n.json file alone
    Given an untracked ".n8n.json" file (no n8n_id) in the "nextcloud:alpha" folder
    When the admin clicks "Purge Nextcloud files"
    Then that untracked file is left in place

  Scenario: Sync from n8n brings the files back after a purge
    Given the "nextcloud:alpha" folder has managed sync workflow files
    And the admin has purged the Nextcloud files
    When the admin clicks "Sync from n8n" for the "nextcloud:alpha" mapping
    Then each "nextcloud:alpha" workflow reappears as a file in the folder
    And no file is duplicated
