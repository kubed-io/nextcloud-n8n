# Notes, decisions and history for this feature: AGENTS.md#open-with

Feature: Opening a workflow file (Open in n8n / Open with text editor)
  As a Nextcloud user
  I want the right openers for a workflow file, defaulting to the right one for its mode
  So that I'm never sent to a workflow that isn't there and can always edit the JSON.

  Background:
    Given the app is connected to n8n

  # ── Open in n8n ───────────────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Open in n8n opens the live workflow (sync)
    Given a managed workflow file in "sync" mode with a live workflow in n8n
    When I choose "Open in n8n" from its context menu
    Then n8n opens at that workflow (not a download, not the text editor)

  @user @ui
  Scenario: Open in n8n is hidden when there is no live workflow (unmapped)
    Given a managed workflow file in "unmapped" mode
    Then "Open in n8n" is hidden from its context menu

  @user @ui
  Scenario: Open in n8n is hidden when there is no live workflow (ignored)
    Given a managed workflow file in "ignored" mode
    Then "Open in n8n" is hidden from its context menu

  # ── Open with text editor ──────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui
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

  @user @in-nextcloud @gesture @ui
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
