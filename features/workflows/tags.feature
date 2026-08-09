# Notes, decisions and history for this feature: ../AGENTS.md#workflowstags

Feature: Changing a workflow's tags
  As an n8n admin browsing workflows in Nextcloud
  I want a change I make to a workflow's tags to reach every other surface
  So that the mirror is as searchable as n8n and I can re-tag from either side

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag    | flows |
      | folder | Flows |
      | mode   | sync  |
    And a mapping with the following values:
      | tag    | reports |
      | folder | Reports |
      | mode   | link    |

    # ── RULE: applying a set of tags is ONE gesture, on any surface ─────────────
    # notes: ../AGENTS.md#applying-a-set-of-tags-is-one-gesture

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Changing a workflow's tags in Nextcloud changes them in n8n
    Given a managed "sync" workflow file in "Flows" whose normal tags are "<tags before>"
    When I change the Nextcloud tags to "<tags after>"
    Then the workflow's normal tags are "<tags after>" in Nextcloud
    And the workflow's normal tags are "<tags after>" in the file
    And the workflow's normal tags are "<tags after>" in n8n

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
    Given a managed "sync" workflow file in "Flows" whose normal tags are "<tags before>"
    When I change the tags in the file to "<tags after>"
    Then the workflow's normal tags are "<tags after>" in Nextcloud
    And the workflow's normal tags are "<tags after>" in the file
    And the workflow's normal tags are "<tags after>" in n8n

    Examples: the same gesture, typed into the JSON instead of clicked on the file
      | tags before   | tags after    |
      | foo, bar      | foo, bar, qux |
      | foo, bar, baz | baz           |
      | foo           | bar, baz      |
      |               | foo, bar      |
      | foo, bar      |               |

  @n8n @in-n8n @ui @occ
  Scenario Outline: Changing a workflow's tags in n8n changes them in Nextcloud
    Given a managed "sync" workflow file in "Flows" whose normal tags are "<tags before>"
    When the workflow's tags are changed to "<tags after>" in n8n
    Then the workflow's normal tags are "<tags after>" in Nextcloud
    And the workflow's normal tags are "<tags after>" in the file
    And the workflow's normal tags are "<tags after>" in n8n
    And nothing else in the file changed

    Examples: n8n is the system of record, so its set wins outright
      | tags before   | tags after         |
      | foo, bar, baz | foo, bar, baz, qux |
      | foo, bar, baz | foo, bar           |
      | foo, bar      | bar, baz, qux      |
      | foo, bar      |                    |
      |               | foo, bar           |
      | foo, bar, baz | qux, quux          |

    # ── RULE: a change only travels where the mode lets it ─────────────────────

  # notes: ../AGENTS.md#changing-the-tags-on-a-link-does-not-change-them-in-n8n
  @user @in-nextcloud @gesture @ui
  Scenario: Changing the tags on a link does not change them in n8n
    Given a managed "link" workflow file in "Reports" whose normal tags are "prod, dns"
    When I change the Nextcloud tags to "prod, dns, local"
    Then the workflow's normal tags are still "prod, dns" in n8n
    And the file's tags settle back to "prod, dns"
    And the file can be found by a Nextcloud tag search for "prod"

  # notes: ../AGENTS.md#changing-the-tags-on-an-unmapped-file-updates-the-body-tags-too
  @user @in-nextcloud @gesture @ui
  Scenario: Changing the tags on an unmapped file updates the body tags too
    Given a workflow file that has become "unmapped"
    When I change the Nextcloud tags to "urgent"
    Then the workflow's normal tags are "urgent" in Nextcloud
    And the file records the tag "urgent" by name alone, with no id

  # notes: ../AGENTS.md#a-reserved-n8n-tag-never-becomes-a-nextcloud-tag
  @n8n @in-n8n @ui @occ
  Scenario: A reserved "n8n:" tag added in n8n never becomes a Nextcloud tag
    Given a managed "sync" workflow file in "Flows" whose normal tags are "foo"
    When the tag "n8n:sync" is added to the workflow in n8n
    Then the workflow's normal tags are "foo" in Nextcloud
    And the workflow's normal tags are "foo" in the file
    And the workflow's normal tags are "foo" in n8n
    And the file has no content tag "n8n:sync"

    # ── RULE: the mapping tag is the membership, so dropping it leaves ────────
    # notes: ../AGENTS.md#the-mapping-tag-is-the-membership-so-dropping-it-leaves

  @user @in-nextcloud @gesture @ui
  Scenario: Removing the mapping tag in Nextcloud takes the workflow out of the mapping
    Given a managed "sync" workflow file in "Flows" whose normal tags are "bar, baz"
    When I remove the "flows" mapping tag in Nextcloud
    Then the file is gone from the mapped folder
    And the workflow still exists in n8n, with its other tags
    And the workflow's normal tags are "bar, baz" in n8n

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Removing the mapping tag from the file takes it out too
    Given a managed "sync" workflow file in "Flows" whose normal tags are "bar, baz"
    When I remove the "flows" mapping tag from the file
    Then the file is gone from the mapped folder
    And the workflow's normal tags are "bar, baz" in n8n

  @n8n @in-n8n @ui @occ
  Scenario: A workflow that loses its mapping tag in n8n loses its mirror
    Given a managed "sync" workflow file in "Flows" whose normal tags are "bar"
    When the tag "flows" is removed from the workflow in n8n
    Then the file is gone from the mapped folder
    And the workflow still exists in n8n, with its other tags

    # ── one workflow, several mirrors (future fan-out) ─────────────────────────

  # notes: ../AGENTS.md#changing-tags-on-one-mirror-should-converge-its-sibling-future-fan-out
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Changing tags on one mirror should converge its sibling (future fan-out)
    Given one n8n workflow mirrored as a file in both the "Flows" and "Reports" folders
    When I change the Nextcloud tags on the "Flows" mirror to "urgent"
    Then the workflow's normal tags are "urgent" in n8n
    And the "Reports" mirror should also show "urgent" once its mapping next syncs
