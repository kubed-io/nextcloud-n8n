---
description: 'PHP / Nextcloud backend conventions for review'
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
  promotion for injected dependencies, and `readonly` for values that never change
  after construction.
- Put `#[\Override]` on every method that implements an interface or overrides a
  parent (php-cs-fixer/Psalm enforce it here).
- PSR-4: the path under `lib/` mirrors the namespace segment-for-segment
  (case-sensitive) — a mismatch is a silent autoload break, not an error.
- Style is `nextcloud/coding-standard` via `.php-cs-fixer.dist.php` (tabs, PSR-12
  base). Don't hand-format against it.

## Dependency injection — never bypass the container
- Type-hint OCP interfaces in constructors and let the server autowire them.
- Flag any `new SomeService(...)` of an injectable, any use of `\OC::$server` or
  `\OC::$SERVERROOT`, or any static service locator — review blockers here.
- Register services / listeners / commands / settings the framework way
  (`info.xml` + `Application::register`), not ad-hoc.
- Use the **modern** OCP APIs: `IAppConfig` (not the deprecated `IConfig`) for app
  config; `IClientService` for HTTP; `ICrypto` for secrets; `OCP\BackgroundJob\*`
  for async.

## Types & docblocks (keep it Psalm-clean)
- Type every parameter, return, and property. Prefer real types; use PHPDoc only
  where PHP can't express it (generics like `list<string>`, `array<string,mixed>`,
  shaped arrays).
- Make nullability explicit (`?Foo`, `Foo|null`) — no implicit null defaults.
- Keep `@param`/`@return` descriptions on a **single line** — php-cs-fixer's
  `phpdoc_align` reflows multi-line ones and produces noisy diffs.
- Don't silence Psalm with inline `@psalm-suppress` or new `tests/psalm-baseline.xml`
  entries on a feature branch — fix the finding. The baseline is a deferred-cleanup
  ledger, not a dumping ground.

## Controllers, settings, commands
- Controllers stay thin — logic belongs in `Service/`. Gate admin endpoints with
  `#[AuthorizedAdminSetting(settings: …)]`; use routing/attribute metadata.
- occ commands extend the framework `Command`, return `0`/non-zero, and are
  registered in `info.xml`.
- Sensitive config uses the declarative `sensitive` flag (encrypted via `ICrypto`) —
  flag any secret stored or echoed in plaintext.

## Errors & secrets
- Throw typed exceptions (`Exception/…`), not error strings/arrays as control flow.
  Preserve `$previous` when wrapping so the cause survives.
- Never put a token/secret into a log line, exception message, or response body.

## Tests
- A `lib/` change should carry a PHPUnit test under `tests/unit/` in the **mirrored
  namespace**. Re-derive assertions from the spec, not from what the code does today.
- Unit tests **mock all collaborators** against the `nextcloud/ocp` interfaces — no
  running server, DB, or network. Integration behaviour goes to the Behat suite.
- Mocking a `final` class needs `dg/bypass-finals` enabled in the bootstrap
  (`createMock(FinalService::class)` throws otherwise) — this is already wired; don't
  drop `final` from production classes just to make them mockable.
- Prefer an explicit `->expects(self::once())` on a mock over
  `expectNotToPerformAssertions()`; the latter plus a configured mock trips a PHPUnit
  "risky/no-assertions" notice.
- When a type must resolve for **both** Psalm and the PHPUnit mock builder, add it to
  the shared stub file (`tests/ocp-stubs.php` / `tests/external-stubs.php`), which
  feeds both `psalm.xml`'s `<stubs>` and the bootstrap — one source of truth.

## CI parity
- The CI PHP version must match the production pod's PHP (currently 8.4);
  `php-cs-fixer` applies version-specific rules, so a mismatch makes CI and the pod
  disagree. Don't lower it casually.
