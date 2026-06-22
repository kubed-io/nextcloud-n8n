# The custom mimetype makes a workflow a first-class FILE TYPE: its own mimetype,
# its own icon, DAV-exposed (and read-only) metadata, and — because n8n_mode is
# indexed — it's queryable. (What happens when you OPEN one is the related but
# separate "open with" concern; see open-with.feature.)

@todo
Feature: n8n workflow is a first-class file type
  As a Nextcloud user
  I want .n8n.json files to be a real, purpose-built file type
  So that they have the right mimetype + icon, expose their state, and are queryable

  Scenario: Workflow files get the custom mimetype and n8n icon
    Given a managed workflow file
    Then its mimetype is "application/n8n+json"
    And the Files app shows the n8n icon instead of a generic JSON icon

  Scenario: WebDAV PROPFIND exposes the workflow metadata in the XML
    Given a managed workflow file
    When a WebDAV client requests the file's properties (PROPFIND)
    Then the raw XML includes:
      | property                  |
      | nc:metadata-n8n_id        |
      | nc:metadata-n8n_mode      |
      | nc:metadata-n8n_versionId |
      | nc:metadata-n8n_mapping   |

  Scenario Outline: The mode property carries the descriptive value
    Given a managed "<mode>" workflow file
    Then its "nc:metadata-n8n_mode" property is "<dav value>"

    Examples:
      | mode     | dav value |
      | sync     | sync      |
      | link     | reference |
      | unmapped | unmapped  |
      | ignored  | ignored   |
    # "reference" is the on-the-wire value for link mode — the literal string "link"
    # is is_callable() and crashes core PROPFIND, so it's stored as "reference".

  Scenario: The metadata is read-only over DAV
    Given a managed workflow file
    When a client tries to change "nc:metadata-n8n_id" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties

  Scenario: Files are queryable by their indexed mode
    Given a "sync" workflow file and a "link" workflow file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-n8n_mode" is "sync"
    Then only the sync file is returned
    # n8n_mode is indexed → "find every sync / unmapped / ignored file" is a fast query.
