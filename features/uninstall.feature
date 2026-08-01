# Uninstall lifecycle — what happens to the SYSTEM and to the user's DATA when the
# app is removed, and that a reinstall reconnects cleanly.
#
#   - SYSTEM: removing the app runs the <uninstall> repair step (UnregisterMimetype),
#     which REVERTS the custom-mimetype registration the install wrote into the
#     Nextcloud core tree (config/mimetype*.json, core/img/filetypes/n8n.svg,
#     core/js/mimetypelist.js) and re-stamps the .n8n.json filecache rows back to
#     application/json. The store's clean-uninstall rule is about this shared state.
#   - DATA: the app ORPHANS the user's data — it never deletes the .n8n.json files,
#     never clears their Files-Metadata, never deletes Team Folders, never touches
#     n8n. A sync folder is a full backup, so deleting it would be data loss. To wipe
#     the Nextcloud side deliberately, an admin uses Purge first (see purge.feature).
#
# Because the files keep their n8n_id, a reinstall + pull RECONCILES them in place
# (matched by id, never duplicated) — the reconnect is free, by design.
#
# The <uninstall> system leg needs a full app remove on a live pod (CI can't drive
# it), so it stays skipped; the data-orphan + reinstall-reconnect legs are provable
# via disable/re-enable + a pull, which exercises the same metadata-keyed reconcile.

# @blocked, NOT @todo, and the missing capability is named: the CI harness can only
# disable and enable the app, never remove and reinstall it. No test anyone writes
# will pass until that exists, which is exactly the distinction the tag makes.
#
# NOTE THE TAG IS ON THE `Feature:`, so it excludes EVERY scenario below, including
# the data-orphan ones a disable/enable could genuinely prove. That is deliberate
# but easy to misread: the DATA promise — reinstall reconciles existing files in
# place by id with NO duplicates — is already proven LIVE by reconcile.feature
# ("existing files are updated in place — matched by workflow id, never
# duplicated"), and a disable/enable changes nothing about that reconcile, so
# re-proving it here would be duplicate coverage of one behaviour in two files.
# If this file is ever un-blocked, delete those scenarios rather than run them.
@blocked
Feature: Uninstall reverts the system and reinstall reconnects the data
  As a Nextcloud admin
  I want removing the app to leave Nextcloud clean and reinstalling to just resync
  So that uninstalling is safe and never costs me data or creates duplicates

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "nextcloud:alpha"

  # ── system cleanup (needs a live app remove — @todo in CI) ────────────────────
  @blocked
  Scenario: Removing the app reverts the custom mimetype registration
    Given the app registered the "application/n8n+json" mimetype on install
    When the app is removed
    Then the mimetype mapping for "n8n.json" is gone from the Nextcloud config
    And the n8n icon is removed from the core filetype icons
    And a ".n8n.json" file resolves to "application/json" again

  # ── data is orphaned, never deleted ───────────────────────────────────────────
  Scenario: Disabling the app leaves the workflow files (and their identity) in place
    Given the "nextcloud:alpha" folder has managed sync workflow files
    When the admin disables the app
    Then the ".n8n.json" files are still in the folder
    And each file still carries its "n8n_id" metadata

  # ── reinstall reconnects with no duplicates (the headline) ────────────────────
  Scenario: Re-enabling and syncing reconciles the existing files without duplicates
    Given the "nextcloud:alpha" folder has managed sync workflow files
    And the admin disables and then re-enables the app
    When the admin clicks "Sync from n8n" for the "nextcloud:alpha" mapping
    Then each existing file is updated in place, matched by its "n8n_id"
    And no file gains a " (2)" collision-suffixed duplicate
