<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Unit\Service;

use OCA\N8nSync\Service\TrashControl;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * {@see TrashControl} — every conversation this app has with the Nextcloud trash, and
 * the only place it makes a delete unrecoverable.
 *
 * The pause is PROCESS-WIDE while it is held, so the failure that matters is not "the
 * link was trashed anyway" (visible, annoying) but "the trash stayed paused", which is
 * invisible and silently makes every later delete on the request permanent — including
 * the user's own, on files this app has never heard of.
 *
 * `files_trashbin` is not installed in the unit suite, so `ITrashManager` does not
 * exist and the no-trash-app path is the one exercised by default. The paired paths are
 * covered with a hand-rolled double registered under that name.
 */
#[CoversClass(TrashControl::class)]
final class TrashControlTest extends TestCase {
	public function testTheCallbackRunsAndItsValueComesBack(): void {
		$control = new TrashControl(
			$this->createStub(ContainerInterface::class),
			$this->createStub(IUserManager::class),
			new NullLogger(),
		);

		self::assertSame('done', $control->withoutTrash(static fn (): string => 'done'));
	}

	/**
	 * NO TRASH APP IS NOT AN ERROR. `files_trashbin` ships with Nextcloud but can be
	 * removed, and on an instance without it `delete()` is already permanent — so there
	 * is nothing to pause and the delete must still happen. Failing here instead would
	 * make a link mirror un-prunable on exactly the instances where the prune is simplest.
	 */
	public function testWithNoTrashAppTheDeleteStillHappens(): void {
		$ran = false;
		$container = $this->createMock(ContainerInterface::class);
		// Never consulted: the interface does not exist, so there is nothing to resolve.
		$container->expects(self::never())->method('get');

		$control = new TrashControl($container, $this->createStub(IUserManager::class), new NullLogger());
		$control->withoutTrash(function () use (&$ran): void {
			$ran = true;
		});

		self::assertTrue($ran, 'the delete was skipped because there was no trash to pause');
	}

	/**
	 * A CONTAINER THAT THROWS IS THE SAME AS NO TRASH. `get()` raising (a broken app, a
	 * half-disabled trashbin) must not take the prune down with it — the delete is still
	 * the right thing to do, and it is permanent anyway if the trash is not there.
	 *
	 * Only reachable when `ITrashManager` exists, so it is skipped in the standalone
	 * unit suite rather than faked: a fake would prove the double behaves, not the class.
	 */
	public function testAContainerFailureDoesNotStopTheDelete(): void {
		if (!interface_exists('OCA\\Files_Trashbin\\Trash\\ITrashManager')) {
			self::markTestSkipped('files_trashbin is not present in the unit suite');
		}

		$ran = false;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no such service'));

		$control = new TrashControl($container, $this->createStub(IUserManager::class), new NullLogger());
		$control->withoutTrash(function () use (&$ran): void {
			$ran = true;
		});

		self::assertTrue($ran);
	}

	/**
	 * NOTHING TO LIST IS NOT AN ERROR EITHER, and here the stakes run the other way
	 * from the pause: {@see TrashReconcileService} destroys what this method returns,
	 * so the failure to avoid is inventing entries, not missing them. An instance with
	 * no trash app cannot be holding a trashed mirror, and a uid that resolves to
	 * nobody has no trash to read — both answer with an empty list rather than
	 * throwing into the pull that asked.
	 */
	public function testWithNoTrashAppThereIsNothingInTheTrash(): void {
		$container = $this->createMock(ContainerInterface::class);
		// Never consulted: the interface does not exist, so there is nothing to resolve.
		$container->expects(self::never())->method('get');

		$control = new TrashControl($container, $this->createStub(IUserManager::class), new NullLogger());

		self::assertSame([], $control->listTrashed('alice'));
	}

	public function testAnUnknownUserHasNoTrash(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);

		$control = new TrashControl($this->createStub(ContainerInterface::class), $users, new NullLogger());

		self::assertSame([], $control->listTrashed('nobody'));
	}
}
