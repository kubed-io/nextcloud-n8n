<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCA\N8nSync\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Dedicated admin settings section so the form gets its own entry in the
 * sidebar instead of living under "Additional settings". Uses the built-in
 * core "workflow" category icon (no shipped asset needed).
 */
class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('n8n');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'categories/workflow.svg');
	}
}
