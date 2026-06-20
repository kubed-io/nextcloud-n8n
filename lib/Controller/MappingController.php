<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Controller;

use OCA\N8nSync\Service\Mapping;
use OCA\N8nSync\Service\MappingService;
use OCA\N8nSync\Service\SyncService;
use OCA\N8nSync\Service\SyncStatusService;
use OCA\N8nSync\Settings\MappingSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST CRUD for folder mappings. Admin-gated via the framework attribute.
 *
 * Routes (see appinfo/routes.php):
 *   GET    /apps/n8n_sync/mappings           → list
 *   POST   /apps/n8n_sync/mappings           → add   { n8n_tag, team_folder, nc_groups, mode, writeback? }
 *   PUT    /apps/n8n_sync/mappings/{id}      → update
 *   DELETE /apps/n8n_sync/mappings/{id}      → delete; ?purge=1 also deletes the
 *                                              integration's managed files (those
 *                                              with n8n_id metadata). The Team
 *                                              Folder + foreign files are always
 *                                              kept (spec UC-4).
 */
final class MappingController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private MappingService $service,
		private SyncService $sync,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse([
			'mappings' => array_map(fn (Mapping $m) => $m->toArray(), $this->service->list()),
		]);
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function create(): JSONResponse {
		try {
			$mapping = Mapping::fromArray($this->request->getParams());
			$saved = $this->service->add($mapping);
			return new JSONResponse(['mapping' => $saved->toArray()], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function update(string $id): JSONResponse {
		try {
			$mapping = Mapping::fromArray($this->request->getParams() + ['id' => $id]);
			$saved = $this->service->update($id, $mapping);
			return new JSONResponse(['mapping' => $saved->toArray()]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Pull just this mapping from n8n (granular alternative to the bulk button).
	 * Synchronous for now — matches the bulk pull; an async job is the §16.1
	 * follow-up.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function sync(string $id): JSONResponse {
		try {
			// Per-mapping is synchronous + scoped to one mapping (§14.2): fast
			// feedback on a small, bounded set.
			$result = $this->sync->dispatch(SyncStatusService::DIR_PULL, $id, false);
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse($result);
	}

	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function destroy(string $id): JSONResponse {
		$purge = filter_var($this->request->getParam('purge', false), FILTER_VALIDATE_BOOLEAN);
		$purged = 0;
		try {
			if ($purge) {
				foreach ($this->service->list() as $m) {
					if ($m->id === $id) {
						$purged = $this->sync->purgeManagedFiles($m);
						break;
					}
				}
			}
			$this->service->delete($id);
			return new JSONResponse(['status' => 'ok', 'purged' => $purged]);
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
