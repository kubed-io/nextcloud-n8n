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
  `N8nWorkflowBody::WRITABLE` deliberately excludes `tags`, and `PUT /workflows/{id}` ignores
  a `tags` field — tags are a **separate** write (`ensureTag` each name → id, then
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

---

---

Sources / cross-links:
- [`n8n_sync` on the Nextcloud App Store](https://apps.nextcloud.com/apps/n8n_sync)
- [`nextcloud-grafana` saga, Chapter 1 — Mise en Place](https://github.com/kubed-io/nextcloud-grafana/blob/main/saga/Chapter_1_Mise_en_Place.md) — the apprentice's side of the cameo.
- This chapter's work: the connection-UX PR (missing-vs-rejected, "is a key stored?"), the Copilot instruction files (`.github/copilot-instructions.md` + `.github/instructions/*`), and the repo Security-&-Quality parity — all landed on the same branch as this chapter opened.
