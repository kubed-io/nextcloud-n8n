---
applyTo: "**/*.php"
---
<!--
  SPDX-FileCopyrightText: 2026 Kelly Ferrone
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# PHP / Nextcloud backend conventions

Applies to the PHP backend under `lib/` (namespace `OCA\N8nSync`) and the PHPUnit
tests under `tests/`. The cross-cutting rules — above all *be Nextcloud-native* —
live in `.github/copilot-instructions.md` and `AGENTS.md`; this file is the PHP
mechanics to enforce in review.

## File & class shape
- Every PHP file starts with the SPDX header and `declare(strict_types=1);`.
- Classes are `final` unless designed for extension. Use constructor property
  promotion for injected dependencies.
- Put `#[\Override]` on every method that implements an interface or overrides a parent.
- PSR-4: the path under `lib/` must mirror the namespace segment-for-segment
  (case-sensitive) — a mismatch is a silent autoload break.

## Dependency injection — never bypass the container
- Type-hint OCP interfaces in constructors and let the server autowire them.
- Flag any `new SomeService(...)` of an injectable, any use of `\OC::$server`, or any
  static service locator — these are review blockers here.
- Register services / listeners / commands / settings the framework way
  (`info.xml` + `Application::register`), not ad-hoc.

## Controllers, settings, commands
- Controllers stay thin — logic belongs in `Service/`. Gate admin endpoints with
  `#[AuthorizedAdminSetting(settings: …)]` and use routing/attribute metadata.
- occ commands extend the framework `Command` and are registered in `info.xml`.
- Sensitive config fields use the declarative `sensitive` flag (encrypted via
  `ICrypto`) — flag any secret stored or echoed in plaintext.

## Errors & secrets
- Throw typed exceptions (`Exception/…`); don't return error strings/arrays as control flow.
- Never put a token/secret into a log line, exception message, or response body.

## Tests
- A `lib/` change should come with a PHPUnit test under `tests/unit/` in the mirrored
  namespace. Re-derive assertions from the spec, not from what the code does today.
- Don't add entries to the Psalm baseline on a feature branch — fix the finding instead.
