# "Open with" — the openers offered for a managed workflow file, and which one is
# the default click. RELATED to the file type (file-type.feature: it's *because*
# `.n8n.json` is a first-class type that we get custom openers) but a distinct
# concern, because the opener set + default depend on the file's MODE, not its type.
#
# Two openers:
#   - "Open in n8n"          — jumps to the live workflow in n8n. Only meaningful for
#                              sync/link; hidden for unmapped/ignored (nothing to open).
#   - "Open with text editor" — edits the raw JSON. ALWAYS available on any workflow
#                              file; it's the default for unmapped/ignored.
# Default click: sync/link → Open in n8n; unmapped/ignored → text editor.
# (Whether editing+saving pushes to n8n follows the file's mode — see
# create-workflow.feature / rename.feature / the bidirectional sync, not here.)
#
# Behat can't click the Files-app JS, so the integration steps assert the
# server-observable the front-end keys off (the n8n_mode DAV value + the
# live/archived workflow state + raw-JSON readability); the opener DECISION logic
# itself is unit-tested in tests/js/files-helpers.test.js.
#
# `link` is a ROW, not a separate scenario. It sat in its own @todo outline for
# "link integration is uncertain" while two other files were arranging a link file
# and running green — a stale reason nobody re-checked. It is one rule over four
# modes, so it belongs in the Examples table with the rest; splitting it hid that
# the only thing missing was the row.

Feature: Opening a workflow file (Open in n8n / Open with text editor)
  As a Nextcloud user
  I want the right openers for a workflow file, defaulting to the right one for its mode
  So that I'm never sent to a workflow that isn't there, and can always edit the JSON

  Background:
    Given the app is connected to n8n

  # ── Open in n8n ───────────────────────────────────────────────────────────────

  @in-nextcloud @gesture
  Scenario: Open in n8n opens the live workflow (sync)
    Given a managed workflow file in "sync" mode with a live workflow in n8n
    When I choose "Open in n8n" from its context menu
    Then n8n opens at that workflow (not a download, not the text editor)

  Scenario: Open in n8n is hidden when there is no live workflow (unmapped)
    Given a managed workflow file in "unmapped" mode
    Then "Open in n8n" is hidden from its context menu

  Scenario: Open in n8n is hidden when there is no live workflow (ignored)
    Given a managed workflow file in "ignored" mode
    Then "Open in n8n" is hidden from its context menu

  # ── Open with text editor ──────────────────────────────────────────────────────

  @in-nextcloud @gesture
  Scenario Outline: Open with text editor is available on every workflow file
    Given a managed workflow file in "<mode>" mode
    When I choose "Open with text editor" from its context menu
    Then the file's raw JSON opens in the text editor

    Examples:
      | mode     |
      | sync     |
      | link     |
      | unmapped |
      | ignored  |

  # ── Default click action follows the mode ───────────────────────────────────────

  @in-nextcloud @gesture
  Scenario Outline: The default click opens the right thing for the mode
    Given a managed workflow file in "<mode>" mode
    When I click the file in the Files app
    Then it opens with "<opener>" by default

    Examples:
      | mode     | opener      |
      | sync     | n8n         |
      | link     | n8n         |
      | unmapped | text editor |
      | ignored  | text editor |
