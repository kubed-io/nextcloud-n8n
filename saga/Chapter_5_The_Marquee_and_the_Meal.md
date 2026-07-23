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

> **Dr K, refilling both glasses:** *"Chapter 4 got you on the marquee. Chapter 5 is
> the part they don't put on the poster — the quiet fixes, the second cook you trained,
> and a meal with someone who finally speaks your language. Chapter's open. Eat slow."*

---

Sources / cross-links:
- [`n8n_sync` on the Nextcloud App Store](https://apps.nextcloud.com/apps/n8n_sync)
- [`nextcloud-grafana` saga, Chapter 1 — Mise en Place](https://github.com/kubed-io/nextcloud-grafana/blob/main/saga/Chapter_1_Mise_en_Place.md) — the apprentice's side of the cameo.
- This chapter's work: the connection-UX PR (missing-vs-rejected, "is a key stored?"), the Copilot instruction files (`.github/copilot-instructions.md` + `.github/instructions/*`), and the repo Security-&-Quality parity — all landed on the same branch as this chapter opened.
