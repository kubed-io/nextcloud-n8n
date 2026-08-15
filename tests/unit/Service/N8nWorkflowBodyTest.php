<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\N8nWorkflowBody;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see N8nWorkflowBody} — the single owner of n8n's request/file
 * body shapes. These pin the schema quirks that were previously duplicated across
 * CreateService / PushService / SyncService / ModeChangeService, so the external
 * contract can't silently drift (the C2 gap from the Chapter 4 review).
 */
#[CoversClass(N8nWorkflowBody::class)]
final class N8nWorkflowBodyTest extends TestCase {
	private function obj(array $props): \stdClass {
		return (object)$props;
	}

	// ── toUpdateBody ─────────────────────────────────────────────────────────────

	public function testUpdateBodyKeepsOnlyWritableFields(): void {
		$wf = $this->obj([
			'name' => 'Flow',
			'nodes' => [['x' => 1]],
			'active' => true,        // read-only → dropped
			'id' => 'wf-1',          // read-only → dropped
			'staticData' => $this->obj(['k' => 'v']),
		]);
		$body = N8nWorkflowBody::toUpdateBody($wf);
		self::assertSame(['name', 'nodes', 'staticData'], array_keys($body));
		self::assertArrayNotHasKey('active', $body);
		self::assertArrayNotHasKey('id', $body);
	}

	public function testUpdateBodyThrowsWhenNoWritableFields(): void {
		$this->expectException(\RuntimeException::class);
		N8nWorkflowBody::toUpdateBody($this->obj(['active' => true, 'id' => 'wf-1']));
	}

	public function testUpdateBodyDropsUnknownSettingsKeys(): void {
		$wf = $this->obj([
			'name' => 'Flow',
			'settings' => $this->obj([
				'executionOrder' => 'v1',   // allowed
				'callerPolicy' => 'any',    // not in the allowlist → dropped
			]),
		]);
		$settings = N8nWorkflowBody::toUpdateBody($wf)['settings'];
		self::assertInstanceOf(\stdClass::class, $settings);
		self::assertSame('v1', $settings->executionOrder);
		self::assertObjectNotHasProperty('callerPolicy', $settings);
	}

	public function testUpdateBodyCoercesEmptyObjectFieldsToStdClass(): void {
		// n8n's GET serialises empty objects as `[]`; the PUT validator demands `{}`.
		$wf = $this->obj([
			'name' => 'Flow',
			'connections' => [],
			'settings' => [],
			'staticData' => [],
		]);
		$body = N8nWorkflowBody::toUpdateBody($wf);
		self::assertInstanceOf(\stdClass::class, $body['connections']);
		self::assertInstanceOf(\stdClass::class, $body['settings']);
		self::assertInstanceOf(\stdClass::class, $body['staticData']);
	}

	public function testNodesEmptyArrayStaysAList(): void {
		// `nodes` is a list, not an object — an empty `[]` must stay `[]`.
		$body = N8nWorkflowBody::toUpdateBody($this->obj(['name' => 'Flow', 'nodes' => []]));
		self::assertSame([], $body['nodes']);
	}

	// ── toCreateBody ─────────────────────────────────────────────────────────────

	public function testCreateBodyPrefersJsonNameOverFilename(): void {
		$body = N8nWorkflowBody::toCreateBody($this->obj(['name' => 'From JSON']), 'From File.n8n');
		self::assertSame('From JSON', $body['name']);
	}

	public function testCreateBodyFallsBackToFileStem(): void {
		$body = N8nWorkflowBody::toCreateBody($this->obj([]), 'My Workflow.n8n');
		self::assertSame('My Workflow', $body['name']);
	}

	public function testCreateBodyFallsBackToUntitled(): void {
		$body = N8nWorkflowBody::toCreateBody($this->obj([]), 'not-a-workflow.txt');
		self::assertSame('Untitled', $body['name']);
	}

	public function testCreateBodyAppliesStarterDefaults(): void {
		$body = N8nWorkflowBody::toCreateBody($this->obj([]), 'New.n8n');
		self::assertSame([], $body['nodes']);
		self::assertInstanceOf(\stdClass::class, $body['connections']);
		self::assertInstanceOf(\stdClass::class, $body['settings']);
	}

	// ── encodeReference / encodeSync ─────────────────────────────────────────────

	public function testEncodeReferenceBuildsDeepLinkAndTags(): void {
		$body = N8nWorkflowBody::encodeReference([
			'id' => 'wf-9',
			'name' => 'Pointer',
			'tags' => [['name' => 'team:flows'], ['name' => 'nextcloud:alpha']],
		], 'https://n8n.example.com/');
		$decoded = json_decode($body, true);
		self::assertSame('n8n.reference/v1', $decoded['$schema']);
		self::assertSame('wf-9', $decoded['id']);
		self::assertSame('https://n8n.example.com/workflow/wf-9', $decoded['url']);
		self::assertSame(['team:flows', 'nextcloud:alpha'], $decoded['tags']);
	}

	public function testEncodeReferenceNullUrlWhenNoBase(): void {
		$decoded = json_decode(N8nWorkflowBody::encodeReference(['id' => 'wf-9', 'name' => 'P'], ''), true);
		self::assertNull($decoded['url']);
	}

	public function testEncodeSyncRoundTrips(): void {
		$workflow = ['id' => 'wf-1', 'name' => 'Flow', 'nodes' => [['x' => 1]]];
		self::assertSame($workflow, json_decode(N8nWorkflowBody::encodeSync($workflow), true));
	}
}
