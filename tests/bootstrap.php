<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Standalone unit-test bootstrap.
 *
 * The unit suite runs with nothing but PHP + the classes under test (see
 * Chapter 2 §4 "the unit ↔ integration boundary"). Composer's autoloader maps
 * OCA\N8nSync\ → lib/ for the app code, OCA\N8nSync\Tests\ → tests/ for the
 * tests, and pulls in nextcloud/ocp so NC interfaces are resolvable for the
 * classes whose collaborators get mocked. No Nextcloud server tree is required.
 *
 * `dg/bypass-finals` strips the `final` keyword as classes are autoloaded so the
 * mock builder can double our `final` services (e.g. N8nClient) — the §12.1
 * paydown made most classes final, which PHPUnit otherwise refuses to mock.
 *
 * The integration suite (later) does NOT use this bootstrap — it runs against
 * the §4a docker-compose stack.
 */
require_once __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp has no autoload block, so OCP base symbols don't resolve
// standalone — these declaration-only shims let app classes that reference an
// OCP symbol (e.g. Application's APP_ID constant) autoload. See the file header.
require_once __DIR__ . '/ocp-stubs.php';

// Stubs for classes from other bundled apps + Sabre/DAV (LinkWriteGuardPlugin's
// collaborators) that nextcloud/ocp doesn't ship. Loaded after ocp-stubs.php so
// their OCP parents (e.g. EventDispatcher\Event) already exist.
require_once __DIR__ . '/external-stubs.php';

\DG\BypassFinals::enable();
