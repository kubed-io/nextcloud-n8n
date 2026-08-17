# Notes, decisions and history for this feature: ../AGENTS.md#workflowsmove

Feature: Moving a workflow file is the same workflow leaving and returning
  As a Nextcloud user
  I want moves to mirror as the same workflow in n8n
  So that relocating a file never duplicates or silently desyncs a workflow

  Background:
    Given the app is connected to n8n
    And a mapping with the following values:
      | tag     | alpha        |
      | folder  | Automations  |
      | mode    | sync         |
      | storage | admin folder |
    And a mapping with the following values:
      | tag     | beta        |
      | folder  | Pipelines   |
      | mode    | sync        |
      | storage | team folder |
      | groups  | admin       |
    And a mapping with the following values:
      | tag     | gamma        |
      | folder  | Blueprints   |
      | mode    | sync         |
      | storage | admin folder |
    And a mapping with the following values:
      | tag     | delta       |
      | folder  | Runbooks    |
      | mode    | sync        |
      | storage | team folder |
      | groups  | admin       |
    And a mapping with the following values:
      | tag     | links        |
      | folder  | Pointers     |
      | mode    | link         |
      | storage | admin folder |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a mapping owns its whole subtree, so moving inside it is nothing ──

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a file into a subfolder of its own mapping changes nothing
    Given a workflow file in "Automations"
    When I move the file into a subfolder of "Automations"
    Then the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | the mapping's id  |
      | n8n_mode    | the mapping's mode |
    And nothing changes in n8n

    # ── RULE: leaving a mapping ────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Moving a sync file out of its mapping unmaps it and archives in n8n
    Given a workflow file in "<source>"
    When I move the file into "Scratch"
    Then the file holds this DAV metadata:
      | n8n_id        | the id it arrived with |
      | n8n_versionId | set                    |
      | n8n_mode      | unmapped               |
      | n8n_mapping   | cleared                |
    And the workflow is archived (hidden, preserved) in n8n
    And the full workflow JSON is still in the Nextcloud file

    Examples: from either storage kind, because leaving is leaving
      | source      |
      | Automations |
      | Pipelines   |

  # notes: ../AGENTS.md#a-link-is-not-movable-and-a-link-mapping-is-not-a-destination
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Moving a link, or into a link mapping, is refused
    Given a workflow file in "<source>"
    When I try to move the file into "<destination>"
    Then the move is refused with a message
    And the file stays in "<source>"
    And nothing changes in n8n

    Examples: a link is read-only in Nextcloud, and there is nowhere it may go
      | source   | destination |
      | Pointers | Scratch     |
      | Pointers | Automations |

    Examples: and a link mapping is filled from n8n, whatever is arriving
      | source      | destination |
      | Automations | Pointers    |

    # ── RULE: arriving in a mapping — the same workflow, or a new one ───────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving an unmapped file back into a mapping restores the workflow
    Given an unmapped workflow file that still carries its "n8n_id"
    When I move the file into "Pipelines"
    Then the workflow is unarchived in n8n
    And the file holds this DAV metadata:
      | n8n_id      | the workflow's id |
      | n8n_mapping | the mapping's id  |
      | n8n_mode    | the mapping's mode |

  # notes: ../AGENTS.md#restoring-when-the-n8n-workflow-was-hard-deleted-falls-back-to-create
  @user @in-nextcloud @gesture @ui
  Scenario: Restoring when the n8n workflow was hard-deleted falls back to create
    Given an unmapped workflow file that still carries its "n8n_id"
    And that workflow no longer exists in n8n
    When I move the file into "Pipelines"
    Then a matching workflow is created in n8n
    And the file holds this DAV metadata:
      | n8n_id      | its own, not the one it arrived with |
      | n8n_mapping | the mapping's id                     |
      | n8n_mode    | the mapping's mode                   |

  # notes: ../AGENTS.md#keeping-one-version-of-a-duplicate-leaves-one-file-and-one-workflow
  @user @in-nextcloud @gesture @ui @todo
  Scenario Outline: Keeping one version of a duplicate leaves one file and one workflow
    Given a workflow file named "Turnbuckle.n8n" in "Automations"
    And an unmapped file named "Turnbuckle.n8n" in "Scratch" carrying the same "n8n_id"
    And that file's nodes differ from the workflow's
    When I move the unmapped file into "Automations"
    And I select "<kept>"
    Then "Turnbuckle.n8n" in "Automations" holds the nodes of "<the body that wins>"
    And its workflow in n8n is live and holds those same nodes
    And "Turnbuckle.n8n" in "Automations" holds this DAV metadata:
      | n8n_id      | the id both files carried |
      | n8n_mapping | the mapping's id          |
      | n8n_mode    | the mapping's mode        |
    And the workflow's tags are "alpha" in Nextcloud, in the file and in n8n

    Examples: one workflow either way — the answer only decides whose body it keeps
      | kept                 | the body that wins     |
      | the existing version | the file already there |
      | the new version      | the file that arrived  |

  # notes: ../AGENTS.md#keeping-both-versions-of-a-duplicate-makes-the-arrival-its-own-workflow
  @user @in-nextcloud @gesture @ui
  Scenario: Keeping both versions of a duplicate makes the arrival its own workflow
    Given a workflow file named "Turnbuckle.n8n" in "Automations"
    And an unmapped file named "Turnbuckle.n8n" in "Scratch" carrying the same "n8n_id"
    And that file's nodes differ from the workflow's
    When I move the unmapped file into "Automations"
    And I select "both versions"
    Then "Turnbuckle.n8n" in "Automations" holds this DAV metadata:
      | n8n_id      | the id both files carried |
      | n8n_mapping | the mapping's id          |
      | n8n_mode    | the mapping's mode        |
    And its workflow in n8n is live, named "Turnbuckle", and holds the nodes it always had
    And "Turnbuckle (1).n8n" in "Automations" holds this DAV metadata:
      | n8n_id      | its own, not the one it arrived with |
      | n8n_mapping | the mapping's id                     |
      | n8n_mode    | the mapping's mode                   |
    And its workflow in n8n is live, named "Turnbuckle (1)", and holds the nodes that arrived
    And the tags on both files are "alpha" in Nextcloud, in the file and in n8n

  # notes: ../AGENTS.md#the-tags-a-file-arrives-with-are-the-tags-its-workflow-ends-up-with
  @user @in-nextcloud @gesture @ui
  Scenario Outline: A file arriving from outside every mapping becomes a workflow there
    Given a workflow file in "Scratch" whose tags are "<tags>"
    When I move the file into "<destination>"
    Then a matching workflow is created in n8n
    And the file holds this DAV metadata:
      | n8n_id      | set                |
      | n8n_mapping | the mapping's id   |
      | n8n_mode    | the mapping's mode |
    And the workflow's tags are "<tags after>" in Nextcloud
    And the workflow's tags are "<tags after>" in the file
    And the workflow's tags are "<tags after>" in n8n

    Examples: the tags it arrived carrying, plus the tag of the folder it landed in
      | destination | tags                    | tags after                     |
      | Automations | prod, billing, critical | alpha, billing, critical, prod |
      | Pipelines   | prod                    | beta, prod                     |

    # ── RULE: between two mappings, the binding follows the folder ─────────────

  # notes: ../AGENTS.md#moving-a-workflow-to-another-mapped-folder
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Moving a workflow to another mapped folder rebinds it
    Given a workflow file named "Foo.n8n" in "<source>" whose tags are "prod"
    When I move the file into "<destination>"
    Then the workflow is now under "<destination>"
    And the workflow named "Foo" is no longer under "<source>"
    And the file holds this DAV metadata:
      | n8n_id      | the id it arrived with |
      | n8n_mapping | the mapping's id       |
      | n8n_mode    | the mapping's mode     |
    And the workflow's tags are "<tags after>" in Nextcloud
    And the workflow's tags are "<tags after>" in the file
    And the workflow's tags are "<tags after>" in n8n

    Examples: between the two storage kinds, in both directions
      | source      | destination | tags after  |
      | Automations | Pipelines   | beta, prod  |
      | Pipelines   | Automations | alpha, prod |

    Examples: and between two folders of the same kind
      | source      | destination | tags after  |
      | Automations | Blueprints  | gamma, prod |
      | Pipelines   | Runbooks    | delta, prod |

  # notes: ../AGENTS.md#the-one-move-nextcloud-refuses-before-we-see-it
  @user @in-nextcloud @gesture @ui
  Scenario: Moving out of a Team Folder into a mapping you only have shared is refused
    Given a workflow file in "Pipelines"
    And "Automations" is shared with me rather than owned by me
    When I try to move the file into "Automations"
    Then the move is refused with a message
    And the file stays in "Pipelines"
    And nothing changes in n8n
