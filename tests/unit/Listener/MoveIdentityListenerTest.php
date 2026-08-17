<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Listener;

use OCA\N8nSync\Listener\MoveIdentityListener;
use OCA\N8nSync\Service\ManagedFile;
use OCA\N8nSync\Service\MoveIdentityStore;
use OCA\N8nSync\Service\SyncGuard;
use OCA\N8nSync\Service\WorkflowMetadata;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see MoveIdentityListener} — the bracket that carries a workflow
 * file's stamp across a move Nextcloud does not.
 *
 * THE BEHAVIOUR UNDER TEST IS A LOSS THAT IS INVISIBLE FROM THE APP. A move between
 * two storages (two Team Folders, or a Team Folder and a home folder) is a copy plus
 * an unlink, and the unlink takes the file's `files_metadata` row with it. Nothing in
 * the event says so; the file simply arrives looking untracked, which is the one shape
 * create-on-land adopts. So the tests are written around what the listener sees — a
 * stashed stamp and an empty read — rather than around any storage arrangement, which
 * a unit test cannot build.
 *
 * The real store is used rather than a double: it is six lines of array, and the pairing
 * of `keep`/`take` across two events is precisely what these tests are checking.
 */
#[CoversClass(MoveIdentityListener::class)]
final class MoveIdentityListenerTest extends TestCase {
	private WorkflowMetadata $metadata;
	private MoveIdentityStore $store;
	private MoveIdentityListener $listener;

	protected function setUp(): void {
		$this->metadata = $this->createMock(WorkflowMetadata::class);
		$this->store = new MoveIdentityStore();

		$guard = $this->createStub(SyncGuard::class);
		$guard->method('run')->willReturnCallback(fn (callable $fn) => $fn());

		$this->listener = new MoveIdentityListener($this->metadata, $this->store, $guard, new NullLogger());
	}

	/** The stamp a managed sync file carries, in the shape `write()` takes back. */
	private const STAMP = [
		WorkflowMetadata::KEY_ID => 'wf-1',
		WorkflowMetadata::KEY_MODE => 'sync',
		WorkflowMetadata::KEY_MAPPING => 'map-src',
	];

	private function node(string $path, int $id): Node {
		$node = $this->createStub(File::class);
		$node->method('getPath')->willReturn($path);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn(basename($path));
		return $node;
	}

	/**
	 * THE EVENTS ARE DOUBLED, NOT BUILT. The unit suite runs against `nextcloud/ocp`,
	 * whose concrete classes are declaration-only — constructing one really would store
	 * nothing and hand back nulls from `getSource()`. A double answers the two getters
	 * the listener uses and still satisfies its `instanceof` checks.
	 */
	private function before(string $from, string $to, int $id): BeforeNodeRenamedEvent {
		$event = $this->createStub(BeforeNodeRenamedEvent::class);
		$event->method('getSource')->willReturn($this->node($from, $id));
		$event->method('getTarget')->willReturn($this->node($to, $id));
		return $event;
	}

	private function after(string $from, string $to, int $id): NodeRenamedEvent {
		$event = $this->createStub(NodeRenamedEvent::class);
		$event->method('getSource')->willReturn($this->node($from, $id));
		$event->method('getTarget')->willReturn($this->node($to, $id));
		return $event;
	}

	/** No id in the metadata means the row was destroyed by the move. */
	private function managed(string $id): ManagedFile {
		return new ManagedFile($id, $id === '' ? '' : 'sync', '', '', '', '');
	}

	/**
	 * THE WHOLE BUG, IN ONE TEST. The file arrives with nothing on it, and the stamp
	 * read before the move goes back on — so the listeners behind this one still see a
	 * managed file and rebind it, instead of create-on-land minting a second workflow
	 * for a mirror that already had one.
	 */
	public function testRestoresTheStampWhenTheMoveDestroyedIt(): void {
		$this->metadata->method('readRaw')->with(7)->willReturn(self::STAMP);
		$this->metadata->method('read')->with(7)->willReturn($this->managed(''));
		$this->metadata->expects(self::once())->method('write')->with(7, self::STAMP);

		$this->listener->handle($this->before('/kelly/files/Src/Foo.n8n', '/kelly/files/Dst/Foo.n8n', 7));
		$this->listener->handle($this->after('/kelly/files/Src/Foo.n8n', '/kelly/files/Dst/Foo.n8n', 7));
	}

	/**
	 * A same-storage move keeps the row, and the LIVE one is the truth. The stash is a
	 * photograph of the past — stamping it back over a row another listener may have
	 * just changed would undo that change, for no gain.
	 */
	public function testLeavesASurvivingStampAlone(): void {
		$this->metadata->method('readRaw')->with(7)->willReturn(self::STAMP);
		$this->metadata->method('read')->with(7)->willReturn($this->managed('wf-1'));
		$this->metadata->expects(self::never())->method('write');

		$this->listener->handle($this->before('/kelly/files/Src/Foo.n8n', '/kelly/files/Src/Bar.n8n', 7));
		$this->listener->handle($this->after('/kelly/files/Src/Foo.n8n', '/kelly/files/Src/Bar.n8n', 7));
	}

	/**
	 * An untracked `.n8n` moved into a mapping is create-on-land's, and it must stay
	 * that way: restoring an empty stamp over it would be a no-op at best, and claiming
	 * the file at worst.
	 */
	public function testDoesNotInventAStampForAFileThatNeverHadOne(): void {
		$this->metadata->method('readRaw')->with(7)->willReturn([]);
		$this->metadata->method('read')->with(7)->willReturn($this->managed(''));
		$this->metadata->expects(self::never())->method('write');

		$this->listener->handle($this->before('/kelly/files/Scratch/Foo.n8n', '/kelly/files/Dst/Foo.n8n', 7));
		$this->listener->handle($this->after('/kelly/files/Scratch/Foo.n8n', '/kelly/files/Dst/Foo.n8n', 7));
	}

	/**
	 * A move this listener never bracketed gets nothing — the restore is keyed on the
	 * source path it stashed against, so a second, unrelated move in the same request
	 * cannot pick up the first one's stamp.
	 */
	public function testARestoreWithoutACaptureDoesNothing(): void {
		$this->metadata->expects(self::never())->method('write');

		$this->listener->handle($this->after('/kelly/files/Other/Baz.n8n', '/kelly/files/Dst/Baz.n8n', 9));
	}

	/** A stash is good for one move: taking it twice must not re-stamp a later file. */
	public function testTheStashIsConsumed(): void {
		$this->metadata->method('readRaw')->willReturn(self::STAMP);
		$this->metadata->method('read')->willReturn($this->managed(''));
		$this->metadata->expects(self::once())->method('write');

		$this->listener->handle($this->before('/kelly/files/Src/Foo.n8n', '/kelly/files/Dst/Foo.n8n', 7));
		$this->listener->handle($this->after('/kelly/files/Src/Foo.n8n', '/kelly/files/Dst/Foo.n8n', 7));
		$this->listener->handle($this->after('/kelly/files/Src/Foo.n8n', '/kelly/files/Elsewhere/Foo.n8n', 7));
	}

	/** Only `*.n8n` files are ours; anything else moves without us noticing. */
	public function testIgnoresFilesThatAreNotWorkflows(): void {
		$this->metadata->expects(self::never())->method('readRaw');
		$this->metadata->expects(self::never())->method('write');

		$this->listener->handle($this->before('/kelly/files/Src/notes.txt', '/kelly/files/Dst/notes.txt', 7));
		$this->listener->handle($this->after('/kelly/files/Src/notes.txt', '/kelly/files/Dst/notes.txt', 7));
	}

	/** An event this listener does not handle is not an error. */
	public function testIgnoresUnrelatedEvents(): void {
		$this->metadata->expects(self::never())->method('write');

		$this->listener->handle($this->createStub(Event::class));
	}
}
