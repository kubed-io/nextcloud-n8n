# Reserved n8n tags — optional, per-workflow fine-grained control.
#
# A mapping binds ONE n8n tag (ANY name — e.g. "team:flows", "myfoobarflows"; the
# "nextcloud:" prefix some examples use is just a convention, NOT required) to a
# folder + a default mode. On top of that default, a small set of reserved tags
# lets you override or exclude a SINGLE workflow without touching the mapping:
#
#   n8n:sync    — pull this one as sync,  whatever the mapping default is
#   n8n:link    — pull this one as a link, whatever the mapping default is
#   n8n:ignore  — skip this one entirely, even though it carries the mapped tag
#
# The mode tags are the SAME vocabulary the app already stamps on every managed
# file in Nextcloud (`n8n:sync` / `n8n:link`): one mode language, two sides — but
# with OPPOSITE authority:
#   - n8n side  — OPTIONAL + hand-set. The app NEVER writes these onto workflows in
#     n8n; it only READS them (if present) as a per-workflow override/ignore at pull
#     time. You add them yourself when you want the exception.
#   - Nextcloud side — AUTHORITATIVE + automatic. The app keeps each managed file's
#     `n8n:sync` / `n8n:link` system tag correct, always matching the file's mode
#     metadata (see the Tagging feature / file-type.feature). You don't touch these.
#
# So they are 100% optional: the mapping default does everything on its own; the
# n8n-side reserved tags are just the escape hatch.
#
# @todo until reserved-tag handling is wired + asserted (saga Chapter 2 §14). CI skips @todo.

@todo
Feature: Reserved n8n tags override the mapping default per workflow
  As an n8n admin
  I want to override the mode of, or exclude, individual workflows with reserved tags
  So that one mapping can still carry per-workflow exceptions

  Background:
    Given the app is connected to n8n
    And a folder mapped as "sync" to the n8n tag "team:flows"

  Scenario: With no reserved tag, a workflow takes the mapping's default mode
    Given n8n has a workflow tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then that workflow's file is in "sync" mode (the mapping default)

  Scenario: n8n:link overrides a sync mapping for one workflow
    Given n8n has a workflow tagged "team:flows" and "n8n:link"
    When the "team:flows" mapping is pulled
    Then that workflow's file is in "link" mode
    And sibling "team:flows" workflows without an override stay "sync"

  Scenario: n8n:sync overrides a link mapping for one workflow
    Given a folder mapped as "link" to the n8n tag "team:links"
    And n8n has a workflow tagged "team:links" and "n8n:sync"
    When the "team:links" mapping is pulled
    Then that workflow's file is in "sync" mode

  Scenario: n8n:ignore excludes a workflow that otherwise matches
    Given n8n has a workflow tagged "team:flows" and "n8n:ignore"
    When the "team:flows" mapping is pulled
    Then that workflow is not pulled into Nextcloud
    And no file is created for it

  Scenario: A mapping tag needs no "nextcloud:" prefix
    Given a folder mapped as "sync" to the n8n tag "myfoobarflows"
    And n8n has a workflow tagged "myfoobarflows"
    When the "myfoobarflows" mapping is pulled
    Then that workflow's file is created in "sync" mode

  Scenario: The app never writes reserved tags onto n8n workflows
    Given n8n has a workflow tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then the workflow in n8n still carries only its original tags
    And the app has not added any "n8n:sync", "n8n:link", or "n8n:ignore" tag to it
