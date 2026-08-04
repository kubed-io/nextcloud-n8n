# Notes, decisions and history for this feature: AGENTS.md#file-type

Feature: n8n workflow is a first-class file type
  As a Nextcloud user
  I want .n8n.json files to be a real, purpose-built file type
  So that they have the right mimetype + icon, expose their state, and are queryable

  Background:
    Given the app is connected to n8n

  @user @ui
  Scenario: Workflow files get the custom mimetype and n8n icon
    Given a managed workflow file
    Then its mimetype is "application/n8n+json"
    And the Files app shows the n8n icon instead of a generic JSON icon

  @user @in-nextcloud @gesture @ui
  Scenario: WebDAV PROPFIND exposes the workflow metadata in the XML
    Given a managed workflow file
    When a WebDAV client requests the file's properties (PROPFIND)
    Then the raw XML includes:
      | property                  |
      | nc:metadata-n8n_id        |
      | nc:metadata-n8n_mode      |
      | nc:metadata-n8n_versionId |
      | nc:metadata-n8n_mapping   |

  # `link` stores as "reference" — the literal "link" is `is_callable()`, which
  # crashes core's PROPFIND, and that is the only reason a wire value differs from
  # its mode name anywhere in this app.
  @user @ui
  Scenario Outline: The mode property carries the descriptive value
    Given a managed workflow file in "<mode>" mode
    Then its "nc:metadata-n8n_mode" property is "<dav value>"

    Examples:
      | mode     | dav value |
      | sync     | sync      |
      | link     | reference |
      | unmapped | unmapped  |
      | ignored  | ignored   |

  @user @in-nextcloud @gesture @ui
  Scenario: The metadata is read-only over DAV
    Given a managed workflow file
    When a client tries to change "nc:metadata-n8n_id" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties

  # n8n_mode is indexed → "find every sync / unmapped / ignored file" is a fast
  # query. @blocked, and the missing capability is named: there is no proven DAV
  # REPORT search over `nc:metadata-*` to drive this against. Confirm that exists
  # and this becomes an ordinary @todo.
  @blocked
  Scenario: Files are queryable by their indexed mode
    Given a "sync" workflow file and a "link" workflow file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-n8n_mode" is "sync"
    Then only the sync file is returned
