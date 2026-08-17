<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Exception;

/**
 * A failed n8n REST call, normalised so callers get n8n's *own*
 * complaint verbatim instead of a multi-line Guzzle dump.
 *
 * The message is the human-readable reason n8n returned (e.g.
 * "request/body/connections must be object") — short enough to drop straight
 * into a toast/notification so a user can fix their workflow JSON. `httpStatus`
 * is the response code (0 for transport errors with no response).
 */
final class N8nApiException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $httpStatus = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
