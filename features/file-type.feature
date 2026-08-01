# The custom mimetype makes a workflow a first-class FILE TYPE: its own mimetype,
# its own icon, DAV-exposed (and read-only) metadata, and — because n8n_mode is
# indexed — it's queryable. (What happens when you OPEN one is the related but
# separate "open with" concern; see open-with.feature.)
#
# Live for the WebDAV-observable surface (saga §14.9): the custom mimetype, the
# four nc:metadata-* props exposed in PROPFIND, the descriptive n8n_mode value for
# sync/unmapped/ignored, and the read-only (PROPPATCH-rejected) guarantee.
#
# Two rows are not live, for two DIFFERENT reasons — which is the whole point of
# having more than one status tag (features/README.md):
#   - the `link` row is @todo — the code exists and other files exercise a link
#     file live; only this assertion is unwritten.
#   - the REPORT-by-indexed-mode query is @blocked — the DAV search plumbing for
#     `nc:metadata-*` is unproven, and that is a capability, not a missing test.

Feature: n8n workflow is a first-class file type
  As a Nextcloud user
  I want .n8n.json files to be a real, purpose-built file type
  So that they have the right mimetype + icon, expose their state, and are queryable

  Background:
    Given the app is connected to n8n

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
    Given a managed workflow file in "<mode>" mode
    Then its "nc:metadata-n8n_mode" property is "<dav value>"

    Examples:
      | mode     | dav value |
      | sync     | sync      |
      | unmapped | unmapped  |
      | ignored  | ignored   |

  # link stores as "reference" — the literal "link" is `is_callable()` → crashes
  # core PROPFIND, which is why the wire value differs from the mode name at all.
  #
  # STALE REASON, CORRECTED. This said "link integration is uncertain (no
  # create-on-land path)" and stayed skipped on that basis — while delete.feature
  # and move.feature were both arranging `a managed "link" workflow file` and
  # running green. The harness can do it; only this assertion is unwritten. That
  # makes it @todo (write the test), and it is a promotion candidate.
  @todo
  Scenario Outline: The mode property carries the descriptive value (link)
    Given a managed workflow file in "<mode>" mode
    Then its "nc:metadata-n8n_mode" property is "<dav value>"

    Examples:
      | mode | dav value |
      | link | reference |

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
