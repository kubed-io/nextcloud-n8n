<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Settings;

use OCA\N8nSync\Service\WritebackStrategy;
use OCA\N8nSync\Settings\AutoSyncSettings;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

/**
 * This form owns its own storage ({@see AutoSyncSettings} docblock) precisely
 * because core's INTERNAL path cannot round-trip a CHECKBOX: it TypeErrors on the
 * bool going out AND on the bool default coming back. So the coercion below is the
 * whole feature, and these pin it.
 */
final class AutoSyncSettingsTest extends TestCase {
	private function form(IAppConfig $config): AutoSyncSettings {
		return new AutoSyncSettings($config);
	}

	private function user(): IUser {
		return $this->createMock(IUser::class);
	}

	public function testDeclaresExternalStorageSoCoreDoesNotTouchTheCheckbox(): void {
		$schema = $this->form($this->createMock(IAppConfig::class))->getSchema();
		self::assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL, $schema['storage_type']);
	}

	/** The checkbox reads back as a real bool — what the frontend round-trips. */
	public function testCheckboxReadsAsABool(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn(true);
		self::assertTrue($this->form($config)->getValue('schedule_enabled', $this->user()));
	}

	/**
	 * A value written by the OLD internal path is string-typed, so getValueBool
	 * raises an AppConfigTypeConflict. Reporting the schedule as off there would
	 * silently stop the pull, so the string is parsed instead.
	 */
	public function testCheckboxFallsBackToTheStringWhenTheStoredTypeConflicts(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willThrowException(new \RuntimeException('type conflict'));
		$config->method('getValueString')->willReturn('1');
		self::assertTrue($this->form($config)->getValue('schedule_enabled', $this->user()));
	}

	public function testCheckboxIsStoredBoolTypedSoTheJobsPrimaryReadWorks(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('setValueBool')
			->with('n8n_sync', 'schedule_enabled', true);
		$this->form($config)->setValue('schedule_enabled', true, $this->user());
	}

	/** The frontend may send a bool, an int, or a string — all mean the same thing. */
	public function testCheckboxAcceptsEveryTruthyWireShape(): void {
		foreach ([true, 1, '1', 'true', 'yes', 'on'] as $wire) {
			$config = $this->createMock(IAppConfig::class);
			$config->expects(self::once())->method('setValueBool')->with('n8n_sync', 'schedule_enabled', true);
			$this->form($config)->setValue('schedule_enabled', $wire, $this->user());
		}
		foreach ([false, 0, '0', 'false', 'no', 'off', ''] as $wire) {
			$config = $this->createMock(IAppConfig::class);
			$config->expects(self::once())->method('setValueBool')->with('n8n_sync', 'schedule_enabled', false);
			$this->form($config)->setValue('schedule_enabled', $wire, $this->user());
		}
	}

	/**
	 * THE FORM NO LONGER ASKS ABOUT WRITEBACK TIMING, and this is what says so.
	 *
	 * A `timing` radio used to live here, pinned to `sync`/`async`. It is gone (saga
	 * Ch5): inline-vs-queued is derived per request by {@see WritebackStrategy} from
	 * whether a job could run at all, which is not a question an admin can answer.
	 *
	 * Asserted as the WHOLE field list rather than "timing is absent", because the
	 * failure worth catching is the form quietly regrowing a knob — and because a
	 * settings form that lost `schedule_interval` would satisfy any negative check.
	 */
	public function testTheFormAsksOnlyAboutTheSchedule(): void {
		$config = $this->createMock(IAppConfig::class);
		$ids = array_map(
			static fn (array $f): string => (string)($f['id'] ?? ''),
			$this->form($config)->getSchema()['fields'],
		);
		self::assertSame(['schedule_enabled', 'schedule_interval'], $ids);
	}

	/** A field the form does not own must not be written just because it was POSTed. */
	public function testARetiredFieldIsIgnoredRatherThanStored(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::never())->method('setValueString');
		$config->expects(self::never())->method('setValueBool');
		$this->form($config)->setValue('timing', 'sync', $this->user());
	}

	/** An emptied interval box means "the default", not "every zero seconds". */
	public function testEmptyIntervalFallsBackToTheDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('setValueString')
			->with('n8n_sync', 'schedule_interval', AutoSyncSettings::DEFAULT_INTERVAL);
		$this->form($config)->setValue('schedule_interval', '   ', $this->user());
	}

	/**
	 * The interval is stored as typed (trimmed): ScheduledPullJob owns parsing it
	 * and already falls back to hourly on anything it cannot read, so validating
	 * twice in two places would only let the two disagree.
	 */
	public function testIntervalIsStoredAsTypedButTrimmed(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())->method('setValueString')->with('n8n_sync', 'schedule_interval', '15m');
		$this->form($config)->setValue('schedule_interval', ' 15m ', $this->user());
	}

	public function testUnknownFieldIsIgnoredRatherThanWritten(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::never())->method('setValueString');
		$config->expects(self::never())->method('setValueBool');
		$this->form($config)->setValue('not_a_field', 'x', $this->user());
		self::assertNull($this->form($config)->getValue('not_a_field', $this->user()));
	}
}
