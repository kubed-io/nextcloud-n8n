# Notes, decisions and history for this feature: ../AGENTS.md#workflowstags

Feature: Changing a workflow's tags
  As an n8n admin browsing workflows in Nextcloud
  I want a change I make to a workflow's tags to reach every other surface
  So that the mirror is as searchable as n8n and I can re-tag from either side

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "flows"
    And a folder mapped as "link" to the n8n tag "reports"
    And the push timing is "sync"

    # ── RULE: applying a set of tags is ONE gesture, on any surface ─────────────
    # notes: ../AGENTS.md#applying-a-set-of-tags-is-one-gesture

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Changing a workflow's tags in Nextcloud changes them in n8n
    Given a managed "sync" workflow file in "flows" whose normal tags are "<tags before>"
    When I change the Nextcloud tags to "<tags after>"
    Then the workflow's normal tags are "<tags after>" in n8n and in Nextcloud

    Examples: adding, subtracting, and doing both at once are one gesture
      | tags before   | tags after         |
      | foo, bar, baz | foo, bar, baz, qux |
      | foo, bar, baz | foo, bar           |
      | foo, bar, baz | bar, baz, qux      |
      | foo           | qux, quux          |
      | foo, bar      |                    |
      |               | foo                |

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Changing a workflow's tags in the file changes them in n8n
    Given a managed "sync" workflow file in "flows" whose normal tags are "<tags before>"
    When I change the tags in the file to "<tags after>"
    Then the workflow's normal tags are "<tags after>" in n8n and in Nextcloud

    Examples: the same gesture, typed into the JSON instead of clicked on the file
      | tags before   | tags after    |
      | foo, bar      | foo, bar, qux |
      | foo, bar, baz | baz           |
      | foo           | bar, baz      |
      |               | foo, bar      |
      | foo, bar      |               |

  @n8n @in-n8n @ui @occ
  Scenario Outline: Changing a workflow's tags in n8n changes them in Nextcloud
    Given a managed "sync" workflow file in "flows" whose normal tags are "<tags before>"
    When the workflow's tags are changed to "<tags after>" in n8n
    Then the workflow's normal tags are "<tags after>" in n8n and in Nextcloud
    And nothing else in the file changed

    Examples: n8n is the system of record, so its set wins outright
      | tags before   | tags after         |
      | foo, bar, baz | foo, bar, baz, qux |
      | foo, bar, baz | foo, bar           |
      | foo, bar      | bar, baz, qux      |
      | foo, bar      |                    |
      |               | foo, bar           |
      | foo, bar, baz | qux, quux          |

  # notes: ../AGENTS.md#with-async-timing-the-change-reaches-n8n-on-the-next-queue-tick
  @user @in-nextcloud @gesture @ui @occ
  Scenario: With "async" timing the change reaches n8n on the next queue tick
    Given the push timing is "async"
    And a managed "sync" workflow file in "flows" whose normal tags are "foo, bar"
    When I change the Nextcloud tags to "foo, bar, qux"
    Then the workflow's normal tags are still "foo, bar" in n8n
    And the workflow's normal tags are "foo, bar, qux" in n8n once the queue has run

    # ── RULE: a change only travels where the mode lets it ─────────────────────

  # notes: ../AGENTS.md#changing-the-tags-on-a-link-does-not-change-them-in-n8n
  @user @in-nextcloud @gesture @ui
  Scenario: Changing the tags on a link does not change them in n8n
    Given a managed "link" workflow file in "reports" whose normal tags are "prod, dns"
    When I change the Nextcloud tags to "prod, dns, local"
    Then the workflow's normal tags are still "prod, dns" in n8n
    And the file's tags settle back to "prod, dns"
    And the file can be found by a Nextcloud tag search for "prod"

  # notes: ../AGENTS.md#changing-the-tags-on-an-unmapped-file-never-reaches-n8n
  @user @in-nextcloud @gesture @ui
  Scenario: Changing the tags on an unmapped file never reaches n8n
    Given a workflow file that has become "unmapped"
    When I change the Nextcloud tags to "urgent"
    Then no tag push to n8n is triggered
    And no tag-push job is queued
    And the body agrees with the pills

  # notes: ../AGENTS.md#a-reserved-n8n-tag-never-becomes-a-nextcloud-tag
  @n8n @in-n8n @ui @occ
  Scenario: A reserved "n8n:" tag added in n8n never becomes a Nextcloud tag
    Given a managed "sync" workflow file in "flows" whose normal tags are "foo"
    When the tag "n8n:sync" is added to the workflow in n8n
    Then the workflow's normal tags are "foo" in n8n and in Nextcloud
    And the file has no content tag "n8n:sync"

    # ── RULE: when both sides moved, neither change is thrown away ─────────────
    # notes: ../AGENTS.md#when-both-sides-moved-neither-change-is-thrown-away

  @n8n @in-n8n @ui @occ
  Scenario: A tag added in Nextcloud survives a tag added in n8n
    Given a managed "sync" workflow file in "flows" whose normal tags are "foo, bar"
    And I have changed the Nextcloud tags to "foo, bar, urgent"
    When the tag "prod" is added to the workflow in n8n
    Then the workflow's normal tags are "foo, bar, urgent, prod" in n8n and in Nextcloud

  @n8n @in-n8n @ui @occ
  Scenario: A tag added in Nextcloud survives a different tag being removed in n8n
    Given a managed "sync" workflow file in "flows" whose normal tags are "foo, bar, old"
    And I have changed the Nextcloud tags to "foo, bar, old, urgent"
    When the tag "old" is removed from the workflow in n8n
    Then the workflow's normal tags are "foo, bar, urgent" in n8n and in Nextcloud

    # ── RULE: the mapping tag is the binding, not a label anyone may drop ─────
    # notes: ../AGENTS.md#the-mapping-tag-is-the-binding-not-a-label-anyone-may-drop

  @user @in-nextcloud @gesture @ui
  Scenario: Removing the mapping tag in Nextcloud does not unbind the workflow
    Given a managed "sync" workflow file in "flows" whose normal tags are "bar, baz"
    When I remove the "flows" mapping tag in Nextcloud
    Then the file still carries the "flows" mapping tag
    And the workflow in n8n still carries the "flows" tag
    And the workflow's normal tags are "bar, baz" in n8n and in Nextcloud

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Removing the mapping tag from the file body does not unbind it either
    Given a managed "sync" workflow file in "flows" whose normal tags are "bar, baz"
    When I change the tags in the file to "bar, baz", dropping the "flows" mapping tag
    Then the file still carries the "flows" mapping tag
    And the file stays mapped to "flows"

  @n8n @in-n8n @ui @occ
  Scenario: A workflow that loses its mapping tag in n8n loses its mirror
    Given a managed "sync" workflow file in "flows" whose normal tags are "bar"
    When the tag "flows" is removed from the workflow in n8n
    Then the file is gone from the mapped folder

    # ── RULE: dropping a tag sweeps the edge, never the shared catalog ─────────
    # notes: ../AGENTS.md#dropping-a-tag-sweeps-the-edge-never-the-shared-catalog

  @user @in-nextcloud @gesture @ui
  Scenario: A tag dropped from a workflow survives on the files that still use it
    Given a managed "sync" workflow file in "flows" whose normal tags are "foo, old"
    And the Nextcloud system tag "old" is also pinned on an unrelated non-workflow file
    When I change the Nextcloud tags to "foo"
    Then the workflow's normal tags are "foo" in n8n and in Nextcloud
    And the "old" system-tag definition still exists
    And the unrelated file still carries the "old" pill
    And no new tag definition is created on either side

    # ── one workflow, several mirrors (future fan-out) ─────────────────────────

  # notes: ../AGENTS.md#changing-tags-on-one-mirror-should-converge-its-sibling-future-fan-out
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Changing tags on one mirror should converge its sibling (future fan-out)
    Given one n8n workflow mirrored as a file in both the "flows" and "reports" folders
    When I change the Nextcloud tags on the "flows" mirror to "urgent"
    Then the workflow's normal tags are "urgent" in n8n
    And the "reports" mirror should also show "urgent" once its mapping next syncs
