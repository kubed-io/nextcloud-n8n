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

  # notes: ../AGENTS.md#a-copy-carries-the-tags-that-travelled-in-its-body
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A copy carries the tags that travelled in its body
    Given a workflow file in "Demo" whose tags are "prod, billing"
    When I copy the file into "Shared"
    Then the copy's normal tags are "prod, billing" in n8n and in Nextcloud
    And the copy's workflow carries the "Shared" mapping tag, and no other mapping's

  # notes: ../AGENTS.md#a-copy-landing-outside-every-mapping-keeps-its-tags-as-a-breadcrumb
  @user @in-nextcloud @gesture @ui
  Scenario Outline: A copy landing outside every mapping is a plain document
    Given a workflow file in "<source>"
    When I copy the file into "Scratch"
    Then the copy holds no n8n DAV metadata at all
    And no workflow is created in n8n for the copy
    And the copy's body still carries the tags "<tags left in the body>"
    And the original file and its workflow are unchanged

    Examples: the identity is stripped; the label saying where it came from is not
      | source   | tags left in the body |
      | Demo     | nextcloud:demo        |
      | Pointers | nextcloud:pointers    |
