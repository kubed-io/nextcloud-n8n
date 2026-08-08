# Notes, decisions and history for this feature: ../AGENTS.md#workflowstags

Feature: A workflow's tags and its Nextcloud system tags stay one set
  As an n8n admin browsing workflows in Nextcloud
  I want each workflow's n8n tags mirrored as Nextcloud system tags and back
  So that the mirror is as searchable as n8n and I can re-tag from either side

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "flows"

    # ══ ADOPTION LIVES IN create-workflow.feature ══════════════════════════════
    # notes: ../AGENTS.md#workflowstags

    # ══ THE MAP: FOUR SURFACES, THREE DIRECTIONS, AND ONE AMBIGUITY ════════════

    # ── DIRECTION 1: n8n → Nextcloud ────────────────────────────────────────────
    # n8n is authoritative; a pull carries its tags to BOTH Nextcloud surfaces and
    # re-stamps `agreed`.

  @n8n @in-n8n @ui @occ
  Scenario: A tag added in n8n reaches both Nextcloud surfaces
    Given the tag state starts as n8n "flows,linux" / pills "flows,linux" / body "flows,linux" / agreed "flows,linux"
    When the tag "prod" is added to the workflow in n8n
    And the "flows" mapping is pulled
    Then the tag state is n8n "flows,linux,prod" / pills "flows,linux,prod" / body "flows,linux,prod" / agreed "flows,linux,prod"
    # All four move together, so nothing is left disagreeing.

  @n8n @in-n8n @ui @occ
  Scenario: A tag removed in n8n is removed from both Nextcloud surfaces
    Given the tag state starts as n8n "flows,linux,old" / pills "flows,linux,old" / body "flows,linux,old" / agreed "flows,linux,old"
    When the tag "old" is removed from the workflow in n8n
    And the "flows" mapping is pulled
    Then the tag state is n8n "flows,linux" / pills "flows,linux" / body "flows,linux" / agreed "flows,linux"
    # `agreed` is what makes this a REMOVE rather than "Nextcloud has an extra tag":
    # `old` was in the baseline, so exactly one side dropped it, and that side wins.

    # notes: ../AGENTS.md#a-pill-added-in-nextcloud-reaches-n8n-and-the-file-body

  @admin @in-nextcloud @gesture @ui
  Scenario: A pill added in Nextcloud reaches n8n and the file body
    Given the push timing is "sync"
    And the tag state starts as n8n "flows,linux" / pills "flows,linux" / body "flows,linux" / agreed "flows,linux"
    When the admin adds the Nextcloud system tag "prod" to the file
    Then the tag state is n8n "flows,linux,prod" / pills "flows,linux,prod" / body "flows,linux,prod" / agreed "flows,linux,prod"

  @admin @in-nextcloud @gesture @ui
  Scenario: A pill removed in Nextcloud is removed from n8n and the file body
    Given the push timing is "sync"
    And the tag state starts as n8n "flows,linux,old" / pills "flows,linux,old" / body "flows,linux,old" / agreed "flows,linux,old"
    When the admin removes the Nextcloud system tag "old" from the file
    Then the tag state is n8n "flows,linux" / pills "flows,linux" / body "flows,linux" / agreed "flows,linux"

    # notes: ../AGENTS.md#the-body-never-disagrees-with-the-pills-whatever-moved

    # notes: ../AGENTS.md#a-tag-typed-into-the-file-reaches-n8n-and-the-pills

  @admin @in-nextcloud @gesture @ui
  Scenario: A tag typed into the file reaches n8n and the pills
    Given the push timing is "sync"
    And the tag state starts as n8n "flows,linux" / pills "flows,linux" / body "flows,linux" / agreed "flows,linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the tag state is n8n "flows,linux,prod" / pills "flows,linux,prod" / body "flows,linux,prod" / agreed "flows,linux,prod"
    # The body edit is written as a BARE {"name": …} with no id — exactly what a human
    # types — so this also proves the name-only shorthand works end to end.

  @admin @in-nextcloud @gesture @ui
  Scenario: A tag deleted from the file is removed from n8n and the pills
    Given the push timing is "sync"
    And the tag state starts as n8n "flows,linux,old" / pills "flows,linux,old" / body "flows,linux,old" / agreed "flows,linux,old"
    When the admin edits the file body's "tags" array to "flows" and "linux"
    Then the tag state is n8n "flows,linux" / pills "flows,linux" / body "flows,linux" / agreed "flows,linux"
    # notes: ../AGENTS.md#a-tag-deleted-from-the-file-is-removed-from-n8n-and-the-pills

    # notes: ../AGENTS.md#a-save-that-did-not-touch-the-tags-must-not-undo-a-pill-edit

  @admin @in-nextcloud @gesture @ui
  Scenario: A save that did not touch the tags must not undo a pill edit
    Given the push timing is "sync"
    And the tag state starts as n8n "flows,linux" / pills "flows,linux" / body "flows,linux" / agreed "flows,linux"
    And the admin adds the Nextcloud system tag "prod" to the file
    And I note the current tag state
    When the admin edits the workflow's nodes and saves, leaving the tags array alone
    Then the tag state is unchanged
    # notes: ../AGENTS.md#a-save-that-did-not-touch-the-tags-must-not-undo-a-pill-edit

    # notes: ../AGENTS.md#tagging-an-unmapped-workflow-file-keeps-its-body-and-pills-in-step

  @admin @in-nextcloud @gesture @ui @todo
  Scenario: Tagging an unmapped workflow file keeps its body and pills in step
    Given an untracked ".n8n.json" file outside every mapped folder
    When the admin adds the Nextcloud system tag "prod" to the file
    Then the file body's "tags" array becomes "prod"
    And n8n is not contacted
    And the tag is recorded by name only, with no id

  @admin @in-nextcloud @gesture @ui @todo
  Scenario: Editing an unmapped file's tags array keeps its pills in step
    Given an untracked ".n8n.json" file outside every mapped folder
    When the admin adds the tag "prod" to the file body and saves
    Then the file has the Nextcloud system tag "prod"
    And n8n is not contacted

  @admin @in-nextcloud @gesture @ui @todo
  Scenario: Untagging an unmapped workflow file keeps its body and pills in step
    Given an untracked ".n8n.json" file outside every mapped folder tagged "prod"
    When the admin removes the Nextcloud system tag "prod" from the file
    Then the file body's "tags" array becomes empty
    And n8n is not contacted
    # Both directions, so neither surface can drift while the file waits outside.

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Moving an untracked tagged file into a mapping creates it in n8n with its tags
    Given an untracked ".n8n.json" file outside every mapped folder tagged "prod" and "billing"
    When the file is moved into the "flows" mapped folder
    Then a workflow is created in n8n for it
    And the workflow in n8n is tagged "prod", "billing", and "flows"
    And the file has the Nextcloud system tags "prod", "billing", and "flows"
    # notes: ../AGENTS.md#moving-an-untracked-tagged-file-into-a-mapping-creates-it-in-n8n-with-its-tags

  @user @ui @occ @todo
  Scenario: The tags an adopted file arrives with come back with real ids
    Given an untracked ".n8n.json" file outside every mapped folder tagged "prod"
    And the file has been moved into the "flows" mapped folder
    When the "flows" mapping is pulled
    Then the file body's "tags" array carries "prod" with an n8n id
    # The loose `{"name":"prod"}` the user typed is resolved by n8n, and the pull writes
    # the canonical row back. Nothing corrects the file until n8n has an opinion.

    # notes: ../AGENTS.md#the-tags-an-adopted-file-arrives-with-come-back-with-real-ids

    # ══ STEADY STATE ═══════════════════════════════════════════════════════════

  @n8n @in-n8n @ui @occ
  Scenario: Pull mirrors n8n tags onto the Nextcloud file as system tags
    Given n8n has a workflow tagged "flows", "dns", and "linux"
    When the "flows" mapping is pulled
    Then the workflow's file has the Nextcloud system tags "dns" and "linux"
    And the file can be found by a Nextcloud tag search for "linux"

  @n8n @in-n8n @ui @occ
  Scenario: The reserved namespace is never imported as a content tag
    Given n8n has a workflow tagged "flows", "linux", and "n8n:sync"
    When the "flows" mapping is pulled
    Then the workflow's file has the Nextcloud system tag "linux"
    And the file has no content tag "n8n:sync"

  @n8n @in-n8n @ui @occ
  Scenario: Pull mirrors tags even for a link mapping (searchability, not push)
    Given a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    Then the workflow's file has the Nextcloud system tags "prod" and "dns"
    And the file can be found by a Nextcloud tag search for "prod"

  # notes: ../AGENTS.md#a-pill-added-on-a-link-is-not-pushed-to-n8n-read-only-projection
  @admin @in-nextcloud @gesture @ui @occ
  Scenario: A pill added on a link is not pushed to n8n (read-only projection)
    Given the push timing is "sync"
    And a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    And the admin adds the Nextcloud system tag "local" to the file
    Then the workflow in n8n is tagged "reports", "prod", and "dns"

  @admin @in-nextcloud @gesture @ui @occ
  Scenario: A locally-added pill on a link is wiped on the next pull (n8n is the only writer)
    Given a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    And the admin adds the Nextcloud system tag "local" to the file
    And the "reports" mapping is pulled
    Then the file has no content tag "local"
    And the workflow's file has the Nextcloud system tags "prod" and "dns"
    And the file can be found by a Nextcloud tag search for "prod"

  @n8n @in-n8n @ui @occ
  Scenario: A tag added in n8n lands on the link on the next pull (searchable projection)
    Given a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports", "prod", and "dns"
    When the "reports" mapping is pulled
    And the workflow in n8n now also has "urgent"
    And the "reports" mapping is pulled
    Then the workflow's file has the Nextcloud system tags "prod" and "urgent"
    And the file can be found by a Nextcloud tag search for "urgent"

  @admin @in-nextcloud @gesture @ui @occ
  Scenario: Push writes Nextcloud content tags into n8n (sync only)
    Given a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    And the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"
    And the reserved "n8n:*" tags are not written to n8n

  # notes: ../AGENTS.md#adding-a-pill-pushes-the-tag-to-n8n-immediately-when-timing-is-sync

  @admin @in-nextcloud @gesture @ui
  Scenario: Adding a pill pushes the tag to n8n immediately when timing is "sync"
    Given the push timing is "sync"
    And a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the workflow in n8n is tagged "flows", "linux", and "urgent" without a manual push
    And the workflow's file has the Nextcloud system tag "urgent"

  @admin @in-nextcloud @gesture @ui @occ
  Scenario: Adding a pill queues the tag push when timing is "async"
    Given the push timing is "async"
    And a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then a tag-reconcile job is queued for the file
    And the workflow in n8n is still tagged only "flows" and "linux"
    When the background queue runs
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"

  @admin @in-nextcloud @gesture @ui
  Scenario: Removing a pill removes the tag from n8n on its own
    Given the push timing is "sync"
    And a managed "sync" file last synced with tags "flows", "linux", and "old"
    When the admin removes the Nextcloud system tag "old" from the file
    Then the workflow in n8n is tagged "flows" and "linux" without a manual push
    And the file has no content tag "old"

  @admin @in-nextcloud @gesture @ui @todo
  Scenario: A bare {name} tag added in the body gains its n8n id
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the file body's "tags" array becomes "flows", "linux", and "prod"
    And every tag in the file body carries an n8n id

  @admin @in-nextcloud @gesture @ui @todo
  Scenario: Removing the mapping-tag from the file body does not unbind the workflow
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to only "linux"
    Then the file's Nextcloud system tags still include "flows"
    And the file stays mapped to "flows"

  # notes: ../AGENTS.md#with-no-nextcloud-edit-a-file-that-disagrees-with-n8n-loses

  @user @ui @occ @todo
  Scenario: With no Nextcloud edit, a file that disagrees with n8n loses
    Given a managed "sync" workflow file in "flows" whose body's tags array reads "flows" and "linux"
    And the workflow in n8n is tagged "flows", "linux", and "prod"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are "flows", "linux", and "prod"
    And the file body's "tags" array becomes "flows", "linux", and "prod"

  @user @in-nextcloud @ui @occ
  Scenario: A tag added in Nextcloud since the last sync is added in n8n
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n still has only "flows" and "linux"
    When the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"

  @n8n @in-n8n @ui @occ
  Scenario: A tag removed in n8n since the last sync is removed in Nextcloud
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the workflow in n8n now has only "flows" and "linux"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are exactly "flows" and "linux"

  # notes: ../AGENTS.md#a-content-change-pulls-the-new-body-and-then-reconciles-the-tags
  @n8n @in-n8n @ui @occ
  Scenario: A content change pulls the new body and then reconciles the tags
    Given a managed "sync" workflow file in "flows" whose body and tags match n8n
    But the workflow's nodes changed in n8n since the last sync
    When the "flows" mapping is pulled
    Then the file is rewritten
    And the file body is updated from n8n
    And the file's Nextcloud system tags match the workflow's n8n tags
    # notes: ../AGENTS.md#a-content-change-pulls-the-new-body-and-then-reconciles-the-tags
    And the file's modification time is when the workflow last changed in n8n

  # A tags-only change still writes the body — the body IS the n8n row, so the new
  # tag lands in its `tags` array. What matters is that nothing ELSE moved with it.
  @n8n @in-n8n @ui @occ
  Scenario: A tags-only change in n8n reaches the pills and the tags array, and nothing else
    Given a managed "sync" workflow file in "flows" whose body and tags match n8n
    But the workflow in n8n gained the tag "prod" since the last sync
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags still include "prod"
    And the file body's "tags" array includes "prod"
    And the rest of the body is unchanged

  @admin @in-nextcloud @gesture @ui @occ
  Scenario: A tag removed in Nextcloud since the last sync is removed in n8n
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the admin removes the Nextcloud system tag "old" from the file
    And the workflow in n8n still has "flows", "linux", and "old"
    When the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows" and "linux"
    And the "old" tag is gone from n8n

  @user @in-nextcloud @ui @occ
  Scenario: Independent changes on both sides both survive a reconcile
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n now also has "prod"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "flows", "linux", "urgent", and "prod"

  @user @in-nextcloud @ui @occ
  Scenario: An add on one side and an unrelated remove on the other both apply
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n now has only "flows" and "linux"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "flows", "linux", and "urgent"
    And the "old" tag is gone from both sides

  # ── mapping-tag protection (the n8n-only hazard) ──────────────────────────────

  @admin @in-nextcloud @gesture @ui @occ
  Scenario: Removing the mapping-tag pill alone does not unbind the workflow
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin removes the Nextcloud system tag "flows" from the file
    And the "flows" mapping is reconciled
    Then the file still carries the "flows" system tag
    And the workflow in n8n still carries the "flows" tag
    And the file is still bound to the "flows" mapping

  # notes: ../AGENTS.md#moving-the-file-out-is-the-sanctioned-unmap-it-changes-no-tags
  @user @in-nextcloud @gesture @ui
  Scenario: Moving the file out is the sanctioned unmap — it changes no tags
    Given a managed "sync" workflow file in "flows" tagged "flows"
    When the file is moved out of the "flows" mapped folder
    Then the file becomes "unmapped"
    And the file still carries the "flows" system tag
    And the workflow in n8n still carries the "flows" tag

  # notes: ../AGENTS.md#editing-tags-on-an-unmapped-file-keeps-nextcloud-in-step-and-leaves-n8n-alone
  @admin @in-nextcloud @gesture @ui
  Scenario: Editing tags on an unmapped file keeps Nextcloud in step and leaves n8n alone
    Given the push timing is "sync"
    And a workflow file that has become "unmapped"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then no tag push to n8n is triggered
    And no tag-push job is queued
    And the body agrees with the pills

  @admin @in-nextcloud @gesture @ui @occ
  Scenario: Ejecting via n8n:ignore keeps the file instead of pruning it
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin tags the file "n8n:ignore"
    And the "flows" mapping is reconciled
    Then the file becomes "ignored"
    And the file is kept as a standalone copy, not pruned
    And "n8n:ignore" is never written to n8n as a content tag

  @admin @ui @unbuilt
  Scenario: Removing the mapping pill as a deliberate eject is paired with n8n:ignore
    # notes: ../AGENTS.md#removing-the-mapping-pill-as-a-deliberate-eject-is-paired-with-n8nignore
    Given a managed "sync" workflow file in "flows" tagged "flows"
    When the admin removes the mapping-tag pill "flows" as an eject gesture
    Then the file is tagged "n8n:ignore" and becomes "ignored"
    And the file is kept, not pruned

  # ── pruning: edges are swept, catalog definitions are not ─────────────────────

  @admin @in-nextcloud @gesture @ui @occ
  Scenario: A dropped tag is pruned from the mirror edge, not from the shared catalog
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the Nextcloud system tag "old" is also pinned on an unrelated non-workflow file
    When the admin removes the "old" pill from the workflow file
    And the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows" and "linux"
    And the "old" system-tag definition still exists
    And the unrelated file still carries the "old" pill

  @n8n @in-n8n @ui @occ
  Scenario: A workflow that loses the mapping tag in n8n loses its mirror
    Given a managed "sync" workflow file in "flows" tagged "flows"
    When the "flows" tag is removed from the workflow in n8n
    And the "flows" mapping is synced
    Then the file is pruned from the folder
    # notes: ../AGENTS.md#a-workflow-that-loses-the-mapping-tag-in-n8n-loses-its-mirror

  @n8n @in-n8n @ui @occ
  Scenario: Reconcile never mints a definition it is about to drop
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the workflow in n8n now has only "flows" and "linux"
    When the "flows" mapping is reconciled
    Then no new tag definition is created on either side

  @user @occ @unbuilt
  Scenario: An optional catalog sweep keeps any tag still used on either side
    Given a non-reserved tag "shared" that is orphaned in Nextcloud
    But the tag "shared" is still on a workflow in n8n
    When an admin runs the optional catalog sweep
    Then the "shared" definition is kept on both sides

  @user @occ @unbuilt
  Scenario: An optional catalog sweep never removes a reserved or mapping tag
    Given the reserved definition "n8n:sync" and the mapping-tag definition "flows" exist
    When an admin runs the optional catalog sweep
    Then the "n8n:sync" definition is kept
    And the "flows" mapping-tag definition is kept

  # notes: ../AGENTS.md#one-workflow-with-two-mapping-tags-is-mirrored-into-both-mapped-folders
  @unbuilt
  Scenario: One workflow with two mapping tags is mirrored into both mapped folders
    Given a folder mapped as "sync" to the n8n tag "flows"
    And a folder mapped as "sync" to the n8n tag "reports"
    And n8n has one workflow tagged both "flows" and "reports"
    When both mappings are pulled
    Then the workflow appears as a file in the "flows" folder
    And the workflow appears as a file in the "reports" folder
    And both files carry the same workflow id

  @admin @in-nextcloud @gesture @ui @unbuilt
  Scenario: Editing tags on one mirror should converge its sibling (future fan-out)
    Given one n8n workflow mirrored as a file in both the "flows" and "reports" folders
    When the admin adds the Nextcloud system tag "urgent" to the "flows" mirror
    Then the workflow in n8n is tagged "flows", "reports", and "urgent"
    And the "reports" mirror should also show the "urgent" pill once its mapping next syncs
    # notes: ../AGENTS.md#editing-tags-on-one-mirror-should-converge-its-sibling-future-fan-out

  @admin @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: A sibling mapping's tag is protected on every mirror (future cross-mapping guard)
    # notes: ../AGENTS.md#a-sibling-mappings-tag-is-protected-on-every-mirror-future-cross-mapping-guard
    Given one n8n workflow mirrored as a file in both the "flows" and "reports" folders
    When the admin removes the "reports" pill from the "flows" mirror
    And the "flows" mapping is pushed
    Then the workflow in n8n still carries the "reports" tag
    And the "reports" mirror is still bound to its mapping
