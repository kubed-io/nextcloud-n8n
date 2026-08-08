# Notes, decisions and history for this feature: ../AGENTS.md#workflowsopen-with

Feature: Opening a workflow file (Open in n8n / Open with text editor)
  As a Nextcloud user
  I want the right openers for a workflow file, defaulting to the right one for its mode
  So that I'm never sent to a workflow that isn't there and can always edit the JSON.

  Background:
    Given the app is connected to n8n

  # ── Open in n8n ───────────────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Open in n8n is offered exactly when a live workflow exists
    Given a managed workflow file in "<mode>" mode
    When I look at its context menu
    Then "Open in n8n" is <offered or hidden>

    Examples: the two modes that name a live workflow, and the two that do not
      | mode     | offered or hidden |
      | sync     | offered           |
      | link     | offered           |
      | unmapped | hidden            |
      | ignored  | hidden            |

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
