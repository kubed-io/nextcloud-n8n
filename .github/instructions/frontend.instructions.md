---
applyTo: "**/*.js"
---
<!--
  SPDX-FileCopyrightText: 2026 Kelly Ferrone
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Frontend (vanilla JS) conventions

Applies to the small JS frontend. Cross-cutting rules are in
`.github/copilot-instructions.md` and `AGENTS.md`.

## Two kinds of JS — don't mix them up
- Admin **panel scripts live in `js/` and are hand-written vanilla JS with NO build
  step** (loaded via `Util::addScript`). Keep them dependency-free and CSP-safe — no
  imports of runtime npm packages, no inline remote URLs.
- Only **`src/` goes through Vite** → `dist/…-files.js` (the Files-app row script).

## Use Nextcloud's browser globals (don't reinvent them)
- i18n: `t('<app_id>', 'text')` for every user-facing string — never a raw literal.
- URLs: `OC.generateUrl('/apps/<app_id>/…')` — never hardcode the path or origin.
- Requests: send `OC.requestToken` as the `requesttoken` header on state-changing
  fetches, and surface the JSON error `message` for user feedback.

## Style & safety
- eslint must pass. No `console.log` (info/warn/error are allowed).
- Escape any server- or user-derived string before inserting it into the DOM.
