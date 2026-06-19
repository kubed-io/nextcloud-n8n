<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('build')
	->notPath('dist')
	->notPath('l10n')
	->notPath('node_modules')
	->notPath('src')
	->notPath('vendor')
	->in(__DIR__);
return $config;
