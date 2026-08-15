<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\DAV;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\N8nSync\DAV\LinkWriteGuardPlugin;
use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\SyncNotifier;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;

/**
 * Unit tests for {@see LinkWriteGuardPlugin} (saga §14.2c — a link is read-only on disk).
 *
 * The load-bearing rule: a `link`-mode workflow file refuses a WebDAV overwrite with a
 * Sabre {@see Forbidden} (a 403), while every other state (sync / unmapped /
 * unmanaged) is left writable. Anything we can't classify is never blocked — fail open.
 * Collaborators are `final`, doubled via the unit bootstrap's `dg/bypass-finals`.
 */
#[CoversClass(LinkWriteGuardPlugin::class)]
final class LinkWriteGuardPluginTest extends TestCase {
	private WorkflowMetadata $metadata;
	private SyncNotifier $notifier;
	private IUserSession $userSession;
	private LinkWriteGuardPlugin $plugin;

	protected function setUp(): void {
		$this->metadata = $this->createMock(WorkflowMetadata::class);
		$this->notifier = $this->createMock(SyncNotifier::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('kelly');
		$this->userSession->method('getUser')->willReturn($user);

		$this->plugin = new LinkWriteGuardPlugin(
			$this->metadata,
			$this->notifier,
			$this->userSession,
			new NullLogger(),
		);
	}

	private function davFile(string $name = 'flow.n8n', int $id = 7): DavFile {
		$node = $this->createMock(DavFile::class);
		$node->method('getName')->willReturn($name);
		$node->method('getId')->willReturn($id);
		return $node;
	}

	/** @param array<string,?string> $meta */
	private function expectRead(array $meta): void {
		$this->metadata->method('read')->willReturn(new ManagedFile(
			(string)($meta['n8n_id'] ?? 'w1'),
			(string)($meta['n8n_mode'] ?? ''),
			(string)($meta['n8n_versionId'] ?? ''),
			(string)($meta['n8n_syncedHash'] ?? ''),
			(string)($meta['n8n_mapping'] ?? ''),
		));
	}

	private function fire(INode $node): bool {
		$data = 'whatever';
		$modified = false;
		return $this->plugin->beforeWriteContent('files/flow.n8n', $node, $data, $modified);
	}

	public function testBlocksOverwritingALinkFile(): void {
		$this->expectRead(['n8n_mode' => Mapping::MODE_LINK]);
		$this->notifier->expects(self::once())->method('linkEditBlocked')->with('kelly', 7, 'flow.n8n');

		$this->expectException(Forbidden::class);
		$this->fire($this->davFile());
	}

	public function testAllowsOverwritingASyncFile(): void {
		$this->expectRead(['n8n_mode' => Mapping::MODE_SYNC]);
		$this->notifier->expects(self::never())->method('linkEditBlocked');

		self::assertTrue($this->fire($this->davFile()));
	}

	public function testAllowsOverwritingUnmappedFiles(): void {
		$this->notifier->expects(self::never())->method('linkEditBlocked');

		foreach ([WorkflowMetadata::MODE_UNMAPPED] as $mode) {
			$metadata = $this->createMock(WorkflowMetadata::class);
			$metadata->method('read')->willReturn(new ManagedFile('w1', $mode, '', '', ''));
			$plugin = new LinkWriteGuardPlugin($metadata, $this->notifier, $this->userSession, new NullLogger());
			$data = 'x';
			$modified = false;
			self::assertTrue($plugin->beforeWriteContent('files/flow.n8n', $this->davFile(), $data, $modified));
		}
	}

	public function testIgnoresNonWorkflowFiles(): void {
		$this->metadata->expects(self::never())->method('read');
		$this->notifier->expects(self::never())->method('linkEditBlocked');

		self::assertTrue($this->fire($this->davFile('notes.txt')));
	}

	public function testIgnoresNonFileNodes(): void {
		$this->metadata->expects(self::never())->method('read');

		$node = $this->createMock(INode::class);
		$node->method('getName')->willReturn('flow.n8n');
		self::assertTrue($this->fire($node));
	}

	public function testFailsOpenForAnUnmanagedFile(): void {
		$this->metadata->method('read')->willReturn(null); // no record — not ours
		$this->notifier->expects(self::never())->method('linkEditBlocked');

		self::assertTrue($this->fire($this->davFile()));
	}

	public function testFailsOpenWhenMetadataReadThrows(): void {
		$this->metadata->method('read')->willThrowException(new \RuntimeException('db down'));
		$this->notifier->expects(self::never())->method('linkEditBlocked');

		self::assertTrue($this->fire($this->davFile()));
	}
}
