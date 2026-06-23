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
# server-observable backing the front-end keys off (the n8n_mode DAV value + the
# live/archived workflow state + raw-JSON readability); the opener DECISION logic
# itself is unit-tested in tests/js/files-helpers.test.js. Live for sync + unmapped
# (saga §14.8 A). The `link` rows stay @todo (their decision logic is covered by the
# JS unit tests) and the `ignored` rows wait on Copilot's reserved-tags / ignored
# slice (§14.8 B) — both flip in this same PR once ready. CI skips @todo.

Feature: Opening a workflow file (Open in n8n / Open with text editor)
  As a Nextcloud user
  I want the right openers for a workflow file, defaulting to the right one for its mode
  So that I'm never sent to a workflow that isn't there, and can always edit the JSON

  Background:
    Given the app is connected to n8n

  # ── Open in n8n ───────────────────────────────────────────────────────────────

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

  Scenario Outline: Open with text editor is available on every workflow file
    Given a managed workflow file in "<mode>" mode
    When I choose "Open with text editor" from its context menu
    Then the file's raw JSON opens in the text editor

    Examples:
      | mode     |
      | sync     |
      | unmapped |
      | ignored  |

  # link integration is uncertain (it has no create-on-land path); its opener
  # logic is covered concretely by tests/js/files-helpers.test.js instead.
  @todo
  Scenario Outline: Open with text editor — link (covered by JS unit tests)
    Given a managed workflow file in "<mode>" mode
    When I choose "Open with text editor" from its context menu
    Then the file's raw JSON opens in the text editor

    Examples:
      | mode |
      | link |

  # ── Default click action follows the mode ───────────────────────────────────────

  Scenario Outline: The default click opens the right thing for the mode
    Given a managed workflow file in "<mode>" mode
    When I click the file in the Files app
    Then it opens with "<opener>" by default

    Examples:
      | mode     | opener      |
      | sync     | n8n         |
      | unmapped | text editor |
      | ignored  | text editor |

  # link: covered by the JS unit tests (see note above).
  @todo
  Scenario Outline: The default click — link (covered by JS unit tests)
    Given a managed workflow file in "<mode>" mode
    When I click the file in the Files app
    Then it opens with "<opener>" by default

    Examples:
      | mode | opener |
      | link | n8n    |
