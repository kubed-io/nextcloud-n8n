<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Controller;

use OCA\N8nSync\Exception\ExistingWorkflowsException;
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
 *   POST   /apps/n8n_sync/mappings           → add { n8n_tag, team_folder, mode,
 *                                              use_team_folder, nc_groups }
 *   PUT    /apps/n8n_sync/mappings/{id}      → re-share { nc_groups } — the ONLY
 *                                              edit a mapping has. Everything
 *                                              else is fixed at create.
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

	/**
	 * The configured mappings — described, not just stored: each carries the
	 * groups its FOLDER is currently shared with, read as this responds.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse([
			'mappings' => array_map(
				fn (Mapping $m): array => $this->service->describe($m),
				$this->service->list(),
			),
		]);
	}

	/**
	 * Map an n8n tag.
	 *
	 * `nc_groups` is passed alongside the mapping rather than into it: groups are
	 * applied to the provisioned folder and read back from it, never stored.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function create(): JSONResponse {
		try {
			$params = $this->request->getParams();
			$mapping = Mapping::fromArray($params);
			// `purge_workflows` IS NOT PART OF THE MAPPING, so it is read off the request
			// rather than through `Mapping::fromArray()` — it is the admin's answer to a
			// question, not a field a mapping stores. It defaults to false, which is the
			// safety: the destructive path cannot be reached by a caller that does not
			// know about it.
			$purge = filter_var($params['purge_workflows'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$saved = $this->service->add($mapping, $params['nc_groups'] ?? [], $purge);
			return new JSONResponse(['mapping' => $this->service->describe($saved)], Http::STATUS_CREATED);
		} catch (ExistingWorkflowsException $e) {
			// THE COUNT TRAVELS AS A NUMBER. The panel turns this refusal into a
			// confirmation and re-submits with `purge_workflows`, so it needs the figure
			// to put in the warning — parsing it back out of a sentence would break the
			// first time that sentence is reworded. Caught before the
			// `InvalidArgumentException` arm below, which it extends.
			//
			// 422, NOT 400: the request is well-formed and the mapping is valid. What is
			// unprocessable is the folder's current contents, and the admin can change
			// that answer — which is exactly what the panel offers next.
			return new JSONResponse(
				['message' => $e->getMessage(), 'workflows' => $e->workflows, 'folder' => $e->folder],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			// The request was fine and the mapping is fine — the FOLDER could not be
			// provisioned (a Team Folder on an instance without groupfolders). A 400
			// would send the admin back to change an input that was never wrong.
			return new JSONResponse(
				['message' => 'Could not provision the mapped folder: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

	/**
	 * Re-share a mapping's folder with the given groups — THE ONLY EDIT THERE IS.
	 *
	 * Everything else about a mapping is immutable once created: the tag, the
	 * folder, the storage backend and the mode. That is not enforced here by a
	 * list of guards — it is enforced by this method taking GROUPS, so no caller
	 * can express a change to anything else. There is no path to check.
	 *
	 * Changing any of the rest means removing the mapping and adding it again,
	 * which makes the migration cost visible instead of hiding it behind a
	 * dropdown: re-pointing the tag silently re-decides which workflows the
	 * mapping owns, and re-pointing the folder orphans everything already
	 * mirrored into the old one.
	 *
	 * It writes to the FOLDER and stores nothing, so the response carries the
	 * groups the folder reports afterwards — which is not always what was
	 * submitted, since a group that does not exist cannot be shared with.
	 */
	#[AuthorizedAdminSetting(settings: MappingSettings::class)]
	public function update(string $id): JSONResponse {
		try {
			$this->service->updateGroups($id, $this->request->getParam('nc_groups', []));
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				['message' => 'Could not re-share the mapped folder: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		$mapping = $this->service->getById($id);

		return new JSONResponse(['mapping' => $mapping === null ? null : $this->service->describe($mapping)]);
	}

	/**
	 * Pull just this mapping from n8n (granular alternative to the bulk button).
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
				$m = $this->service->getById($id);
				if ($m !== null) {
					$purged = $this->sync->purgeManagedFiles($m);
				}
			}
			$this->service->delete($id);
			return new JSONResponse(['status' => 'ok', 'purged' => $purged]);
		} catch (\OutOfBoundsException) {
			return new JSONResponse(['message' => 'Mapping not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
