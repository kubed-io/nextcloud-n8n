# Notes, decisions and history for this feature: ../AGENTS.md#workflowscopy

Feature: Copying a workflow file always makes a new instance
  As a Nextcloud user
  I want a copy to be a fresh workflow, never a hijack of the original
  So that duplicating a file is safe and predictable

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | nextcloud:demo |
      | folder  | Demo           |
      | mode    | sync           |
      | storage | admin folder   |
    And a mapping with the following values:
      | tag     | nextcloud:pointers |
      | folder  | Pointers           |
      | mode    | link               |
      | storage | admin folder       |
    And a mapping with the following values:
      | tag     | nextcloud:shared |
      | folder  | Shared           |
      | mode    | sync             |
      | storage | team folder      |
      | groups  | admin            |
    And a folder "Scratch" that is not mapped

    # ── RULE: the copy belongs to where it LANDS, never to where it came from ──
    # notes: ../AGENTS.md#the-copy-belongs-to-where-it-lands

  @user @in-nextcloud @gesture @ui
  Scenario Outline: A copy landing in a mapped folder is a brand-new workflow there
    Given a workflow file in "<source>"
    When I copy the file into "<destination>"
    Then the copy holds this DAV metadata:
      | n8n_id         | its own, not the original's |
      | n8n_mapping    | the mapping's id            |
      | n8n_mode       | the mapping's mode          |
      | n8n_versionId  | set                         |
      | n8n_syncedHash | set                         |
    And the copy's workflow carries the "<destination>" mapping tag, and no other mapping's
    And the original file and its workflow are unchanged

    Examples: within one mapping, the binding is simply kept
      | source  | destination |
      | Demo    | Demo        |
      | Scratch | Demo        |

    Examples: and across mappings it is REPLACED — the copy belongs where it landed
      | source   | destination |
      | Demo     | Pointers    |
      | Demo     | Shared      |
      | Pointers | Demo        |

  # notes: ../AGENTS.md#a-copy-made-in-nextcloud-is-named-by-nextcloud
  @user @in-nextcloud @gesture @ui
  Scenario Outline: A copy is named by Nextcloud, and that is its name everywhere
    Given a workflow file named "Fleet Health.n8n" in "<source>"
    When I copy the file into "Demo"
    Then the copy holds this DAV metadata:
      | filename         | "<copy>"                    |
      | name in the file | "<named>"                   |
      | name in n8n      | "<named>"                   |
      | n8n_id           | its own, not the original's |
    And the original file and its workflow are unchanged

    Examples: a copy landing beside its source is named by Nextcloud, and that is its name everywhere
      | source  | copy                 | named            |
      | Demo    | Fleet Health (1).n8n | Fleet Health (1) |
      | Scratch | Fleet Health.n8n     | Fleet Health     |

    # ── RULE: a workflow duplicated in n8n keeps the name n8n gave it ──────────

  # notes: ../AGENTS.md#a-copy-made-in-n8n-is-named-by-n8n
  @n8n @in-n8n @gesture @ui
  Scenario: A workflow duplicated in n8n arrives as its own file
    Given a workflow file named "Fleet Health.n8n" in "Demo"
    When someone duplicates its workflow in n8n, keeping the name
    Then the duplicate arrives as its own file in "Demo"
    And the copy holds this DAV metadata:
      | filename         | "Fleet Health (1).n8n"      |
      | name in the file | "Fleet Health"              |
      | name in n8n      | "Fleet Health"              |
      | n8n_id           | its own, not the original's |
      | n8n_mapping      | the mapping's id            |
      | n8n_mode         | the mapping's mode          |
    And the original file and its workflow are unchanged

  # notes: ../AGENTS.md#the-second-suffix-and-the-pull-that-used-to-fight-it
  @n8n @in-n8n @gesture @ui
  Scenario: Three workflows in n8n wearing one name
    Given a workflow file named "Fleet Health.n8n" in "Demo"
    When someone duplicates its workflow in n8n, keeping the name
    And someone duplicates its workflow in n8n, keeping the name
    Then "Demo" holds one file per workflow, named:
      | Fleet Health.n8n     |
      | Fleet Health (1).n8n |
      | Fleet Health (2).n8n |
    And all three workflows are still named "Fleet Health" in n8n

  # notes: ../AGENTS.md#a-copy-carries-the-tags-that-travelled-in-its-body
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A copy carries the tags that travelled in its body
    Given a workflow file in "Demo" whose tags are "prod, billing"
    When I copy the file into "Shared"
    Then the copy's normal tags are "prod, billing" in n8n and in Nextcloud
    And the copy's workflow carries the "Shared" mapping tag, and no other mapping's

  # notes: ../AGENTS.md#a-copy-landing-outside-every-mapping-is-a-plain-document
  @user @in-nextcloud @gesture @ui
  Scenario Outline: A copy landing outside every mapping is a plain document
    Given a workflow file in "<source>"
    When I copy the file into "Scratch"
    Then the copy holds no n8n DAV metadata at all
    And no workflow is created in n8n for the copy
    And the copy's body is byte-for-byte the original's
    And the copy's pills match its body
    And the original file and its workflow are unchanged

    Examples: the identity is stripped; the labels the file carries are not
      | source   |
      | Demo     |
      | Pointers |
      | Scratch  |

