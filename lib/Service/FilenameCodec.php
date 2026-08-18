<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Service;

use OCP\Files\File;
use OCP\Files\Node;

/**
 * Filename codec for n8n workflow files.
 *
 * Two on-disk shapes are supported:
 *
 *   1. Clean (default)         <name>.n8n
 *   2. Id-suffixed (opt-in)    <name>.<id>.n8n
 *
 * The clean shape is the only one the app writes today — every production
 * caller of {@see format} passes `$idInFilename = false`, and no config flag
 * selects the other shape. The id-suffixed shape is still *parsed* because
 * files written by older builds carry it, and kept in {@see format} as the
 * forward-compat affordance for environments where the Files Metadata API
 * exposure over WebDAV ever regresses.
 *
 * Both shapes carry exactly the same metadata server-side via the Files
 * Metadata API — the filename is *only* a redundant carrier. See plan
 * §5-D' "id resolution layers".
 *
 * ## ONE SEGMENT, BECAUSE NEXTCLOUD ONLY EVER READS ONE
 *
 * This was `.n8n.json` for the app's first four chapters, on the reasoning that the
 * real `.json` tail made the file open in a JSON editor off-Nextcloud. It cost more
 * than it bought, and the bill came due on `copy`:
 *
 *   - `IMimeTypeDetector::detectPath()` takes the LAST extension and nothing else
 *     (`strrchr`, verified in core). `Name.n8n.json` is `application/json` to
 *     Nextcloud, forever — so every file we wrote landed with the wrong mimetype and
 *     had to be corrected afterwards by a table-wide UPDATE. Measured on a live
 *     instance: a sequential scan of 20,144 filecache rows, ~26ms, to find the rows
 *     it then rewrote. An entire listener existed for nothing else.
 *   - Nextcloud's collision counter goes before the LAST extension, so a copy landing
 *     beside its source was named `Name.n8n (1).json` — a name that ends in `.json`,
 *     matches none of our predicates, and made the copy invisible to the app.
 *     Confirmed live as `FooBoblicious.n8n (1).json`.
 *
 * With a single segment both problems stop existing rather than getting handled:
 * `Name.n8n` is detected as `application/n8n+json` by core's own detector, and a
 * colliding copy is born `Name (1).n8n` — already our spelling, because it is
 * Nextcloud's. The counter sits immediately before the extension, and {@see format()}
 * puts it in exactly the same place, so the two conventions are one convention.
 *
 * The `.json` tail is not free to give up: off-Nextcloud, a `.n8n` file needs a
 * one-time editor association to open as JSON. That is a per-machine setting made
 * once, weighed against a mimetype correction on every save and a copy the app could
 * not see. The Grafana sibling made this cut first; this is the same cut.
 *
 * Collision policy: when two workflows in the same n8n tag share a `name`
 * (n8n permits this), the first file gets the plain name and the ones after it
 * get an NC-style ` (1)`, ` (2)`, ... suffix — Nextcloud's own counter, which
 * starts at one. The chosen filename is what gets stored in metadata, so
 * subsequent pulls are stable and won't oscillate.
 *
 * ## TWO NAMES, AND THE DIFFERENCE MATTERS
 *
 * {@see parse()} returns both, because callers want opposite things from a
 * suffixed file:
 *
 *   `name`     the LOGICAL name, counter stripped — `Fleet Health`. What a pull
 *              matches a workflow's name against, so a mirror already wearing
 *              `(1)` is recognised as the same logical file next time round.
 *   `display`  the name AS WRITTEN, counter and all — `Fleet Health (1)`. What
 *              the user sees, and therefore what the JSON `name` and the n8n
 *              workflow have to say whenever NEXTCLOUD named the file.
 *
 * Which is authoritative is decided by WHERE THE GESTURE HAPPENED, and a copy
 * exercises both directions:
 *
 *   - Copied in Nextcloud → Nextcloud picked the name, counter included, so
 *     `display` is the name and it propagates to the JSON and to n8n. All three
 *     agree.
 *   - Copied in n8n → n8n permits two workflows with one name and Nextcloud does
 *     not permit two files with one name, so the counter is added to the FILENAME
 *     ONLY and `name` is what still matches the workflow. This is the single
 *     exception to *a name is one value living in three places*.
 *
 * This class is **pure logic**: no filesystem access, no DI dependencies,
 * trivial to unit test.
 */
final class FilenameCodec {
	/** Trailing extension shared by both shapes. */
	public const EXT = '.n8n';

	/**
	 * True when $name is a managed n8n workflow filename (ends in {@see EXT}).
	 * Pure string test — the single source of truth for "is this one of ours?".
	 *
	 * The non-empty stem is required so this agrees with {@see parse()}, which rejects
	 * a bare `.n8n`. The two predicates must never disagree on "is this ours?", and
	 * under the old compound extension a bare `.n8n` slipped past this one.
	 */
	public static function isWorkflowName(string $name): bool {
		return strlen($name) > strlen(self::EXT) && str_ends_with($name, self::EXT);
	}

	/**
	 * True when $name is one of ours **as the trash renamed it**. Nextcloud appends
	 * a deletion timestamp when a file is moved to the trash —
	 * `Old Name.n8n.d1712345678` — so {@see isWorkflowName}'s `str_ends_with`
	 * is FALSE for every trashed workflow file.
	 *
	 * That is not a hypothetical: it is half of why the trash-purge step never ran
	 * (see {@see \OCA\N8nSync\Listener\TrashPurgeHook}). The integration harness had
	 * documented the `.dNNNN` suffix for a long time; production code had not.
	 *
	 * The timestamp is required, not optional — a bare `.n8n` is
	 * {@see isWorkflowName}'s job, and accepting both here would let a live file
	 * match a trash-only predicate.
	 */
	public static function isTrashedWorkflowName(string $name): bool {
		return (bool)preg_match('/' . preg_quote(self::EXT, '/') . '\.d\d+$/', $name);
	}

	/**
	 * True when $node is a managed n8n workflow file: a {@see File} whose name
	 * ends in {@see EXT}. The one predicate the listeners/services share instead
	 * of open-coding `$node instanceof File && str_ends_with(...)` everywhere.
	 *
	 * `@psalm-assert-if-true` narrows $node to File in the caller's true branch
	 * (and, via the negation, after a `if (!isWorkflowFile($node)) { return; }`
	 * guard) so callers keep the flow-narrowing the inline `instanceof` gave them.
	 *
	 * @psalm-assert-if-true File $node
	 */
	public static function isWorkflowFile(?Node $node): bool {
		return $node instanceof File && self::isWorkflowName($node->getName());
	}

	/**
	 * What an n8n workflow id looks like in practice (verified against the
	 * live instance: e.g. `0oOA4iz0T0GRmICc`, `-7AgWuz-iwnhC4dktuGxS`,
	 * `PQfdkurMHf6SdR4w`). Mixed case alphanumeric plus `-` and `_`, length
	 * roughly 16-21. The lower bound of 12 is deliberately lax — we want to
	 * recognise an id segment, not validate it; n8n is the source of truth.
	 *
	 * The character class explicitly excludes `.` so we can never confuse
	 * an id with a name fragment that happened to contain dots.
	 */
	private const ID_RE = '/^[A-Za-z0-9_-]{12,32}$/';

	/** A trailing Nextcloud collision counter, e.g. the ` (2)` of `Fleet Health (2)`. */
	private const COUNTER_RE = '/^(?<base>.+) \\((?<n>\\d+)\\)$/';

	/**
	 * Parse a basename (or full path; we ignore everything before the last
	 * slash) into its components. Returns null if the basename does not
	 * end in {@see EXT}.
	 *
	 * Both clean and id-suffixed shapes are recognised; the id field is
	 * `null` for the clean shape and a non-empty string for the suffixed
	 * shape.
	 *
	 * @return array{name:string, id:?string, suffix:int, display:string}|null
	 *                                                                         `suffix` is the collision counter (0 for the canonical name,
	 *                                                                         1+ for "(N)" duplicates); `display` is the name with that
	 *                                                                         counter still on it.
	 */
	public static function parse(string $basename): ?array {
		$slash = strrpos($basename, '/');
		if ($slash !== false) {
			$basename = substr($basename, $slash + 1);
		}
		if (!str_ends_with($basename, self::EXT)) {
			return null;
		}
		$stem = substr($basename, 0, -strlen(self::EXT));
		if ($stem === '') {
			return null;
		}

		// THE COUNTER COMES OFF FIRST, because it is the last thing on the stem — that
		// is where Nextcloud puts it and where {@see format()} puts it. Reading it
		// before the id keeps the id segment intact on a duplicated file:
		// `Board.0oOA4iz0T0GRmICc (1).n8n` is an id-suffixed `Board` wearing a counter,
		// not a file whose id is `0oOA4iz0T0GRmICc (1)`.
		$suffix = 0;
		$counter = '';
		if (preg_match(self::COUNTER_RE, $stem, $m) === 1) {
			$suffix = (int)$m['n'];
			$counter = ' (' . $m['n'] . ')';
			$stem = $m['base'];
		}

		// Then the id-suffixed shape: `<name>.<id>` where `<id>` matches ID_RE. Walk
		// from the rightmost dot so a name containing dots (e.g. "v1.2 thing") still
		// parses.
		$id = null;
		$name = $stem;
		$lastDot = strrpos($stem, '.');
		if ($lastDot !== false) {
			$candidate = substr($stem, $lastDot + 1);
			if (preg_match(self::ID_RE, $candidate)) {
				$id = $candidate;
				$name = substr($stem, 0, $lastDot);
			}
		}
		if ($name === '') {
			return null;
		}

		// `name` is the logical name, so later pulls detect they're updating the same
		// file; `display` is what the user sees. See this class's docblock for which one
		// a caller wants — taking the wrong one is what let a copy reach n8n under the
		// ORIGINAL's name.
		return ['name' => $name, 'id' => $id, 'suffix' => $suffix, 'display' => $name . $counter];
	}

	/**
	 * The name a managed file shows the user: its stem with any collision counter
	 * left on, and no id segment or extension. Empty string when $basename is not
	 * one of ours.
	 *
	 * THE ONE-LINER FOR "WHAT IS THIS FILE CALLED", so callers that want the visible
	 * name don't reach into {@see parse()} and take `name` — the counter-stripped
	 * field — by mistake.
	 */
	public static function displayName(string $basename): string {
		$parsed = self::parse($basename);
		return $parsed !== null ? trim($parsed['display']) : '';
	}

	/**
	 * Build a filename for a workflow.
	 *
	 * The counter goes LAST, immediately before the extension — the same place
	 * Nextcloud's own `getUniqueName()` puts it. That is the point of the single-segment
	 * extension: our spelling of a collision and Nextcloud's are the same spelling, so a
	 * copy the client names needs no correcting and a name we choose needs no defending.
	 *
	 * @param string $name Workflow display name from n8n.
	 * @param string $id Workflow id from n8n.
	 * @param bool $idInFilename If true, embed the id segment.
	 * @param int $collisionIndex 0 = canonical filename, 1+ adds "(N)".
	 */
	public static function format(string $name, string $id, bool $idInFilename, int $collisionIndex = 0): string {
		$safe = self::sanitiseName($name);
		if ($safe === '') {
			// Fall back to id so we never produce just ".n8n".
			$safe = $id;
		}
		$stem = $safe;
		if ($idInFilename) {
			$stem .= '.' . $id;
		}
		if ($collisionIndex > 0) {
			$stem .= ' (' . $collisionIndex . ')';
		}
		return $stem . self::EXT;
	}

	/**
	 * Replace characters that are unsafe in NC/WebDAV filenames with `_`.
	 * Keep this conservative — we'd rather have a slightly munged but
	 * predictable name than fight every locale's edge cases.
	 *
	 * Specifically banned by NC default: `\\ / : * ? " < > |` and control
	 * characters. We also collapse runs of whitespace so users don't end
	 * up with awkward "Foo   bar" filenames just because the n8n name had
	 * a tab in it.
	 */
	private static function sanitiseName(string $name): string {
		$n = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name) ?? '';
		$n = preg_replace('/[\\\\\\/:\\*\\?"<>\\|]/u', '_', $n) ?? '';
		$n = preg_replace('/\\s+/u', ' ', $n) ?? '';
		$n = trim($n);
		// Strip a leading dot so the file isn't hidden on POSIX storages.
		if ($n !== '' && $n[0] === '.') {
			$n = '_' . substr($n, 1);
		}
		return $n;
	}
}
