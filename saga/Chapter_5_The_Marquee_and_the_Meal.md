# Chapter 5 — The Marquee and the Meal

> **Prerequisite:** Chapter 4 (Showtime) ended store-*ready* — polished, branded,
> badged, pipeline built, CSR in flight — on the line *"The marquee can light up in
> Chapter 5."* It did. The countersign landed, the store upload went through, and
> **`n8n_sync` is live on [apps.nextcloud.com](https://apps.nextcloud.com/apps/n8n_sync)** —
> one-click installable by anyone. Chapter 4's Epic 7 (the finale) is **done**. This
> chapter opens the morning after opening night.

Chapters 1–4 were a climb: the vibe, the package, the audition, the show. They end at
a summit — a real product on the real store. Chapter 5 is what a summit is actually
*for*: you look around and realise you're not the only one who climbed. Down the
street a kitchen just turned its lights on. So this chapter is two things at once —
**the after-party for the star** (the small, sharp improvements a shipped app earns
once real users can touch it) and **a cameo**: our hero finally has a peer, and they
sit down to a meal.

> **Dr K, from the good table by the window, glass already poured:** *"You made it.
> Now stop performing for a second and eat. And say hello — the kid from down the
> block cooks your menu now."*

---

## Where we are — 2026-07-23 · **ON THE MARKET, AND NO LONGER ALONE**

> **The app is published. The work since is polish a shipped product invites, a new
> sous-chef in the kitchen (an AI reviewer trained on our house rules), and a
> genuine peer — `nextcloud-grafana` — cooking the same menu one street over.**
>
> - **The marquee is lit.** `n8n_sync` is on apps.nextcloud.com. The whole Chapter-4
>   store machine — signed tarball, GitHub release, store upload — is a *repeatable
>   capability* now, not a one-time stunt. We release when we want.
> - **Everything in the CHANGELOG `[Unreleased]` is post-summit.** Those entries are,
>   by definition, *"what we learned once the app was real and installable."* They are
>   this chapter's substance on the n8n side (§5.1).
> - **The kitchen has a new pair of hands.** We turned on GitHub Copilot code review
>   and *tuned it to our principles* — a reviewer that reads `AGENTS.md` and our
>   instruction files and enforces **be-Nextcloud-native** on every PR (§5.3). It
>   promptly caught real bugs, including some it learned to flag *from rules we wrote
>   this week*.
> - **The cameo is real.** `nextcloud-grafana` — the apprentice from its own saga's
>   Chapter 1 (*Mise en Place*) — is now far enough along that our two stories touch.
>   The headline connection-UX fix this chapter shipped on **both apps, in parity, the
>   same afternoon** (§5.2). The "mother sauce" thesis stopped being a hope and became
>   an observation.

---

## §5.1 — The after-party: what a shipped app taught us

Nothing sharpens a feature like a real person being confused by it. The moment
`n8n_sync` was installable, the connection panel — the very first thing an admin
touches — showed its one rough edge, and the fix turned into the most instructive PHP
lesson since the Chapter-2 code-scanning paydown.

**The problem, in a user's words:** *"I pasted my key. Did it save? The field's
empty."* And it always would be — a **sensitive settings field renders blank even when
a value is stored**, because core (correctly) never echoes a secret back. So the field
alone can't answer "is it set?", and worse, a **wrong** key looked identical to **no**
key: both just failed the connection test with some opaque line.

**What we shipped (the `[Unreleased]` entries):**

- **The card tells you if a key is stored.** `AdminSettings` now reads whether a key
  exists (in `getSchema()`) and renders its copy from that — a plain, reliable
  "is it set?" signal the blank field can't give. (Mirror decision on grafana:
  `ConnectionSettings` does the same.)
- **The test tells *missing* apart from *rejected*.** A shared
  `N8nClient::describeConnectionError()` — used by **both** the Test-connection button
  and `occ n8n_sync:test-connection`, so they can never disagree — says *"add one
  first"* for an unset key and *"HTTP 401 — n8n rejected the API key"* for a bad one.

**The bug hiding under the polish (the real lesson).** The friendly "check the key"
message had *never once fired*. `N8nApiException` **extends `RuntimeException`**, and
`ConfigController` caught `\RuntimeException` **before** the 401 branch — so every auth
failure was swallowed into the generic catch and surfaced n8n's raw text. And even
past that, the status lives in the exception's **`httpStatus` property, not the
Exception `code`** (which is always `0`), so a `getCode()`-based check would have read
401 as 0 anyway. Two independent traps, both invisible until a human tried a genuinely
*wrong* key on a live instance.

> **The durable rule (now in `AGENTS.md` gotchas + `php.instructions.md`):** when a
> typed API exception subclasses `RuntimeException` and carries its status in a
> property, **catch order and `instanceof` are load-bearing**, and you read the
> property, not `getCode()`. A single `catch (\Throwable)` delegating to one formatter
> is the safe shape. CI never caught this — only a wrong key on a real pod did.

**One more, from the review bot (§5.3):** *"could not reach n8n"* was being reported
for a reached-but-errored response (a 500). Fixed to distinguish a true transport
failure (`httpStatus === 0`, no response) from an HTTP error we *did* receive
(*"n8n returned HTTP 500"*).

---

## §5.2 — The cameo: a table for two

Down the street, `nextcloud-grafana` has been doing its *mise en place* — its saga's
Chapter 1 is a working admin-connection appetizer and, now, a whole **folder-mapping**
course (Grafana folders → Nextcloud folders, the tag-hack retired because Grafana has
real folders). Its saga casts *us* as **"the master who already has his stars."** This
chapter is where the framing pays off, because the two kitchens finally cooked the
**same dish at the same time**:

- The connection-UX fix in §5.1 wasn't ported *later*. It landed on **both apps in the
  same afternoon**, in parity — same shared-formatter shape, same "is it stored?"
  card, same missing-vs-rejected test, adapted only for the noun (`api_key` /
  `X-N8N-API-KEY` here; `grafana_token` / `Authorization: Bearer` there). Two open PRs,
  one lesson, mirrored.
- That is the "mother sauce" thesis from grafana's saga made concrete. If a single fix
  reduces cleanly onto both backends — one that speaks a header key, one a bearer
  token; one with tags, one with real folders — then the shared base is **real**, not
  "n8n with the labels filed off." We didn't prove it by abstracting early (Dr K's
  standing order: *concrete beats clever*). We proved it by fixing the same bug twice
  and watching the diffs rhyme.

> **The meal.** The star, done with opening night, walks down to the apprentice's
> kitchen. They don't talk shop about *frameworks* — they compare *plates*. "You had
> the blank-field problem too?" "Same night. Fixed it the same way." That's the whole
> cameo: two sagas, one dinner, the history of today flowing between them. See the
> apprentice's side of this table in
> [`nextcloud-grafana` saga, Chapter 1 — Mise en Place](https://github.com/kubed-io/nextcloud-grafana/blob/main/saga/Chapter_1_Mise_en_Place.md).

---

## §5.3 — A new sous-chef: the review bot, trained on the house rules

A shipped app on a public store earns a second reviewer. We turned on **GitHub Copilot
code review** and — the part that matters — **tuned it to this project** instead of
letting it run generic:

- **Repo "Security & Quality" parity, via `gh`.** These settings live *outside* the
  workflow files and had silently drifted between the two repos: the **Copilot-review
  ruleset**, **secret scanning**, and **CodeQL default setup** (the "Analyze" check)
  were on for n8n but missing on grafana. All three are now matched on both, set over
  the CLI. (Recorded as a reusable checklist so the next apprentice inherits it in one
  pass.)
- **Instruction files, back-linked to the saga.** A repo-wide
  `.github/copilot-instructions.md` that points the bot at `AGENTS.md` /
  `CONTRIBUTING.md` / `SECURITY.md` / the saga *first*, then hammers the one principle
  we hammer everywhere — **be Nextcloud-native; use a framework primitive if one
  exists; hunt for code to delete in favour of core** — plus path-specific
  `*.instructions.md` for PHP, frontend, YAML, and GitHub workflows (the workflow one
  encodes our real rules: no `${{ }}` inside `run:`, comment above the step, one
  function per step, verify action versions via `gh`, `GITHUB_ENV` only for flow-wide
  values).
- **The bot then enforced our own week-old rules.** It flagged an injected dependency
  that should be `readonly` — a convention we'd written into `php.instructions.md`
  *days* earlier — and caught a genuine correctness bug on grafana: a client-supplied
  mapping `id` accepted on create, and an `update()` array-union that kept the *body's*
  id instead of the path's. Both fixed. A reviewer that has read your house style and
  uses it is worth more than one that hasn't.

> **What we learned about the bot:** it reads instructions from the **PR's head
> branch**, so you can test instruction changes in the same PR. It's non-deterministic
> — keep each file short and scoped with `applyTo`. And it *will* miss framework
> internals (it "flagged" a tooltip as unescaped when `Util::sanitizeHTML` already does
> `ENT_QUOTES`; it worried about optional `catch {}` on browsers NC dropped years ago).
> **Triage, don't obey.** The good comments were very good; the fluff was verifiably
> fluff, and we declined it with the receipts.

---

## §5.4 — Checklist: what's done

| Item | Status | Note |
|---|---|---|
| **First store release** (Ch4 Epic 7) | ✅ | Live on apps.nextcloud.com; the finale that "may not happen this chapter" happened. |
| Repeatable release pipeline (bump → package → sign → release → store) | ✅ | Proven, not a stunt — we release on demand. |
| Connection card shows "is a key stored?" | ✅ | `[Unreleased]`; mirrored on grafana. |
| Test-connection: missing vs rejected key | ✅ | Shared formatter, button + occ agree; fixed the dead-401 + `httpStatus` traps. |
| Reached-but-errored (5xx) ≠ unreachable | ✅ | From the review bot. |
| Injected deps `readonly` | ✅ | Our own convention, enforced by the bot. |
| Copilot review enabled + tuned to house rules | ✅ | Repo-wide + path-specific instruction files, back-linked to the saga. |
| Repo Security-&-Quality parity (ruleset, secret scanning, CodeQL) | ✅ | Set via `gh`; checklist saved for the next repo. |
| Grafana apprentice reaches feature-cameo parity | ✅ | Connection UX shipped on both, same afternoon. |
| Live-pod smoke test before approval | ✅ (standing rule) | CI green ≠ smoke test; UX only shows when a human clicks. Now an `AGENTS.md` obligation. |

---

## §5.5 — What we learned (the durable distillation)

1. **A sensitive field is a blind spot.** It renders blank whether set or not, so drive
   the card's *copy* from stored state, and make the connection *test* distinguish
   **missing** from **rejected** — those are different problems and the error must say
   which.
2. **Typed API exceptions that subclass `RuntimeException` are a trap.** Catch order and
   `instanceof` decide whether your friendly 401 message ever fires, and the status is
   in the property (`httpStatus`), not `getCode()`. One `catch (\Throwable)` → one
   formatter is the safe shape.
3. **"Could not reach" must not swallow a response we received.** Only `httpStatus === 0`
   is transport failure; a 5xx means we reached it — say so with the code.
4. **Repo settings drift silently.** "Security & Quality" (Copilot review, secret
   scanning, CodeQL) lives outside the workflow files and won't clone itself onto a
   sibling repo. Keep a `gh`-command parity checklist.
5. **An AI reviewer is only as good as its instructions.** Point it at the saga and your
   house principles from the *head branch*, keep files short and `applyTo`-scoped, and
   it starts enforcing *your* conventions. But it doesn't know framework internals —
   triage its output, decline the fluff with proof.
6. **CI green is necessary, not sufficient.** Every bug of real substance this chapter
   (the dead-401, the wrong-key confusion) was invisible to CI and only surfaced on a
   live pod. Deploy and click before approving — it's now a written rule.
7. **Parity is the proof of the base.** With a real apprentice, every fix is two commits.
   When the same fix reduces cleanly onto two different backends, the shared "mother
   sauce" is real. Don't abstract early to prove it — fix twice and watch the diffs
   rhyme.

---

## §5.6 — A gap we *both* have: bidirectional tag sync (note from the apprentice's kitchen)

The apprentice (`nextcloud-grafana`), cooking its pull engine, tasted an ingredient we
shipped *around* rather than *through* — **tags** — and found a seam that is open in **this**
app too. Recording it here because the fix is shared-module bait, not a one-app patch. **No
code changes here; this is a saga note.**

**The gap, stated for n8n.** An n8n workflow carries real tags (`/api/v1/tags`, opaque ids,
`PUT …/workflows/{id}/tags` to set them). We use those tags only as the **mapping key** and
as reserved control tags (`n8n:ignore`, the `n8n:sync`/`n8n:link` mode pills). We do **not**
reconcile a workflow's *content* tags into Nextcloud's **system tags**. So a user browsing
the mirror in Files can't filter "every `prod` workflow" the Nextcloud-native way, and can't
re-tag a workflow with an NC pill and have it reach n8n. **A sync that carries the body but
not the labels isn't a full sync of the object** — the apprentice's phrasing, and it's right.

**The dish (spec, for whenever we cook it — likely in the shared module):**

- **Equality minus the reserved namespace.** After a reconcile, a workflow's n8n tags and
  its NC system tags hold the same strings, excluding the reserved `n8n:*` control tags
  (never pushed to n8n; never imported as content). The `n8n:` prefix on the mode/ignore
  pills is exactly the seam that lets content and control tags share the NC systemtag space.
- **Three edit surfaces — the object body is the third.** The apprentice's user sharpened
  this: tags live *inside* the object we map, so a `sync` file's on-disk JSON already has a
  `tags` array. That makes **three** editable places, kept as one set: (1) **n8n tags** on
  the workflow, (2) the **`tags` array in the `.n8n.json` file body**, (3) the **NC system
  tags** (pills). Two of the three live inside Nextcloud (body + pills) and can drift from
  each other without ever touching n8n, so the model makes the **file body the canonical
  object** and the pills a **listener-kept projection**: edit a pill → the body's `tags`
  follow → the change pushes to n8n; edit the JSON `tags` → the pills follow → it pushes;
  edit in n8n → a pull writes both. `link` files have only surfaces 1 + 3 (the pointer body
  is not the object), so their pills are a read-only projection of n8n, pull-only.
- **The n8n write leg differs from Grafana (and it matters here).** On Grafana, tags ride
  *inside* the dashboard upsert — writing the body writes the tags. On n8n they do **not**:
  `N8nWorkflowBody::WRITABLE` deliberately excludes `tags`, and `PUT /workflows/{id}` **does not
  accept** a `tags` field — n8n rejects unknown/read-only fields, which is exactly why
  `N8nWorkflowBody` keeps a writable-field whitelist — so tags are a **separate** write
  (`ensureTag` each name → id, then
  `setWorkflowTags(id, [ids])` = a full-replace `PUT /workflows/{id}/tags`). So the body push
  and the tag push are two calls on n8n, one call on Grafana. The **read** side is parallel
  (both echo `tags` in the GET body); only the write leg forks. This is exactly the kind of
  per-backend seam the shared module isolates behind one `writeTags(object, names)` method.
- **⚠ The mapping-tag hazard — n8n-only, no Grafana analogue.** n8n maps a **tag** to a
  folder, so the workflow's binding *is* a content tag (e.g. `myflows`). If we sync all
  content tags bidirectionally, the mapping tag becomes an NC pill too — and **removing that
  pill would push a tag removal that unmaps the workflow** (it falls out of the mapping and
  gets pruned). Grafana maps by *folder*, so it has no such coupling — a Grafana content tag
  is never load-bearing for placement. Resolution options (a genuine n8n fork): **(a)**
  treat each mapping's own tag as **protected** — surface it as a pill for visibility but
  refuse to push its *removal* via the pill (removal only happens by moving the file out, the
  existing unmap path); or **(b)** allow it and define "remove the mapping pill = unmap,"
  consistent with move-out semantics. Leaning **(a)** — least-surprise, and it keeps the tag
  sync from ever silently un-binding a workflow. **This asymmetry is a parity divergence to
  record: the shared tag-reconcile must accept a per-backend "protected tags" set (n8n passes
  its mapping tags; Grafana passes none).**
- **Pull is mode-independent.** The systemtag reconcile runs for **both** `sync` and `link`
  files — a `link` file's body is a pointer, but its **NC tags still mirror the live n8n
  tags**, so the mirror is *as searchable as n8n itself* regardless of mode. Push stays
  `sync`-only (a `link` file never pushes), so `link` tags flow one way, n8n → NC.
  - **A `link`'s pills are read-only, and the pull enforces it.** A `sync` pull keeps
    NC-local additions (`nc − baseline`) so a pill added in Files survives to push next
    time; a `link` has no push channel, so `reconcilePull` **drops** local adds for a
    `link` (`localAdds = isSync ? nc − baseline : []`) and mirrors n8n's content tags
    exactly. Without this, a pill clicked on a link would linger forever as a phantom the
    system could never carry anywhere. You *can* click a pill on a link; it just isn't
    pushed and is wiped on the next pull. Proven live by the three `link` read-only
    scenarios in `tag-sync.feature` and `TagSyncServiceTest::testPullForALinkDropsLocalAddsAsPureMirror`.
- **Provenance needs a baseline.** The hard part is two-sided drift: when a tag is on one
  side and not the other, you **cannot** tell an *add* from a *remove* from the two current
  sets alone. The fix is a banked baseline — **`n8n_syncedTags`, the reserved-stripped tag
  set as of the last successful sync** (the tag analogue of `n8n_syncedHash`). With it,
  `added = side − baseline` and `removed = baseline − side` on each side give a true
  three-way merge (union of adds, propagate removes); the only genuine conflict — same tag
  added on one side, removed on the other — falls to the reconcile's direction-of-truth
  (pull → n8n wins, push → NC wins). We track the *baseline set*, **not** per-tag authorship
  (neither system records who made a tag; an origin flag would rot on re-add).
- **Why it's shared-module bait.** The NC-side half — systemtag reconcile, reserved-prefix
  filter, baseline three-way merge, direction-of-truth on conflict, body↔pills projection —
  is **backend-agnostic** and identical to Grafana's. The per-backend differences are three,
  all small and nameable: **where tags live / how they're written** (separate id'd resource
  for n8n → `ensureTag` + `setWorkflowTags`; inside the object for Grafana → upsert the
  dashboard), **the reserved prefix** (`n8n:` vs `grafana:`), and **the protected-tags set**
  (n8n's mapping tags vs. Grafana's empty set). Two backends, one label-reconcile recipe with
  three injected knobs — the clearest shared-base signal yet (§5.5 rule 7: fix twice, watch
  the diffs rhyme — here they rhyme *before* we cook).

> **Cross-note:** the apprentice's full treatment (live measurements of Grafana's tag model,
> the `link`-searchability rule, the provenance/baseline merge) is in
> [`nextcloud-grafana` saga, Chapter 2 — "Dashboard tags / bidirectional tag sync"](https://github.com/kubed-io/nextcloud-grafana/blob/main/saga/Chapter_2_Service_for_a_King.md).
> Fork H there == this note here.

> **Dr K, tapping the rail:** *"The kid found a hole in *your* line, not just theirs. Both
> apps carry the body and drop the labels. When you finally build the shared pot, the
> tag-reconcile goes in it — same recipe, two backends, one baseline note that tells a new
> tag from a dead one. Don't cook it twice. Cook it once, in the mother sauce."*

### §5.6.1 — We cooked it (n8n first). The engine is on the pass.

The note above said *"whenever we cook it — likely in the shared module."* We cooked it
**here, now** — the n8n app takes the lead on tag parity ahead of the apprentice
([PR #51](https://github.com/kubed-io/nextcloud-n8n/pull/51)). n8n could move first because
its pull/push spine already exists (the apprentice still only has foundation), so the runtime
lands here and the *shape* it took becomes the blueprint the shared pot inherits.

**What actually shipped** (runtime, not spec):

- **`TagMerge`** — the backend-agnostic core, extracted **pure** on purpose: no Nextcloud, no
  n8n, just set algebra over `list<string>`. `merge(baseline, nc, source)` does the three-way
  merge from §5.6 — adds are the union, removes propagate. This is the piece that lifts into the
  mother sauce *verbatim*.
- **`TagSyncService`** — the IO shell around `TagMerge`: reads/writes NC system tags
  (`ISystemTagManager` + `ISystemTagObjectMapper`, the exact pattern `OwnershipTags` already
  uses), and does the n8n write leg. It owns the two per-backend knobs that turned out to
  matter — the reserved-prefix filter and the protected-tags force-keep.
- **`WorkflowMetadata::KEY_SYNCED_TAGS`** (`n8n_syncedTags`) + `ManagedFile::syncedTags` — the
  banked baseline, the tag analogue of `n8n_syncedHash`, stamped as canonical (unique + sorted)
  JSON so equal sets always compare equal.
- Wired into `SyncService`: pull reconcile in `writeWorkflow` (**sync AND link** — searchability
  is mode-independent), push reconcile in `pushOne` (**sync only**).

**What the build taught (updates to the spec above):**

- **The "conflict tiebreak" was a phantom — the merge is deterministic.** The §5.6 spec (and my
  first cut, `merge(baseline, nc, source, sourceWins)`) assumed a genuine two-sided conflict —
  "same tag added on one side, removed on the other" — needing a pull-vs-push winner. The PR
  reviewer caught that for a **set** element against a *single* baseline that case is impossible:
  *added* means not-in-baseline, *removed* means in-baseline, and those are disjoint. So a tag
  is in the result iff either side added it or the baseline kept it, and a remove by either side
  always wins over the untouched side. There is nothing to break. `$sourceWins` was dead code;
  it's gone. Direction-of-truth still exists — but it lives in *which* reconcile runs and where
  it writes the result, **not** inside the pure merge, which stays a symmetric function of three
  sets. (Provenance/authorship would only matter if we banked per-*element* history or allowed
  concurrent same-tag add+remove — we bank the baseline *set*, so we don't.)
- **Baseline is what the *source* reflects, not what we wrote to disk.** On pull, NC-local
  additions survive onto the pills (`source ∪ nc-local-adds`), but the **baseline is stamped to
  the source set only** — an NC-local add is *not yet agreed* until a push lands it in n8n.
  Stamp it too early and the next push reads that local add as a two-sided no-op and never
  propagates it. This subtlety wasn't in the original note; it's the difference between "tag
  reaches n8n next push" working and silently dropping.
- **The mapping tag gets a *sane* eject, not just a force-keep.** §5.6 landed on "protect the
  mapping tag" (option a) but left the UX of a pill-removal fuzzy. Thinking it through: a bare
  force-keep means the pill *pops back* on the next reconcile — which reads as broken. So the
  full model is two-layer: (1) the reconcile **force-keeps** the mapping tag as the safety net —
  a bulk sync can never let a stray pill click prune the mirror; and (2) *leaving* a mapping is
  an **explicit** gesture with exactly two forms — **move the file out** (`unmapped`, workflow
  archived, restorable) or **`n8n:ignore`** (`ignored`, file kept standalone). Removing the
  mapping pill *as a deliberate eject* is defined as form (2): it is **paired with `n8n:ignore`**
  so the workflow leaves the mapping but the file is **kept, never pruned**. That is the "if we
  do allow removing the mapped tag, we add the ignore tag to make it logical" resolution — the
  binding tag can never be silently dropped, only ever traded for an explicit ignore. (The
  reactive pill→ignore listener is surface-2/3 work, still `@todo`; the force-keep safety net
  ships now.)
- **The n8n write leg is full-replace, so reserved markers must be re-sent.**
  `setWorkflowTags` replaces the whole tag list. If you push only content tags, any `n8n:*`
  marker a user hand-set *on the workflow in n8n* vanishes. So the push path reads the live
  workflow's current tag names, **preserves the reserved ones**, and unions them back in before
  the replace. (Grafana's upsert has the same "you send the whole set" property — this rhymes,
  as predicted.)
- **Tag failure must never sink the body sync.** The reconcile is wrapped in try/catch at the
  `SyncService` call sites and logged — the body already landed via `putContent` +
  `stampSynced`; a tags hiccup (n8n 500 on `setWorkflowTags`, a systemtag race) is retried next
  reconcile, not promoted to a failed sync. Tags are the *last* thing done, deliberately.
- **`getTagsByIds`, not per-id lookups.** Reading a file's NC content tags is
  `getTagIdsForObjects` → one `getTagsByIds` batch → strip the reserved prefix. Matches how
  core surfaces them and avoids N calls.
- **Pruning is an edge sweep, not a catalog GC.** Chasing "minimal in the end" tempts you to
  delete unused tag *definitions* on both sides. Don't: neither catalog is ours alone — an NC
  system tag may be pinned on non-workflow files, an n8n tag may sit on unmanaged workflows — so
  deleting a definition because no *managed* object uses it strips it off bystanders. The pruning
  that matters is the **assignment** edge, and the three-way merge already sweeps it both ways
  (remove-on-either-side drops the pill *and* the n8n tag). Minimality then comes free from being
  **prune-free by construction**: `ensureTag` reuses by name (no dup definitions), reserved never
  crosses (n8n's catalog never grows a control tag), and the reconcile computes the *final* set
  before it writes — so it never mints a pill or tag it's about to drop. Verified both write legs
  (`writeNcContentTags`, `pushSourceTags`) only `ensureTag` the winners. A true catalog sweep, if
  ever wanted, is an opt-in `occ` command, dry-run first, symmetric (a tag alive on *either* side
  survives), never on the hot path.
- **Removing a tag from the JSON body is just another `NodeWrittenEvent`.** The `name` key already
  rides that event (the filename↔name reconcile); the body `tags` array is the same surface. So a
  hand-edit that *drops* a tag inside the JSON is a first-class removal — pill follows the body,
  next push drops it in n8n — and the mapping-tag protection covers a body-drop of the binding tag
  exactly like a pill-drop. (The listener that makes body↔pills instant is still `@todo`; the
  reconcile already honors whatever the body says.)

**Still on the cutting board (why some scenarios stay `@todo`):** surfaces **2 and 3** from
the spec — the live **body↔pills projection listener** (edit a pill → the `.n8n.json` `tags`
array follows, and vice-versa, *without* a full sync) — aren't wired yet. The pull/push
**reconcile** is real, unit-tested (`TagMergeTest` pins the algebra; `ManagedFileTest` pins the
baseline decode), *and* now covered end-to-end: the n8n↔pills scenarios are live in
`tag-sync.feature`, backed by the `TagSyncSteps` integration trait. Only the scenarios that
hand-edit the JSON `tags` array (plus the reactive eject and the optional catalog sweep) keep
their `@todo` — the *reactive projection* is the next slice. So today: **sync from n8n mirrors
tags, sync to n8n writes them back, the baseline keeps adds and removes straight** — but
re-tagging with a pill only reaches n8n on the next push, not instantly.

**Two scope lines nailed down while scrutinising the flow.** (1) **Move-out is
tag-neutral.** The unmap path only *archives* the workflow in n8n — it never touches
tags — so a move-out leaves both the n8n tag and the NC pill exactly as they were, and
once the file is `unmapped` it is a plain Nextcloud file the tag machinery no longer
applies to. That holds by construction today (the reconcile only runs inside a mapping's
loop), and the future auto-trigger listener must gate on mapping membership to keep it
true. The live move-out scenario now asserts precisely that — both sides still carry the
tag — instead of leaning on an undefined "left as they were" step and a fuzzy
"handled by the unmap path" one. (2) **A second multi-mapping hazard, pinned.** When one
workflow carries two mapping tags and is mirrored into two folders, each mirror sees the
*other* mapping's tag as an ordinary content pill that is **not** in its per-mapping
protected set (`[mapping tag]`) — so dropping it would push a removal that unbinds the
sibling and prunes its mirror. The real fix is a protected set that is the **union** of
every mapping tag on the workflow; it is `@todo` alongside the sibling-convergence
fan-out, both deliberately out of scope for this cut.

> **Dr K, reading the ticket off the rail:** *"Good — you cooked the sauce, not just wrote the
> recipe on the wall. And you kept it pure in the middle so it pours straight into the big pot
> later. Now: the pill and the paper still don't talk to each other till the whole plate goes
> out. That's your next fire. But the labels reach the guest now, both ways. The kid's still
> plating his base — you're serving. Stay ahead."*

### §5.6.2 — The reactive fire: making an edit reach the other side on its own

§5.6.1 shipped the **engine** and one **trigger**: the bulk manual sync. Pull mirrors n8n → pills
(sync + link), the per-mapping "Sync to n8n" button pushes pills → n8n, and the `n8n_syncedTags`
baseline keeps adds and removes straight. That is a *correct* two-way sync — but only when a human
clicks a button. The goal is **perfect** two-way: edit a tag on either side and *see it on the
other on its own*. So the remaining work is not new algebra — the `TagMerge` pot is done — it is
**triggers**: wiring the existing reconcile onto the events that already fire when a user edits
tags, the same way the body writeback and the `name` reconcile are wired.

**The three NC-side edit surfaces, and what actually happens today.** The spec names three places a
tag can be edited, kept as one set (n8n tags; the file body `tags` array; the NC system-tag pills).
Reading the wiring against that spec surfaced exactly where the reactive half is missing:

| Surface | User gesture | Event that fires | What happens today |
|---|---|---|---|
| 1 — n8n tags | edit in n8n | (none in NC) | **Pull reconciles it.** ✅ (bulk pull, sync + link) |
| 3 — NC pills | add/remove a pill in Files | `TagAssignedEvent` / `TagUnassignedEvent` | **Nothing.** No listener acts on a *content* pill — `ModeTagListener` only fires on `n8n:ignore`. The tag sits in NC and never reaches n8n until a manual push. ❌ |
| 2 — file body | edit the JSON `tags` array + save | `NodeWrittenEvent` | **Deferred (Slice B — see §5.6.2.3).** Attempted, reverted: the body's `tags` array is a **derived mirror** a pull writes; a hand-edit is not projected to n8n today and self-heals on the next pull. The reconcile engine exists (`TagReconcileService::reconcileFromBody`, unit-tested) but is **not wired**. ⏸ |

**Logical issues found while reading the wiring (the real value of this pass):**

1. **`PushService::push` drops body-tag edits on the floor.** The most surprising gap: a user edits
   `tags` in the JSON, saves, the body PUT succeeds, the synced-hash is re-stamped as "done" — and
   n8n's tags are untouched and the pills are untouched. A sync tool that *looks* like it saved but
   silently discarded the change is worse than one that errored. Directly violates the user's
   "honor editing tags from the file contents itself."
2. **Pills-as-truth vs body-as-canonical is a real fork, not a detail.** `reconcilePush` reads the
   **pills** as the NC-side truth. The spec says the **body** is canonical and the pills are a
   projection. For a *pill* edit, pills-as-truth is exactly right. For a *body* edit, the body must
   win — a reconcile that always reads pills would *overwrite the body edit with the stale pills*.
   So the two reactive entry points cannot share one "read the NC side" step blindly; the body path
   must feed the body's `tags` as the NC input. This is why surfaces 2 and 3, though they look like
   one feature, split into two carefully-ordered slices.
3. **A content-tag listener is a loop unless it is guarded.** `writeNcContentTags` assigns/unassigns
   pills, which *fires* `TagAssignedEvent`/`TagUnassignedEvent`. Today that is harmless only because
   nothing listens for content-tag events. The moment a reactive listener exists it must run its
   reconcile **inside `SyncGuard`** (exactly as `ModeChangeService` does for `n8n:ignore`), or the
   pill it writes re-enters the listener forever.
4. **The mapping-tag safety net now has a reactive face.** Remove the `flows` mapping pill in Files
   and the reactive reconcile reads the pills (no `flows`), merges, **force-keeps** the protected
   mapping tag, and writes the pills back — so the pill *pops back on its own*. That is the intended
   safety net (a stray click can never unbind), and the pop-back write is swallowed by the same
   guard. The deliberate eject (drop-pill ⇒ `n8n:ignore`) stays `@todo` — the net ships first.

**The plan — reactive triggers, smallest safe slices first.** The engine is reused verbatim; only
the triggers are new. Ordered by value ÷ risk:

- **Slice A (this cut) — the pill-edit auto-trigger (surface 3).** A new `ContentTagListener` on
  `TagAssignedEvent`/`TagUnassignedEvent` that, for a **content** (non-reserved) pill on a managed
  **sync** file, runs `reconcilePush` on **that one file** — inline when `timing=sync`, or via a new
  `ReconcileTagsJob` when `timing=async`, honouring the *same* knob the body writeback already uses.
  It reuses `reconcilePush` **unchanged** (pills = NC truth — precisely correct for a pill edit), so
  the risk is only in the trigger, not the algebra. Delivers the headline "add/remove a pill in
  Files → n8n updates on its own," and the mapping-tag pop-back net comes for free. Orchestration
  (resolve the file's mapping → protected tag, wrap in the guard, best-effort log) lives in a small
  `TagReconcileService` shared by the inline listener and the async job. **Deliberately does NOT
  touch the file body `tags` array** — that is Slice B — so the on-disk `tags` briefly trail the
  pills/n8n and self-heal on the next pull. Safe today because *nothing* yet reads the body `tags`
  as canonical (see issue 2), so a stale body cannot revert anything until Slice B lands and updates
  it atomically.
- **Slice B (next) — body ⇆ pills projection (surface 2).** Make the body `tags` array canonical: a
  pill edit updates the body in place (loop-safe: re-stamp `n8n_syncedHash` so the write it triggers
  is recognised as ours and pushes *no* body), and a body-`tags` edit updates the pills and pushes
  the tags — **without** a redundant full-file body push (the "silent body update" scenario). This
  is where issue 1 is fully closed and where the body-vs-pills authority (issue 2) is resolved in
  code. Bigger and trickier (the tag-only-vs-body-changed discrimination on `NodeWrittenEvent`), so
  it follows A behind its own tests.
- **Slice C (next) — pull change-detection.** The scheduled/manual pull already reconciles tags on
  every file every run; add the skip-unchanged / body-only / tags-only branches so an hourly pull
  doesn't churn every file. Correctness is already there; this is the anti-churn refinement.
- **Later — reactive eject (drop mapping pill ⇒ `n8n:ignore`), the union protected-set + sibling
  fan-out (multi-mapping), and the optional catalog sweep.** All already specced `@todo`.

> **Dr K, sliding the next ticket across:** *"The pot's done — quit stirring it. What's missing is
> the *bell*: nobody rings when a plate changes. Wire the bell for the pills first — it's the
> gesture a guest actually makes at the table — and leave the paper ticket for the next fire. And
> for the love of the line, put the guard on before you ring it, or you'll be plating the same dish
> till close."*

#### §5.6.2.1 — Slice A, taken to the live line (and a checkbox that fought back)
Slice A shipped and went to the production pod for a real smoke test. Two legs, two outcomes:

- **Push leg (NC pill → n8n): GREEN.** Removed the `tasks` pill from a synced workflow file in the
  Nextcloud UI; with `timing=async` the `ReconcileTagsJob` fired on the next tick and the `tasks`
  tag was gone from the workflow in n8n. The reactive bell rings, the guard held (no echo), the
  three-way merge did the right thing end-to-end on live data. This is the gesture a guest actually
  makes at the table, and it works.
- **Pull leg (n8n tag → NC): BLOCKED, then unblocked.** Adding a tag in n8n and waiting for the
  scheduled pull to mirror it into Nextcloud went nowhere — because the **scheduled pull had never
  run once.** The `schedule_enabled` checkbox in Sync Settings was silently refusing to save: its
  schema declared a **string** `'0'` default where Nextcloud's declarative checkbox needs a real
  **bool** (`DeclarativeManager` stores string-in/string-out with no coercion, so the frontend's
  boolean round-trip broke and the toggle persisted nothing). One-line fix — `'default' => false` —
  and the pull is scheduled again. The full autopsy lives in **Chapter 1 §17.3.3**; the short of it
  is the reactive tag *push* was never the blocker — a settings-persistence bug on an unrelated
  toggle was gating the whole *pull* direction, tag reconcile included.

So Slice A is proven live on the push side, and the pull side is clear to validate now that the
schedule actually turns on. Slice B (body ⇆ pills) was attempted next — and then deliberately
deferred; §5.6.2.3 is the autopsy.

#### §5.6.2.2 — Slice B: the body is canonical (and the `PUT` that ate the tags)
> **Read this as the attempt, not the outcome.** Slice B was built as described below, then
> **reverted** before merge — the CI spec caught that it broke a shipping feature. §5.6.2.3 is the
> autopsy and the decision to defer. The design here is preserved because it is the starting point
> when the feature is picked up again.

The next smoke test found the gap the §5.6.2 read had already flagged as **logical issue #1**:
copy an `mcp` tag object into a workflow's JSON `tags` array via *Files → edit in JSON*, save, wait
— and `mcp` never reached n8n. The pod logs told the whole story: a `PushWorkflowJob` fired for the
file, the body `PUT /workflows/{id}` succeeded, the synced-hash was re-stamped "done" — and the tags
were **untouched**. `N8nWorkflowBody`'s writable whitelist omits `tags` (n8n rejects the field on the
body `PUT`), and `PushService::push` never reconciled them separately. A save that *looked* like it
worked silently discarded the edit. (The lone `getAppValueString() on null` line nearby is an
unrelated notifications-app push-device quirk, not ours.)

**The fix is exactly the user's instinct: "hop in front of the `PUT`, grab the tags, handle them
ourselves."** `PushService::push` now, after the (tag-less) body push over the REST channel, calls a
new `TagReconcileService::reconcileFromBody` — the file's JSON `tags` array is the NC-side truth,
run through the same three-way `TagMerge` as everything else. And the nicer half of the ask — *let a
user add a bare `{"name":"foo"}` and get the real tag back* — falls out for free: `setWorkflowTags`
**returns n8n's canonical tag rows** (`[{id,name,…}]`), so after the set we rewrite the body's `tags`
in place with those authoritative objects. Add `{"name":"foo"}`, save, and the file comes back with
`{"id":"<n8n-id>","name":"foo"}` — the id filled in from the source, the pills updated, n8n tagged.

Design decisions, so the fork from **logical issue #2** (pills-as-truth vs body-as-canonical) is
resolved in code, not left ambiguous:

- **The body is canonical; the pills lockstep it.** A body-`tags` edit is truth for a body save
  (`reconcileFromBody`). A pill edit is truth for a pill event (`reconcilePush`) — and *then*
  locksteps the body to n8n's canonical rows, re-stamping `n8n_syncedHash` so the body write it
  triggers is recognised as the app's own and pushes nothing (the "silent body update"). Because a
  pill edit always updates the body, the two NC surfaces never durably disagree, so a later body
  save can't diff against a stale sibling and revert a tag.
- **One tag path, one truth source.** `PushService::push` now owns tag reconcile for *both* the
  reactive writeback and the bulk "Sync to n8n" — the old pills-truth `reconcileTagsOnPush` in
  `SyncService::pushOne` is gone. Bulk is body-canonical too, consistently.
- **A cheap fast-path keeps ordinary saves quiet.** `reconcileFromBody` compares the body's content
  tags to the stamped baseline; if unchanged, it skips the n8n round-trip entirely — a nodes-only
  save costs one metadata read and a set compare, no `getWorkflow`/`setWorkflowTags`. n8n-side drift
  is the pull's job, not a push's.
- **Only over REST.** The reconcile runs only when the API channel is on (it uses the REST tag
  endpoints); a webhook-only deployment already ships the full body — tags included — to its flow.

`TagSyncService::reconcilePush*` now return n8n's canonical rows (so the body writer can mirror the
real objects); `pushSourceTags` normalises the `setWorkflowTags` response to rows carrying a name.
Unit-tested in `TagReconcileServiceTest` (pill lockstep, body fast-path, and the bare-`{name}` →
`{id,name}` fill) and `SyncServiceTest` (pushOne delegates tags to `push()`, no longer reconciles
itself). The body↔pills *integration* step defs (a WebDAV body PUT + async drain + n8n assert) are
the remaining follow — the feature scenarios stay `@todo` for that step-def work, not for unbuilt
behaviour.

#### §5.6.2.3 — Slice B, reverted: when the ladder caught a wolf in the merge
Slice B went up on the PR and CI turned red — and reading *which* rung caught it is the whole point
of the README → feature → code → integration-test ladder. Two failures, one of them the real reason:

- **The unit tests failed first (my own doubles).** Easy: the body path's mocks needed
  `contentTagsFromWorkflow` stubbed and the lockstep sort was alphabetical (`flows` < `inventory`),
  plus a `createStub`→`createMock` where `expects()` was used. Fixed, green. This was noise.
- **The integration tests failed for a real reason — and not the one I expected.** The seven new
  body↔pills scenarios failed (the behaviour was never verified live), *and* — the sharp part —
  **seven already-green pill-driven scenarios regressed** (`tag-sync.feature:195, 296–414`). The
  Slice B commit hadn't just *added* a body path; it had **refactored the shared merge**
  (`reconcilePush` dropped `sourceWins: false`, `writeNcContentTags` changed) so the pill path and
  the body path shared one code path. That refactor changed the pill path's behaviour and broke a
  *shipping* feature — the working "edit a pill → n8n" reactive trigger from Slice A.

**That is the executable spec doing exactly its job.** The "advertisement" (README) had written a
check the code couldn't cash; the bottom rung — integration tests against a live n8n — refused to
certify it and, more importantly, caught the collateral damage to a neighbour. A proof-of-concept
that fails its own spec is a *successful* PoC: we learned it wasn't ready **without shipping a
regression**. The fix was a clean revert of the two entangled files (`SyncService`, `PushService`)
back to the Slice-A line, plus their unit tests — restoring the pill path, leaving Slice A live.

A second, smaller cleanup followed once the deferral was decided: the pill path had *also* grown a
Slice-B habit — after pushing a pill to n8n, `reconcileFile` was lockstepping the file body's `tags`
array (and re-stamping the hash). That body write is part of the deferred surface, was never
verified live, and contradicts Slice A's own contract ("carry the pill, leave the body alone"). It
was stripped from `reconcileFile` (and its unit test removed) so the live pill path is **pure Slice
A**: it carries the pill to n8n and leaves the body untouched — the body mirror self-heals on the
next pull. `reconcileFromBody`, `rewriteBodyTags`, and the `reconcilePush*` row-returning helpers
stay in place, dormant and unit-tested, as the Slice-B starting point.

**Why this is fiddly, not blocked (the notes for next time).** No n8n limitation makes body-tag
editing impossible. The genuine difficulties, in order:

1. **The REST `PUT` strips `tags`** (n8n's body whitelist omits it), so a body save can *never*
   carry tags on its own — a body edit always needs a separate tag call. "Push the body" ≠ "push the
   tags."
2. **The id-fill write-back is re-entrant.** Turning a bare `{"name":"foo"}` into `{"id":…,"name":…}`
   rewrites the file, firing another `NodeWrittenEvent`. It must be loop-guarded (hash re-stamp) or
   it plates the same dish till close.
3. **Two authoritative NC surfaces.** Pills *and* the body `tags` array both being editable means
   keeping them converged across two different events (`TagAssignedEvent` vs `NodeWrittenEvent`)
   that can double-fire or diff against stale state — the pills-vs-body authority fork (issue #2).
4. **Do NOT refactor the shared merge to serve the new path.** The regression came from making
   `reconcilePush` (pills = truth) and a body path share one "read the NC side" step. The body path
   needs its **own** entry that feeds the body's `tags` as the NC input; the pill path's algebra
   must stay untouched.

**The correct shape when it's picked up again:** a dedicated `NodeWrittenEvent` trigger (the same
event the `name`/filename reconcile already rides), calling `reconcileFromBody` **only**, guarded,
with the body-vs-nodes discrimination so a nodes-only save costs nothing — and **without** touching
`reconcilePush`. The engine (`TagReconcileService::reconcileFromBody`) and its unit tests are kept,
dormant, for exactly that. The six body scenarios stay `@todo`, and they must be **verified live**
against real n8n before the `@todo` comes off — a green unit test was not enough to trust this one.

**Today's shipped truth, stated plainly (so no doc lies):** the **pills are the authoritative
Nextcloud tag surface**; the body's `tags` array is a **derived mirror** a pull writes. Hand-edit
the JSON `tags` and it is *not* projected to n8n — it self-heals (is overwritten) on the next pull.
To change a workflow's tags from Nextcloud, edit the **pills**. This is not "editing JSON tags is an
error" — it is "the `tags` array is read-only/derived," which is both truthful and cheap (ignoring a
body-tag delta is *less* work than detecting and rejecting it).

> **Dr K, wiping the pass down:** *"Better to 86 the dish than send a plate that poisons the table
> next to it. You didn't lose the recipe — it's still on the board. You learned the bell for the
> paper ticket rings the pill station too, and rings it wrong. Wire it its own bell next fire. And
> notice what saved you: the taster at the door sent it back before it reached a guest. That taster
> is the whole reason we write the ticket first."*

### §5.6.3 — The tag model, rebuilt from the transport case (and the bug it exposed)

Slice B was deferred twice — once for a regression, once for the fiddliness — and both times the
question was framed as *"which surface wins?"*. Command reframed it, and the reframing is what
finally made the model fall out:

> *"think of a situation where we took an n8n file out of n8n or even a copy … no tags in the body
> and a file leaves nxt and comes back to nxt in a mapped folder, no tags can be known — so the json
> file here is the ultimate source of truth that tags are needed to be added or removed."*

That is not an opinion about precedence. It is an observation about **portability**, and it settles
the design because only one of the three surfaces survives a round trip.

#### The homework: what n8n's API actually permits

Read off n8n's own OpenAPI spec (`workflow.yml` and `workflowCreate.yml`), not inferred:

- Both schemas are `additionalProperties: false`, and in **both** of them `tags` is
  **`readOnly: true`**. So tags cannot ride the body on **create** *or* on **update**.
- The only writer is `PUT /workflows/{id}/tags`, which takes **tag ids** (not names) and returns the
  workflow's full tag list afterwards.

So "strip the tags out of the body and send them to the tags endpoint separately" is not a design
choice we made — **it is the only door n8n leaves open.** §5.6.2.3's difficulty #1 is confirmed and
now has a citation instead of a live observation.

#### The bug this uncovered: adoption throws every tag away

`CreateService::applyMappingTagAdditive`'s docblock claims:

> *"POST `/workflows` preserves tags the body declared."*

It does not, and we never declare them anyway — `N8nWorkflowBody::WRITABLE` is
`['name','nodes','connections','settings','staticData']`. So `$created['tags']` is **always empty**
and the "additive merge" merges the mapping tag into nothing.

**Drop a `.n8n.json` carrying `prod`, `billing`, `critical` into a mapped folder and the workflow is
created in n8n with the mapping tag ONLY. All three are silently discarded.** Same for a copy
(`CopyService` routes through the same create).

That is exactly the transport case Command described, handled exactly backwards: the body is the
only thing that knows those tags, and it is the one moment we ignore it. **Found by reading, not by
a failing test — the feature files never described adoption's tag behaviour at all.**

#### The three surfaces have three different natures

The mistake was treating them as three peers to referee. They are not:

| Surface | Survives export / re-import? | Survives a copy? | Editable where |
|---|---|---|---|
| **n8n tags** | n/a — it *is* the remote | n/a | the n8n UI |
| **NC pills** | **no** — bound to a file id | **no** (NC does not copy system tags) | the Files app |
| **body `tags`** | **YES** — it is bytes in the file | **YES** | any text editor |

The body is the only **portable** carrier. That single row is the whole argument.

#### Authority belongs to the MOMENT, not to a surface

Command's two statements — *"the json file is the ultimate source of truth"* and *"if the tags in
the file disagree with n8n, n8n takes precedence and we lose the file change"* — read as a
contradiction until they are separated by moment. Then they are the same model:

| Moment | Authority | Why |
|---|---|---|
| **Adoption** — a file becomes managed (create / copy / move-in) | **the body** | Nothing else knows. No pills, no metadata, no workflow yet. |
| **Steady state, no NC edit** | **n8n** | The system of record; the pull heals both NC surfaces. Command's precedence rule, exactly. |
| **A deliberate NC edit** (a pill toggle, or a body-`tags` edit) | **the edit** | The user acted. Carry it. |

#### Why "pick a winner" does not prevent split brain on its own

Command asked whether a fixed precedence winner would prevent split brain. It helps — but only
*after* you know a side changed, and that is the part that is missing. These two states are
byte-identical:

```
body {a,b}   n8n {a,b,c}
  ├─ the user deleted `c` from the file      → "n8n wins" silently restores it
  └─ the user added the `c` pill, body stale → "file wins" silently deletes it
```

Same two sets, opposite correct answers. A fixed winner does not resolve that; it only chooses which
of two legitimate gestures to break. **That is what a baseline is for** — it says *who moved*, and
precedence is then needed only for a genuine both-moved tie. And per §5.6.1 such a tie is
**impossible** for a set element against one baseline (added ⇒ ∉ baseline, removed ⇒ ∈ baseline,
disjoint), which is why `$sourceWins` was deleted as dead code.

Where Command's rule **is** load-bearing is where there is **no baseline at all** — adoption, or a
file arriving from outside. There, "n8n wins" is the right tiebreak, and it is written down as such.

#### The one hard problem, and the two honest ways to solve it

Everything above is settled. This is not: **telling "the user edited the tags array" from "the body
is merely stale."** The body goes stale for exactly one reason — a pill edit updates the pills and
n8n but leaves the file alone (Slice A's deliberate contract).

**Option A — a change marker (`n8n_bodyTags`).** Store the tag set the body carried the last time
the app read or wrote it. Then `bodyTags == n8n_bodyTags` means the user did not touch tags (free,
no n8n call, and a *stale* body still equals its own marker so it can never trigger a false
removal); `bodyTags != n8n_bodyTags` is a deliberate edit, applied as a **delta** to the agreed set.
This is not a competing baseline — it is change-detection on one surface, the same trick
`n8n_syncedHash` already plays for the body as a whole, one level finer.

**Option B — lockstep, and no marker.** Command's own instinct: *"wouldn't n8n_bodyTags always be
the same as the pills anyway?"* Almost. They coincide the moment the body is written and diverge
only after a pill edit — which is the only moment you would ever consult the marker. But the
divergence can be **removed instead of tracked**: if a pill edit also rewrites the body's `tags`
array, the two are always in lockstep, and `body ≠ pills` then unambiguously means a body edit.

|   | extra metadata | extra file write | how it fails |
|---|---|---|---|
| **A — marker** | one key | none | the body visibly lags until the next pull |
| **B — lockstep** | none | one guarded `putContent` per pill edit | every future writer of the tag set must remember to rewrite the body |

Note the write in B is on a **deliberate gesture**, not the hourly sweep — a different thing
entirely from the churn complaint below. **Leaning A** (it cannot be forgotten by a future code
path, and it degrades to "looks stale" rather than "the surfaces silently disagree"), but this is a
real fork and is recorded as one rather than settled by assertion.

#### The churn, named

`SyncService::writeWorkflow` calls `putContent($body)` **unconditionally**, for every workflow, on
every pull. An hourly pull therefore rewrites every mirrored file every hour and bumps its mtime —
which is precisely the *"it overdoes the amount of 'updated' changes I see"* Command noticed while
working on the third app. Confirmed here by reading. That is Slice C, and it is also the answer to
*"how do NC pills reach the file?"*: today, only by the pull rewriting the whole body; the fix is
Slice C's **tags-only branch**, which updates the `tags` array and leaves the rest byte-identical.

#### What is realtime, and what cannot be

| Direction | Today | Ceiling |
|---|---|---|
| pill → n8n | realtime (`timing=sync`) / next tick (`async`) | **realtime** ✅ (Slice A, live) |
| file body → n8n + pills | nothing | **realtime** ✅ (same `NodeWrittenEvent` trigger) |
| n8n → NC | scheduled pull | **poll-only** ❌ |

The third is not a gap we can close: n8n emits no outbound event on a tag change. The documented
near-realtime answer stays "build an n8n workflow that pushes to Nextcloud" — the same escape hatch
the schedule setting already advertises.

#### Standing order

The specs land first and the code follows, because that is what caught the last regression. The
feature file now describes this model — including adoption, which it had never covered — with the
unbuilt parts tagged as unbuilt rather than implied by a present-tense header.

> **Dr K, reading the new ticket:** *"So the paper ticket is the only thing that walks out the door
> with the plate. Then stop asking which station is boss and ask when. At the pass, the kitchen
> owns it. Coming in off the truck, the label on the box is all you've got — and you've been
> throwing those boxes' labels in the bin since service began. Fix that one first; it's the only
> one that loses something you can't get back."*

---

## §5.7 — Two bins, and the one that was never wired (the finding, confirmed and fixed)

> This section opened as an **unverified claim dropped in from `nextcloud-penpot`**
> while it built its own delete lifecycle. It is now confirmed against this app's
> own source and CI, fixed, and rewritten as the outcome — because a warning left
> standing after it has been acted on is the same doc drift it was warning about.

### The claim, and it was right: `BeforeNodeDeletedEvent` does NOT fire on a purge

`DeleteToN8nListener` used to say the same event fires for both lifecycle steps —
the first delete at the file's normal path, the purge under
`<uid>/files_trashbin/files/…` — *"discriminated by path prefix"*. It does not. The
trashbin's `removeItem` emits nothing typed at all, so the hard branch never ran.

Confirmed from Nextcloud's own `stable33` source rather than by inference:

```
DAV DELETE → AbstractTrash::delete() → ITrashManager::removeItem()
           → LegacyTrashBackend::removeItem() → Trashbin::delete()
           → \OC_Hook::emit('\OCP\Trashbin', 'preDelete', ['path' => …])
```

The legacy hook is the **only** entry point that exists, so its deprecation is
unavoidable. `nextcloud-grafana` had this right in writing all along
(*"proven live: the trashbin's removeItem fires nothing typed"*); `nextcloud-penpot`
followed **this** repo's docblock into the bug. Two siblings disagreed in comments
and the wrong one was believed, which is why the correction now lives loudly in
`TrashPurgeHook` instead of quietly in a diff.

### It was dead TWICE over, and the second half was ours alone

Even with a working trigger the leg could not have run: the trashed node is renamed
`<name>.n8n.json.d<timestamp>`, and the guard was
`str_ends_with($name, '.n8n.json')` — **false at purge time**, several lines before
the path was ever consulted. Hence `FilenameCodec::isTrashedWorkflowName()`, which
requires the timestamp so a live file can never match a trash-only predicate.

The sharp part: `WebDavTrait` in the integration harness had documented the `.dNNNN`
suffix for a long time. **The knowledge was in the repo, on the test side only.**

### The feature file had already guessed both causes

`features/delete.feature` carried a skipped scenario whose comment named the
trashbin's missing event *and* the extension gate, hedged as *"likely cause"*. Both
were true. It sat there while purged `sync` workflows stayed alive in n8n forever
with their files gone — a quiet leak nobody goes looking for.

**A comment that says "likely cause" is an open investigation, not a status.** That
is now a named trap in `.github/instructions/gherkin.instructions.md`, because the
cost here was not the bug — it was the months the diagnosis sat unread.

### It was dead THREE times over — and the third was a method that never ran

The hook was correct, the predicate was correct, and the purge still did nothing. The
log showed **no trace of the listener at all**, and the reason was not in any of the
code that had been changed:

`appinfo/info.xml` had **no `<types>` declaration.**

`register()` runs for every enabled app during bootstrap, but `boot()` only runs when
an app is actually **loaded** — and `remote.php`, the WebDAV entry point, loads a
restricted set:

```php
$appManager->loadApps(['authentication']);
$appManager->loadApps(['extended_authentication']);
$appManager->loadApps(['filesystem', 'logging']);
```

With no `<types>`, this app was never in that set, so **`boot()` never ran on a DAV
request.** Everything wired in `register()` — copy, move, rename, create, soft-delete
— kept working perfectly, which is exactly what hid it: the ONE thing wired in
`boot()` was the legacy purge hook. A correct hook, connected in a method that was
never called on the only requests that mattered.

`<types><filesystem/></types>` is the fix: `filesystem` is the type for apps that
must be present when the filesystem is in play, which is what a file-event-driven
mirror is.

**`nextcloud-penpot` had already found and fixed this, with the explanation written
out in its own `info.xml`, citing the same §C6.13.** The note that was ported into
this chapter carried the *hook* half of that finding and not the *loading* half — so
the port reproduced the bug's cure without its prerequisite. Two sibling apps, one
finding, transcribed incompletely. **When porting a fix, port the whole diff, not the
paragraph about it** — and check the sibling's `appinfo/info.xml` and `psalm.xml`,
because config is where a fix hides in plain sight.

### Then the promoted test failed, and the second lesson was about SILENCE

With the hook wired, the purge scenario still failed and the Nextcloud log had **no
trace of `TrashPurgeHook` at all**. That reads identically whether the hook never
fired or fired and returned early — and every early return in it is a *legitimate*
reason to do nothing, so silence was genuinely ambiguous.

The fix was observability before diagnosis: CI now sets `loglevel 0` (the
"Nextcloud log on failure" step is the only window into a failing run, and at the
default level a listener that decides to do nothing writes nothing), and every bail
in the hook states its reason. Guessing a patch against an unknown cause is how the
second wrong fix gets shipped on top of the first.

### THE MATRIX: two bins, asymmetric on purpose

Both systems have a reversible bin and an irreversible purge, so a workflow has two
lifecycles that only make sense read as a pair:

```
Nextcloud     live file  →  trash      →  purged
n8n           live wf    →  archived   →  deleted
```

| Gesture | Nextcloud | n8n | Status |
|---|---|---|---|
| delete a synced file | → trash | → archived | live |
| restore it from the trash | → live | → unarchived | live |
| purge it from the trash | → gone | → **deleted** (best effort) | live (this fix) |
| unarchive the workflow in n8n | *(nothing)* | → live | **gap** |
| delete the workflow in n8n | *(nothing)* | → gone | correct, by accident |

**Nextcloud drives; n8n does not drive back.** The bottom two rows are the
asymmetry, and it is deliberate: Nextcloud's trash is the user's own undo history,
and an n8n-side bin change is not permission to reach into it.

### What that asymmetry costs — one blindness, two opposite verdicts

`grep -n "trash" lib/Service/SyncService.php` returns **nothing**. The pull indexes
`$folder->getDirectoryListing()`, and a trashed file is not in the folder, so a
trashed mirror is invisible to a reconcile. Two consequences, and the interesting
part is that the same fact is right once and wrong once:

1. **Unarchive in n8n → the trashed file should come back. It does not.** The pull
   finds no file for that id and writes a **brand-new** one, orphaning the trashed
   copy. Restore that copy later and two files carry the same id — precisely the
   duplicate the reconcile is otherwise careful to avoid. The fix is a trash-aware
   reconcile: before creating a file for an unseen id, look for a trashed mirror
   carrying it and restore that instead. `nextcloud-penpot` built exactly this
   (penpot saga §6.37); n8n never got it.
2. **Purge in n8n → the trashed file should stay put. It does** — but nothing
   *decides* that; the pull simply cannot see it. A trash-aware reconcile has to
   preserve this behaviour **deliberately** rather than lose it on the way to
   fixing (1).

### And a third gap, whose fix is already written twenty lines away

`DeleteService::restore()` unarchives through `callIdempotent`, which treats **404
as success**. Right everywhere else; wrong here. If the workflow was permanently
deleted in n8n while its mirror sat in the trash, restoring the file brings it back
carrying a **dead id** with nothing created — silently detached, no sign anything is
wrong.

`MotionService::moveIn()` handles the identical situation correctly — catch the 404,
create from the file's content, stamp the fresh id — and that path is **live-tested**
(`move.feature`, "Restoring when the n8n workflow was hard-deleted falls back to
create"). Command spotted the equivalence unprompted: *"this smells similar to
moving an unmapped workflow into a mapping."* It is not a resemblance; it is the
same fallback, already written, simply not wired to the restore listener.

All three gaps are now `@unbuilt` scenarios in `features/delete.feature` with the
pre/post state spelled out, so the spec and the code finally agree about what is
missing.

> **Dr K, tapping the board twice:** *"You had two bins and you only ever watched
> one door. Fine — you found the other one. But look at what actually cost you: not
> the bug, the *note about* the bug that sat there hedging for months, and then a
> station that said nothing when it decided to do nothing. Write down what you
> refuse to do, and why, or the next cook reads silence as agreement."*

## §5.8 — The apprentice cooked, and it was the best thing on the table

This chapter opened with a table for two (§5.2): the master and a new apprentice
still plating its base while we were serving. It closes with the apprentice cooking
for **us** — and the meal being better than ours.

`nextcloud-penpot` was forked from this app. So was `nextcloud-grafana`. That makes
this repo the eldest, and for most of the year "alignment" meant the younger two
catching up. This round it ran entirely the other way. Everything of substance in the
alignment pass came from the youngest at the table:

| What penpot fed back | What it fixed here |
|---|---|
| the legacy `preDelete` purge hook | a purged workflow left alive in n8n **forever** |
| `<types><filesystem/></types>` | `boot()` never running on any WebDAV request |
| `gherkin.instructions.md` + `features/README.md` | a 33-item "backlog" that was really 5 |
| the session-less actor fallback | `occ tag:files:add` silently doing nothing |
| dropping `#[NoCSRFRequired]` | an admin endpoint reachable cross-site |

**And the sharpest part is that the debt travelled in a circle.** The purge bug did
not originate in penpot — penpot walked into it *following this app's docblock*, which
claimed `BeforeNodeDeletedEvent` fires twice. Penpot paid for the diagnosis, wrote it
down, and sent it back as a note. We took the note, ported the cure, and missed its
prerequisite — the `<types>` line penpot had already spelled out in its own
`info.xml`. So this app shipped a wrong comment, was handed the correction twice, and
still needed a third pass to receive it. The eldest was the slowest learner in the
family.

Why the youngest is the best cook is not a mystery, and it is worth stating because
it will keep being true: **penpot was built after every lesson this app learned the
hard way, so it started from the distilled version.** It never had to un-learn the
path-discriminated purge, because it was born into a world where the trash purge was
already known to be a legacy hook. Newest ≠ least mature when the lineage is written
down. That is the whole return on keeping a saga.

The concrete standing order that falls out of it:

- **Alignment is bidirectional and the direction is not fixed by age.** Read the
  sibling that shipped most recently, whichever one that is.
- **When porting a fix, port the whole diff, not the paragraph about it.** Config is
  where a fix hides in plain sight — `appinfo/info.xml` and `psalm.xml` are now part
  of the checklist, because a correct listener in an app that never boots is
  indistinguishable from no listener at all.
- **A comment that another app might read is a load-bearing artefact.** The wrong one
  here cost a sibling a full debugging cycle. Docblocks are an export surface.

> **Dr K, pulling up a chair and not saying anything for a moment:** *"Well. The kid
> plated that. And you three are cooking out of the same book now, so quit counting
> who came in first — the newest station has the cleanest mise, that's all it means.
> You wrote a bad ticket once and it went out to two other kitchens; that's the part
> to sit with. Fix the ticket. Then eat, because that was a good dish and it's going
> cold while you take notes."*

## §5.9 — Full three-way tag sync, and the blocker that turned out to be self-inflicted

Slice B was deferred twice (§5.6.2.3), both times on the same reasoning: a body-tag
edit cannot be told apart from a stale body, so reading the body as truth risks
destroying a real pill edit. That reasoning was correct. What nobody had done was ask
**how the body gets stale in the first place.**

Command asked for the whole flow written out as pre/post state — every direction, all
surfaces — precisely because it had only ever been argued about. Writing it down
answered the question in one table.

### The proof: staleness has exactly ONE cause

Four surfaces, and the exercise is to enumerate every trigger and check the `body`
column afterwards. Not asserted — enumerated, because the claim is that the list is
exhaustive:

| Trigger | What happens to the body | Verdict |
|---|---|---|
| a pull runs | rewritten wholesale from n8n | **fresh** |
| the user edits the body | it *is* what they typed | **truth** |
| a tag changes in n8n alone | invisible until a pull, which rewrites it | **fresh** |
| **a pill is toggled** | n8n and pills move; the body is left alone | **STALE** |

One row. And that row is not a fact about mirrors — it is Slice A's contract, chosen
deliberately ("carry the pill, leave the body alone") to avoid writing a file on a tag
click. So **the ambiguity that blocked this feature twice was a consequence of one
design decision, not a property of three-way sync.**

That reframes the whole problem. The two candidate fixes stop being equals:

- **A — track the staleness.** A fourth stamp (`n8n_bodyTags`) recording what the body
  last held, so a body change can be read as a delta rather than as truth.
- **B — remove the staleness.** A pill edit also writes the body's `tags` array. Then
  `body ≠ pills` can only mean a body edit, and no extra state exists to get stale.

**B, and it is not close.** A adds a surface whose only job is to describe a lie we
chose to tell; B stops telling it. B also needs no new metadata, and it converges with
work already required: pull change-detection has to write the body's `tags` array
in place on a tags-only change, which is the same operation. One mechanism, two
features.

### Correcting the record on why B was reverted

B had been treated as discredited because it shipped once and was reverted. Re-reading
that revert: **it was not about the body write at all.** The commit also refactored the
SHARED merge so the pill path and the body path went through one "read the Nextcloud
side" step, and *that* changed the pill path's behaviour and broke a shipping feature.
The lesson recorded at the time — the body path needs its own entry point and must not
touch `reconcilePush` — still stands, and it says nothing against lockstep.

So a correct idea sat blocked for two rounds because it was bundled with an incorrect
one, and the bundle was what got remembered. **When a revert is a bundle, record which
part failed**, or the innocent half serves the sentence.

### What shipped

- `TagReconcileService::reconcileFile()` — the pill path now writes n8n's canonical
  rows into the body, loop-guarded by a re-stamped `n8n_syncedHash`.
- `TagReconcileService::reconcileFromBody()` — now compares the body to the **PILLS**,
  not to `n8n_syncedTags`. That is the load-bearing change: the baseline moves on a
  pill edit while the body does not, so comparing to it made an ordinary nodes-only
  save look identical to a deliberate removal. Comparing to the pills is only reliable
  *because* of the lockstep. The decidability comes from the invariant, not from a
  cleverer comparison.
- `BodyTagListener` — a dedicated `NodeWrittenEvent` trigger, sharing the merge engine
  with the pill path and nothing else, exactly as §5.6.2.3 specified.
- The body is left **as typed**. A bare `{"name": "prod"}` is not "corrected" on save;
  n8n mints the id and the next pull writes the canonical row. Rewriting a file the
  user is actively editing is hostile, and it would re-introduce the re-entrant write
  this path is built without.

One detail worth keeping: when the n8n push fails, the body is **still** converged —
onto the pills. An outage is no reason to let the two Nextcloud surfaces disagree,
because that disagreement is the very thing the design exists to prevent. The invariant
has to hold on the error path or it is not an invariant.

### The acceptance test, named

> *A save that did not touch the tags must not undo a pill edit.*

Any design that fails it is wrong however well it handles the happy path. It is a live
scenario and a unit test, and it is the first thing to run if this ever regresses.

---

## §5.10 — The Nextcloud pair is local; only the n8n leg needs a mapping

Command then added the case that completes the model:

> *"a user adds/removes a tag from an unmapped flow — we don't even care about n8n in
> this case, either direction is still synced."*

Both reactive paths gated on `managed && sync`, so an unmapped `.n8n.json` synced
neither way: its pills and its `tags` array could drift apart freely. That gate was
wrong, and the reason is worth stating precisely — **it conflated three participants
with two.**

```
pills  ⇄  body          Nextcloud-local. No remote system involved.
pills/body  →  n8n      needs a mapping.
n8n  →  pills/body      needs a mapping.
```

Only the legs that *talk to n8n* need a mapping. Keeping a file's own two surfaces in
step never did.

### Why it matters, and it is not a tidiness argument

The body is the only **portable** surface (§5.6.3). Pills are bound to a file id and do
not survive a copy or a trip out of Nextcloud; the `tags` array is bytes in the file. So
a tag applied to a file sitting *outside* a mapping only survives if it reaches the
body. With the old gate it never did — it lived in the pills and died the moment the
file moved.

Which means the transport case now closes end to end:

1. tag a `.n8n.json` while it sits anywhere → the body records it, `{"name": "foo"}`,
   no id, because n8n has never seen it and pretending otherwise would be a lie;
2. move or copy the file into a mapped folder → no metadata, so this is unambiguously
   a create, and the body is the only record of the tags → they seed the new workflow;
3. the next pull writes n8n's canonical `{id,name}` rows back.

Step 2 is also the **adoption defect** found in §5.6.3 finally fixed: `CreateService`
was discarding every tag an arriving file carried, because `toCreateBody`'s whitelist
omits `tags` and n8n marks the field readOnly on create anyway. It now ensures the
body's names and unions them with the mapping tag through
`PUT /workflows/{id}/tags` — the only writer n8n offers.

A file being briefly "incomplete" on disk — a tag with a name and no id — is the
honest state, not a defect to paper over. **Write down what you do not know yet.**

> **Dr K, reading the ticket back:** *"So the thing blocking you was a rule you wrote
> yourself, and you'd been treating it like weather. That's the lesson, not the merge.
> And you had a gate asking 'is this mapped?' on a job that never once needed to leave
> the building — two of your three parties don't care about the other kitchen at all.
> Label the box before it goes on the truck. Even if all you know is what's in it."*

---

## §5.11 — Slice C: the churn, measured, and the one line that caused it

§5.6.3 named this by reading: *"`SyncService::writeWorkflow` calls `putContent($body)`
**unconditionally**, for every workflow, on every pull."* It was filed as Slice C and
left. Command then hit it in production, with the schedule turned down to five minutes:

> *"the last updated changes on every single round … every 5 minutes it says every
> single n8n file has been updated. i did not change anything in n8n in between."*

### Measured on the live instance before touching any code

The prediction was worth checking rather than trusting, because the interesting
question is not *"does it write?"* — the code plainly does — but **whether n8n was
handing back a different body each round.** If it were, the bug would be a
normalisation problem, not a write problem, and skipping the write would be wrong.

Two consecutive pulls, five minutes apart, over the live mapping:

| | before | after |
|---|---|---|
| filecache `mtime` | `14:38:41` | `14:43:42` |
| `n8n_syncedHash` | `550017e1482c…` | `550017e1482c…` |
| `n8n_versionId` | `7b83daf0…` | `7b83daf0…` |

All 14 mirrors, one second apart each round — the pull loop, ticking. **The stamped
hash is `sha1($body)` of the body the pull just wrote, so an unchanged hash across two
rounds is proof the bytes were identical and written anyway.** n8n's rows are stable;
only our write was not. That single table is the entire diagnosis, and it also
retired the alternative theory (a volatile field in n8n's response) without a debate.

### The fix is smaller than the branch table §5.6.3 imagined

The plan was three branches — skip / body / tags-only. It collapses to **one**
question, because a tags-only change in n8n *is* a body difference: the mirror is the
n8n row verbatim, so a new tag arrives inside the `tags` array and the "tags-only"
branch is just the body branch landing on a smaller diff. What remained:

```php
$wrote = $this->bodyDiffers($existing, $body);
if ($wrote) { $existing->putContent($body); }
```

### The half of the change that turned out to be already done

The first draft also made `stampSynced` conditional, with a `sameStamp()` comparison
of the five metadata keys. Reading Nextcloud core deleted that code:

- `FilesMetadata::setString()` — *"we ignore if value and index have not changed"*, and
  never sets the `updated` flag;
- `FilesMetadataManager::saveMetadata()` — returns immediately on `!$filesMetadata->updated()`.

So the metadata layer had been silently no-oping unchanged writes all along, and the
tag reconcile is diff-based for the same reason. **`putContent` was the only write in
the method without a guard of its own**, which is precisely why it was the only one
anybody noticed. The lesson is the recurring one: check whether the platform already
solved it before adding a second mechanism that says the same thing.

### Compare bytes, not the stamp

`n8n_syncedHash` is the obvious change-detector and it is the wrong one. It records
what the last sync *agreed on*, not what is on disk now — so a mirror that drifted
since (a push that failed, a hand edit, a half-written file) would compare equal to
n8n's body and be left broken forever, and the pull would have quietly stopped being
able to heal anything. Comparing the file's real bytes keeps "n8n is authoritative"
exactly as it was. The filecache `size` is a free pre-check that can only ever answer
*differs*, so a genuinely-changed workflow never pays for the read.

An unreadable mirror answers "differs" too. Degrade toward the old behaviour, never
toward silence.

### Where the scenarios go — "modified" is a result, not a use case

The first draft of the specs got this wrong in an instructive way. Having written *"a
quiet pull rewrites nothing"*, the obvious next move looked like its mirror image: a
scenario per direction — *a user edits a file → its mtime moves*, *a workflow changes
in n8n → its mirror's mtime moves*. Command stopped it:

> *"'updated at' changing is not a behavior that is necessarily performed by a user.
> The metadata for updated and created is more like end results to different changes —
> move and copy and rename and edit would all result in the updated changing. Then we
> would just need to verify that when nothing changes between reconciles the meta is
> not updated."*

That is the `features/README.md` rule (*a feature is a BEHAVIOUR*) applied to a field
rather than to a file, and it cuts cleanly. **A modification time has no primary
actor.** It is the shared *outcome* of four gestures that already own their own
files — so a scenario asserting the mtime moved after an edit is not specifying this
app at all; it is specifying Nextcloud, in the wrong file, with an invented actor.

Command then named the second half of it, an argument already had once with the
apprentice on `nextcloud-penpot`:

> *"There is only one behavior that directly results in a pull, and that is an admin
> button that syncs from n8n. The scheduled reconcile is a machine that does a bunch
> of things and is not a behavioral feature — it is a state machine that makes the end
> result of 'n8n origin' behaviors be reflected in Nextcloud. So 'rename in n8n' is
> just handled by reconciler sync-from-n8n, but the RENAMING is the behavior and the
> reconciler is the HOW."*

Two rules, and together they place every scenario in this area without further debate:

| Not a behaviour | Because | So it is specified as |
|---|---|---|
| a modification time changing | it is the shared *result* of edit / move / copy / rename | nothing — the gesture's own file already owns it |
| the reconciler running | it is the *mechanism* an `@in-n8n` behaviour arrives by | the behaviour, tagged `@in-n8n`; the pull is just the `When` |

Which leaves exactly one thing that IS a behaviour here: **the admin presses "Sync
from n8n" and nothing has changed.** One scenario, in `reconcile.feature` — the file
that owns what a run does *as a run*. That claim is ours and it is not automatic.

The one thing that must not be lost is the negative control — "rewrites nothing" is
also satisfied by a pull that has stopped writing entirely. So it lives with the
behaviour that already supplies it: `tag-sync.feature`'s content-change scenario
(`@n8n @in-n8n` — a workflow's nodes changed in n8n) gained a single line, `Then the
file is rewritten`. No new actor, no new file, and the two claims fail in opposite
directions.

The draft that got binned had five scenarios across two files, two of them with
invented actors. The rule that killed them is one line of `features/README.md` —
*a feature is a BEHAVIOUR, not a mechanism* — applied to a **field** and to a **job**
rather than to a file.

### What a run reports

`pullOne` now also returns `unchanged`, a subset of `succeeded`, surfaced in the Sync
Actions panel. The counter is the point, not decoration: a run that inspected 14 files
and reported "14 synced" was telling an admin the same number whether it had done
everything or nothing.

> **Dr K, unimpressed by the ceremony:** *"You wrote a three-branch plan for a
> one-branch problem, and half the branch you did keep was already handled by the
> house. Next time read the manual before you write the memo. But you measured before
> you cut — before, not after — and that is the only part of this I would put on a
> plate."*

---

## §5.12 — The clock the file was never wearing

§5.11 stopped the pull touching what it had not changed. That immediately exposed the
question underneath, which Command put in four lines:

```
3:00  workflow edited in n8n          ← the true "modified"
3:02  reconciler pulls, rewrites file
3:02  Nextcloud stamps mtime          ← what the file reports
```

The mtime was never *lying* — it faithfully recorded when the app wrote that node. It
was answering a question nobody asks. And the two-minute case is the **best** it did:
a workflow nobody had touched since March reported the moment its mirror was created,
off by months. `creation_time` was wrong the same way and worse, because once the file
exists there is no "before" left to reconstruct it from.

Neither app had ever set either clock — zero `touch` / `setMTime` / `creation_time`
writes across all three `lib/` trees. The apprentice had specified the fix
(`file-type.feature`) without building it, and named the trap in the same breath:

> *a naive implementation writes the timestamp every run, which is exactly the churn
> `reconcile.feature` forbids — and which the sibling app demonstrably has.*

### The trap is real, and the stated reason for it is wrong

This is the part worth the section. The warning reads as *"touch() bumps the etag, so
every desktop re-downloads every mirror"* — the same argument penpot's `storeLink()`
docblock makes about rewriting a `link`. Measured on the live instance rather than
inherited:

| | result |
|---|---|
| file's own etag after `touch()` | **unchanged** — `6a6f5aea65a79` → `6a6f5aea65a79` |
| **parent folder's** etag after `touch()` | **changed** — `6a6f71a7cf733` → `6a6f71eae4fc5` |

So the file-level fear is unfounded, and the real hazard is one level up: **a folder
etag is exactly what sync clients poll to decide "something in here changed, re-scan
it."** An unconditional stamp would not have churned the files. It would have churned
the *folder*, on every tick, forever — §5.11's defect relocated somewhere harder to
see and nobody watching.

The right conclusion and the wrong reason would have produced the same code this
time. It would not next time, which is why it is written down.

### What that bought

`MirrorTimes` — small, and deliberately its own class rather than four lines in the
reconciler. Reading both clocks is public API (`OCP\Files\Node extends FileInfo`), and
so is setting the mtime (`Node::touch()`). Setting the **creation** time is not:
`getCreationTime()` has no setter and the value lives in the filecache extension table,
so the supported route is `Node::getStorage()` → `IStorage::getCache()` →
`ICache::update()`. Three hops of framework plumbing with no business in a reconciler
loop — and the siblings now port one class instead of re-deriving them.

Every write is conditional, which also makes it self-healing: a mirror written before
this existed is corrected on the next pull and then left alone forever. Proven by
accident — a probe had left one file stamped `2026-03-01` / `2026-01-09`, and the first
pull with the feature moved it to n8n's real `2026-07-24` / `2026-06-24` while reporting
`unchanged: 14`. Fourteen mirrors re-clocked, not one body rewritten, and the folder
etag never moved.

### Where the scenarios went — the rule, applied a third time

None of this got a scenario of its own. Command had already ruled twice that a
modification time is a **result**, not a behaviour, and that the reconciler is the
*how*, not the *what*. So the assertions attach to the behaviours that cause them:

| assertion | rides on |
|---|---|
| the mirror's mtime is the workflow's `updatedAt` | *a content change in n8n reaches the mirror* (`tag-sync.feature`) |
| the mirror's creation time is the workflow's `createdAt` | *a pull brings tagged workflows in as files* (`reconcile.feature`) — the moment the mirror comes into existence |

Which retires the apprentice's three standalone `@unbuilt` timestamp scenarios as
mis-modelled by the same rule: two are end states of behaviours it already specs, and
the third — *"setting the times never makes an unchanged pull look like a change"* —
is not a timestamp scenario at all. It is the churn rule, and it already lives in
`reconcile.feature`.

> **Dr K, holding the plate up to the light:** *"You inherited a warning, and the
> warning was right. Then you checked it anyway and found it was right about the wrong
> thing — the danger wasn't the plate, it was the tray it goes back on. Most cooks
> would have shipped the correct dish for the incorrect reason and never known.
> Measuring the thing you already agree with is the whole job."*

---

## One extension, because Nextcloud only reads one

**Outcome: `.n8n.json` is retired. Workflow files are `.n8n`.** The analysis below is
what the decision was made on; the last section is what shipped.

> The copy fix landed here as a port from the grafana sibling, which had already
> spent a night on it. It arrived carrying the same root cause, because this app is
> where the shape was invented. Worth naming before we build anything else on it.

### The tally

Every one of these exists because our files are `Name.n8n.json` and not
`Name.n8n`:

| | the workaround | why it exists |
|---|---|---|
| 1 | `FilenameCodec::canonicalise()` | Nextcloud spells a collision `Name.n8n (1).json`; we spell it `Name (1).n8n.json` |
| 2 | the copy the app could not see | that spelling does not end in `.n8n.json`, so every predicate said "not ours" |
| 3 | the counter landing inside the extension | the browser's `getUniqueName()` splits on the LAST dot, and to it our file is a `.json` called `Name.n8n` |
| 4 | `updateFilecache('n8n.json')` for the icon | `detectPath()` reads only the last segment, so it answers `application/json` — the custom type has never come from detection |
| 5 | `isTrashedWorkflowName()` | the trash appends `.dNNNN`, so `str_ends_with` is false for every trashed file |
| 6 | the ~60s convergence window after a copy | the rename cannot happen before the client stats the path it chose |
| 7 | a proposed browser-side rename | to close that window |

**The control group is `penpot_sync`.** One segment, `.penpot`. It has none of these —
not one. `detectPath('Board (1).penpot')` answers `application/vnd.penpot` without help,
the counter lands where a user expects, and a copy is just a copy.

### What we would be buying

A single-segment `.n8n` extension puts us back on Nextcloud's own model:
one extension, last segment, everything downstream works by default. Items 1, 2, 3, 4
and 6 above stop existing rather than getting better implementations.

### What we would be paying

Real costs, and they are the reason the compound form was chosen:

- **A `.n8n` file is not a `.json` file to the rest of the computer.** Editors,
  `jq`, git diff colouring, and every tool outside Nextcloud stop recognising it.
- **They ARE JSON**, with a schema — so the extension would be lying about the format
  to everything except us.
- **Nobody authors a `.n8n` file by hand.** Someone making a workflow file from
  scratch reaches for `.json`, so the New-menu entry becomes the only comfortable way
  in.

### The shape it would probably take

Not a global flip: **a per-mapping setting, immutable like `sync`/`link`.** A mapping
declares which on-disk shape it uses when it is created and never changes it, for the
same reason mode is immutable — the files already on disk are the migration cost, and
an admin toggling it would rewrite a folder underneath its users.

There is a real argument for supporting the single-segment form ONLY, on the principle
that has already cost us twice: **stay on the platform's model so we are not
permanently reconciling two of them.** Two supported shapes is two code paths forever;
one shape is a migration once.
### The tax nobody costed: a table-wide UPDATE on every write

The tally above is all correctness. This one is throughput, and it is the finding that
changes the argument.

`detectPath('Name.n8n.json')` answers `application/json` — Nextcloud reads only the
last segment, so **every file this app writes lands with the wrong mimetype** and has to
be corrected afterwards. The correction is `IMimeTypeLoader::updateFilecache()`, which is

    UPDATE oc_filecache SET mimetype = ? WHERE LOWER(name) LIKE '%.n8n.json'

— the whole table, not the row just written. The grafana sibling's `NodeWrittenListener` says why it cannot be
narrowed: *"every NodeWrittenEvent implies NC's scanner re-detected mime off the path's
last extension … so it must run on every write."*

Count the call sites and the two designs separate cleanly:

| | `penpot_sync` (`.penpot`) | `n8n_sync` / `grafana_sync` (`.x.json`) |
|---|---|---|
| `updateFilecache` sites | **2** — install and uninstall | **4** / **5** — install, uninstall, **and the hot path** |
| runs per file write | never | **every write to one of our files** |
| mimetype from NC's own detection | yes | no — corrected after the fact, forever |

penpot registers `"penpot"` and is simply done. We register `"n8n.json"`, which the
detector cannot see, and pay for it on every save and every sync tick.

### One shape or two — the Gherkin answers this

Supporting BOTH shapes reads like the safe option and is the expensive one. The
extension appears in **37 filename mentions across 5 feature files** here (74 across 10
in the grafana sibling). A second shape makes every one of those an axis — an Examples
column on scenarios that are not about naming at all, doubling the run and the reading
cost of files whose subject is copy, move, trash, or tags.

Going single-segment ONLY is a find-and-replace over the same mentions. No new axis, no
new scenarios, no new run time. **The spec cost is the argument for picking one shape,
and it points the same way the code does.**

### What would actually go away

Of the seven, **five stop existing** rather than getting better implementations:
`canonicalise()`, the invisible copy, the misplaced counter, the runtime mimetype
re-stamps, and the convergence window — and the browser-side rename never needs writing.

**One survives, and it is worth being honest about it:** the trash appends `.dNNNN` to
whatever the name was, so `Name.n8n.d1712345678` still fails a `str_ends_with`
check. `isTrashedWorkflowName()` is needed either way. A single segment is not a cure
for every filename problem — only for the ones we invented.

### What it would cost

- **A `.n8n` file is not JSON to the rest of the computer.** Mitigable per tool —
  VS Code takes `"files.associations": {"*.n8n": "json"}`, git takes a
  `.gitattributes` line — but it is a real papercut for anyone working outside Nextcloud.
- **Migration is the unanswered half.** On this instance it is small (20 n8n files,
  11 grafana) — and **this app is the one published on the App Store**, so its population is not
  ours to count — the migration bar is higher here than in the sibling. A repair step renaming `*.x.json` → `*.x` is straightforward; the trash,
  file versions, and already-synced desktop clients are the parts to think through.
- **The setting shape** would be per-mapping and immutable, like `sync`/`link` — though
  the section above argues the honest answer may be no setting at all.


### What shipped

`FilenameCodec::EXT` is `.n8n`, and the counter now goes **last**, immediately before
the extension — the same position `getUniqueName()` puts it. That single alignment is
the whole cut: our spelling of a collision and Nextcloud's are one spelling, so there
is nothing to fold on the way in and nothing to rename on the way out. `canonicalise()`,
`isNextcloudSpelling()` and `ReconcileNameJob`'s `canonicaliseSpelling()` are gone.

**And an entire listener went with them.** `MimeRestampListener` existed for one
reason: a rename re-detected the mime off `.json` and the icon vanished, so a
`NodeRenamedEvent` handler re-ran the table-wide UPDATE to put it back. Its own
docblock called that UPDATE "idempotent, cheap". Measured on the live instance it is a
sequential scan of 20,144 filecache rows at ~26ms — a leading-wildcard `LIKE` cannot
use `fs_name_hash`, so the index is useless by construction. Four `updateFilecache`
call sites became one install and one uninstall, which is exactly what `penpot_sync`
has always had.

The id-suffixed shape needed one thought. `Board.<id>.n8n` collides into
`Board.<id> (1).n8n`, so `parse()` takes the counter off **before** it looks for the
id — the other order reads the id as `<id> (1)` and drops the identity on the one
gesture most likely to need it.

`MigrateFileExtension` renames existing files on upgrade, and **this app keeps it**.
The grafana sibling deleted its copy the moment it had run: that app is unpublished,
so there was exactly one instance to migrate and it was already done — 11 files, 0
failures, verified in `oc_filecache`. This one is on the App Store. Its population is
not ours to count, and an admin upgrading from an older release a year from now still
needs their files renamed. It comes out a version or two later.

The Gherkin came out the way the argument said it would: **not one new scenario, not
one new Examples column.** Every feature file changed by the length of a string.

**And one workaround survives, exactly as predicted:** the trash appends `.dNNNN`, so
`isTrashedWorkflowName()` is still needed. A single segment cures the problems we
invented, not every filename problem.

---

Sources / cross-links:
- [`n8n_sync` on the Nextcloud App Store](https://apps.nextcloud.com/apps/n8n_sync)
- [`nextcloud-grafana` saga, Chapter 1 — Mise en Place](https://github.com/kubed-io/nextcloud-grafana/blob/main/saga/Chapter_1_Mise_en_Place.md) — the apprentice's side of the cameo.
- This chapter's work: the connection-UX PR, the Copilot instruction files, the repo
  Security-&-Quality parity, the tag-sync engine (§5.6), and the delete/purge fix (§5.7).
- Nextcloud `stable33`: `apps/files_trashbin/lib/Trashbin.php`,
  `lib/Trash/LegacyTrashBackend.php`, `lib/Sabre/AbstractTrash.php` — the purge chain
  above, read rather than assumed.
