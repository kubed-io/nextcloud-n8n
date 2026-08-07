# Notes, decisions and history for this feature: AGENTS.md#sync-now

Feature: Syncing a mapped n8n tag into Nextcloud
  As an admin who has just mapped a tag
  I want the workflows already in n8n to appear in Nextcloud
  So that the mirror starts out true, however the sync was started

  Background:
    Given the app is connected to n8n

  # ── one behaviour, three ways to start it ──────────────────────────────────
  #
  #   actor        | scope
  #   -------------+---------------------
  #   the admin    | one mapping        the card's "Sync from n8n"
  #   the admin    | every mapping      the section's button
  #   the schedule | every mapping      time as the actor
  #
  # Same pre-state, same post-state. The actor and the scope are the only things
  # that differ, so they are COLUMNS rather than three scenarios. Whether a run is
  # synchronous or queued is a mechanism, and is asserted nowhere.
  #
  # THIS FILE IS THE FIRST SYNC, AND ONLY THAT. Nothing is tracked yet, so whatever
  # is in n8n is simply a Given. A LATER run only has work to do because something
  # changed in n8n — and every one of those is a scenario about the change, not
  # about the sync: a workflow renamed upstream belongs to rename.feature, one
  # deleted upstream to delete.feature, a tag added or dropped upstream to
  # tag-sync.feature. The sync is how those arrive, not what they are.
  # notes: AGENTS.md#sync-now-scope

  @admin @occ @ui
  Scenario Outline: A sync brings the tag's workflows into Nextcloud
    Given a folder mapped as "sync" to the n8n tag "<tag>"
    And n8n has workflows tagged "<tag>"
    When <actor> syncs <scope>
    Then each "<tag>" workflow appears as a file in the mapped folder
    And each file carries its n8n dates
    And each file carries its n8n metadata

    Examples: every way a sync starts
      | actor        | scope         | tag               |
      | the admin    | one mapping   | nextcloud:alpha   |
      | the admin    | every mapping | nextcloud:bravo   |
      | the schedule | every mapping | nextcloud:charlie |

    # A TAG PER ROW, deliberately. A tag may be mapped once, so every row needs its
    # own — and distinct tags stop one row's mirrors being read as the next row's
    # result.
    #
    # THE DATES ARE AN END STATE, not a feature of their own: a mirror wears the
    # workflow's clocks rather than the sync's, and that is true however the sync
    # started. Creation time especially — it is the one clock a later run can never
    # reconstruct, because after this run there is no "before" left to read it
    # from. One reusable sentence, so any later behaviour that produces a mirror
    # can assert it the same way.
    # notes: AGENTS.md#carries-its-n8n-dates
