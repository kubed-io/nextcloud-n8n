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
 * The integration suite (later) does NOT use this bootstrap — it runs against
 * the §4a docker-compose stack.
 */
require_once __DIR__ . '/../vendor/autoload.php';
