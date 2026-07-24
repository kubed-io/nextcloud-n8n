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

> **Dr K, reading the ticket off the rail:** *"Good — you cooked the sauce, not just wrote the
> recipe on the wall. And you kept it pure in the middle so it pours straight into the big pot
> later. Now: the pill and the paper still don't talk to each other till the whole plate goes
> out. That's your next fire. But the labels reach the guest now, both ways. The kid's still
> plating his base — you're serving. Stay ahead."*

---

---

Sources / cross-links:
- [`n8n_sync` on the Nextcloud App Store](https://apps.nextcloud.com/apps/n8n_sync)
- [`nextcloud-grafana` saga, Chapter 1 — Mise en Place](https://github.com/kubed-io/nextcloud-grafana/blob/main/saga/Chapter_1_Mise_en_Place.md) — the apprentice's side of the cameo.
- This chapter's work: the connection-UX PR (missing-vs-rejected, "is a key stored?"), the Copilot instruction files (`.github/copilot-instructions.md` + `.github/instructions/*`), and the repo Security-&-Quality parity — all landed on the same branch as this chapter opened.
