# Bidirectional workflow-tag sync — a workflow's tags and its Nextcloud system
# tags are kept as ONE set, so the mirror is as searchable as n8n.
#
# Two label systems, made equal (minus our control tags):
#
#   • n8n tags       — tags on the workflow (`/api/v1/tags`, opaque ids; the
#                      workflow GET body echoes `tags: [{id,name},...]`). Written
#                      via a SEPARATE call: ensureTag(name)->id, then
#                      setWorkflowTags(id, [ids]) — the body PUT ignores tags.
#   • Nextcloud tags — collaborative SYSTEM TAGS (the coloured pills in Files,
#                      searchable via DAV REPORT).
#
# THE RULE OF EQUALITY: after a reconcile a managed workflow's n8n tags and its
# Nextcloud system tags hold the same strings, with ONE exclusion — the app's
# reserved namespace `n8n:*` (`n8n:sync`, `n8n:link`, `n8n:ignore`, and any future
# control tag). Reserved tags are the app's control plane: never pushed to n8n,
# never imported from n8n as content.
#
# THREE EDIT SURFACES — the object body is the third: tags are part of the object,
# so a sync file's on-disk JSON already has a `tags` array. That makes three
# editable places, kept as one set:
#   1. n8n tags on the workflow    (edit in n8n → pull)
#   2. the file body `tags` array  (edit the JSON → push)
#   3. Nextcloud system-tag pills  (edit the pills → push)
# The FILE BODY is the canonical object; the PILLS are a listener-kept projection.
# Editing either Nextcloud surface updates the other and pushes to n8n; a pull
# writes n8n's tags into the body and reconciles the pills. In `link` mode the body
# is a pointer (not the object), so only surfaces 1 and 3 exist and the pills are a
# read-only projection of n8n.
#
# SEARCHABILITY IS MODE-INDEPENDENT: the pull-side systemtag reconcile runs for
# BOTH `sync` and `link` files. A `link` file is never pushed, so its tags flow one
# way only: n8n → Nextcloud.
#
# PROVENANCE — a new tag from Nextcloud vs a new tag from n8n: when the two sets
# differ on a string you cannot tell an ADD on one side from a REMOVE on the other
# from the current sets alone. So the app banks the reserved-stripped tag set as of
# the last successful sync in `n8n_syncedTags` (the tag analogue of
# `n8n_syncedHash`) and three-way-merges against it: add-on-either-side is additive,
# remove-on-either-side propagates, and the only genuine conflict (same tag added on
# one side, removed on the other) falls to the reconcile's direction of truth —
# pull → n8n wins, push → Nextcloud wins.
#
# MAPPING-TAG PROTECTION (n8n-only): n8n maps a folder BY TAG, so the tag that binds
# a workflow to its folder is itself a content tag. It is shown as a pill for
# visibility but is PROTECTED — removing the pill must NOT push a tag removal that
# would unbind the workflow. To unmap, move the file out (the unmapped path). This
# hazard has no Grafana analogue (Grafana maps by real folders).
#
# DESIGN, NOT WIRED: this feature is @todo — CI skips it — until the tag-reconcile
# engine and the `n8n_syncedTags` baseline key are cooked (saga Ch5 §5.6). Shared
# with the Grafana sibling; per-backend knobs = tag write path, reserved prefix,
# protected-tags set.

@todo
Feature: A workflow's tags and its Nextcloud system tags stay one set
  As an n8n admin browsing workflows in Nextcloud
  I want each workflow's n8n tags mirrored as Nextcloud system tags and back
  So that the mirror is as searchable as n8n and I can re-tag from either side

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "flows"

  Scenario: Pull mirrors n8n tags onto the Nextcloud file as system tags
    Given n8n has a workflow tagged "flows", "dns", and "linux"
    When the "flows" mapping is pulled
    Then the workflow's file has the Nextcloud system tags "dns" and "linux"
    And the file can be found by a Nextcloud tag search for "linux"

  Scenario: The reserved namespace is never imported as a content tag
    Given n8n has a workflow tagged "flows", "linux", and "n8n:sync"
    When the "flows" mapping is pulled
    Then the workflow's file has the Nextcloud system tag "linux"
    And the file has no content tag "n8n:sync"
    And the file's "n8n:sync" mode pill is unaffected

  Scenario: Pull mirrors tags even for a link mapping (searchability, not push)
    Given a folder mapped as "link" to the n8n tag "reports"
    And n8n has a workflow tagged "reports" and "prod"
    When the "reports" mapping is pulled
    Then the link file has the Nextcloud system tag "prod"
    And the file can be found by a Nextcloud tag search for "prod"

  Scenario: Push writes Nextcloud content tags into n8n (sync only)
    Given a managed "sync" workflow file in "flows" with n8n tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    And the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"
    And the reserved "n8n:*" tags are not written to n8n

  Scenario: Editing a pill updates the file body's tags array (body is canonical)
    Given a managed "sync" workflow file in "flows" with body tags "flows" and "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "flows", "linux", and "urgent"

  Scenario: Editing the file body's tags array updates the pills and pushes to n8n
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin edits the file body's "tags" array to "flows", "linux", and "prod"
    Then the file's Nextcloud system tags become "flows", "linux", and "prod"
    And when the "flows" mapping is pushed the workflow in n8n is tagged "flows", "linux", and "prod"

  Scenario: A tag added in Nextcloud since the last sync is added in n8n
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n still has only "flows" and "linux"
    When the "flows" mapping is pushed
    Then the workflow in n8n is tagged "flows", "linux", and "urgent"

  Scenario: A tag removed in n8n since the last sync is removed in Nextcloud
    Given a managed "sync" file last synced with tags "flows", "linux", and "old"
    And the workflow in n8n now has only "flows" and "linux"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are exactly "flows" and "linux"

  Scenario: Independent changes on both sides both survive a reconcile
    Given a managed "sync" file last synced with tags "flows" and "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the workflow in n8n now also has "prod"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "flows", "linux", "urgent", and "prod"

  Scenario: The mapping tag is protected — removing its pill does not unmap
    Given a managed "sync" workflow file in "flows" tagged "flows" and "linux"
    When the admin removes the Nextcloud system tag "flows" from the file
    And the "flows" mapping is pushed
    Then the workflow in n8n still carries the "flows" tag
    And the file is still bound to the "flows" mapping

  Scenario: Unmapping is done by moving the file out, not by removing the tag pill
    Given a managed "sync" workflow file in "flows" tagged "flows"
    When the file is moved out of the "flows" mapped folder
    Then the file becomes "unmapped"
    And the workflow's "flows" tag is handled by the unmap path, not the tag sync
