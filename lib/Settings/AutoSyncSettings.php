<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Settings;

use OCA\N8nSync\AppInfo\Application;
use OCA\N8nSync\Service\AppConfigReader;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * "Sync Settings" — the n8n→NC scheduled pull. (User-facing title "Sync Settings";
 * persistence is keyed by the form id `data_sync`, not the class name.) The
 * always-available bulk buttons live in their own dedicated panel
 * ({@see SyncSettings}); this form is config only.
 *
 * Values live in appconfig under each field id, read elsewhere by:
 *   - `schedule_enabled`  → {@see \OCA\N8nSync\BackgroundJob\ScheduledPullJob} (n8n→NC)
 *   - `schedule_interval` → same job's TimedJob interval (seconds)
 *
 * NC schedules by **interval** (TimedJob), not cron expressions.
 *
 * Same id-prefix gotcha as AdminSettings — the form id must NOT be prefixed with
 * the app id.
 *
 * ── WHY THIS FORM HANDLES ITS OWN STORAGE ────────────────────────────────────
 *
 * `STORAGE_TYPE_INTERNAL` CANNOT CARRY A CHECKBOX IN AN ADMIN FORM. This is a
 * core limitation, read out of `OC\Settings\DeclarativeManager` (verified on both
 * `master` and `stable32`, which are identical here), not a preference:
 *
 *   - **the write** — `saveInternalValue()` for `SECTION_TYPE_ADMIN` calls
 *     `IAppConfig::setValueString($app, $fieldId, $value)`. A CHECKBOX posts a
 *     real JSON `bool`, and `DeclarativeManager.php` declares `strict_types=1`,
 *     so the bool raises a **TypeError** and the save aborts.
 *   - **the read** — `getInternalValue()` passes the schema's `default` straight
 *     into `IConfig::getAppValue($app, $key, $default)`, whose third parameter is
 *     also typed `string`. So a `'default' => false` (which a CHECKBOX needs, and
 *     which is what core's own examples use) throws on the way *back out*.
 *
 * Both spellings are therefore broken, in opposite directions, and the toggle
 * springs back on reload with nothing shown to the admin. The earlier
 * `'default' => false` fix addressed only the frontend round-trip.
 *
 * `STORAGE_TYPE_EXTERNAL` + {@see IDeclarativeSettingsFormWithHandlers} hands the
 * two operations back to this class, which coerces each value to the type its
 * field actually means. Core calls {@see getValue}/{@see setValue} directly on the
 * registered form object — **no listener class and no event wiring** (see
 * `DeclarativeManager::getValue()`, which prefers the interface and only falls
 * back to `DeclarativeSettingsGetValueEvent` when the form does not implement it).
 *
 * The KEYS AND THEIR STORAGE ARE UNCHANGED — `occ config:app:get n8n_sync
 * schedule_interval` still reads exactly what it always did. Only who does the read
 * and write moves.
 *
 * THE `timing` RADIO IS GONE (saga Ch5). Whether a writeback runs inline or is queued
 * is derived from the environment by {@see \OCA\N8nSync\Service\WritebackStrategy}
 * rather than asked of an admin who could not have known the answer.
 *
 * The interface is `@since 31.0.0`, which is why `appinfo/info.xml` requires
 * Nextcloud ≥ 31.
 */
final class AutoSyncSettings implements IDeclarativeSettingsFormWithHandlers {
	/** Fallback pull cadence, used as both the placeholder and the stored default. */
	public const DEFAULT_INTERVAL = '1h';

	private const FIELD_SCHEDULE_ENABLED = 'schedule_enabled';
	private const FIELD_SCHEDULE_INTERVAL = 'schedule_interval';

	public function __construct(
		private IAppConfig $config,
		private AppConfigReader $reader,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			'id' => 'data_sync',
			'priority' => 33, // above Folder mappings (36); Sync Actions (45) comes last
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'n8n_sync',
			// EXTERNAL so getValue()/setValue() below own the coercion — see the
			// class docblock for why INTERNAL cannot carry the checkbox.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Sync Settings',
			'description' => 'Saving a workflow file always writes it back to n8n — there is nothing to configure for that direction. These settings are the other one: how often Nextcloud pulls from n8n. Sync Actions below runs either direction on demand.',
			'fields' => [
				[
					'id' => self::FIELD_SCHEDULE_ENABLED,
					'title' => 'n8n → Nextcloud: scheduled sync',
					'description' => 'Nextcloud periodically pulls from n8n; nothing in n8n changes. A change made in n8n appears here within the interval below. When off, use Sync from n8n in Sync Actions.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					// A real bool: this is what the frontend round-trips. It is safe
					// here only because EXTERNAL storage never feeds it to
					// IConfig::getAppValue() (see the class docblock).
					'default' => false,
				],
				[
					'id' => self::FIELD_SCHEDULE_INTERVAL,
					'title' => 'Schedule — how often',
					'description' => 'Number + unit (s/m/h/d), e.g. 15m, 1h, 6h, 1d. A plain number means seconds. Minimum 1m.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => self::DEFAULT_INTERVAL,
					'default' => self::DEFAULT_INTERVAL,
				],
			],
		];
	}

	/**
	 * Read one field for the settings UI, in the type that field means — a real
	 * `bool` for the checkbox, a `string` for the text box. Reads go through
	 * {@see AppConfigReader} because a value stored by the old INTERNAL path is
	 * string-typed and the typed getter would throw until the admin saves once.
	 */
	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			self::FIELD_SCHEDULE_ENABLED => $this->reader->bool(self::FIELD_SCHEDULE_ENABLED),
			self::FIELD_SCHEDULE_INTERVAL => $this->reader->string(self::FIELD_SCHEDULE_INTERVAL, self::DEFAULT_INTERVAL),
			default => null,
		};
	}

	/**
	 * Persist one field, normalising what the frontend sent. The interval is stored
	 * verbatim-but-trimmed because
	 * {@see \OCA\N8nSync\BackgroundJob\ScheduledPullJob} already owns parsing it
	 * (and falls back to hourly on anything it cannot read).
	 */
	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case self::FIELD_SCHEDULE_ENABLED:
				// setValueBool (not a '1'/'0' string) so the job's primary
				// getValueBool() read succeeds instead of falling through its
				// AppConfigTypeConflict rescue path.
				$this->config->setValueBool(Application::APP_ID, self::FIELD_SCHEDULE_ENABLED, AppConfigReader::coerceBool($value));
				break;
			case self::FIELD_SCHEDULE_INTERVAL:
				$raw = is_string($value) ? trim($value) : '';
				$this->config->setValueString(
					Application::APP_ID,
					self::FIELD_SCHEDULE_INTERVAL,
					$raw === '' ? self::DEFAULT_INTERVAL : $raw,
				);
				break;
		}
	}
}
