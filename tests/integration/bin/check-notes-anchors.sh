#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Kelly Ferrone
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Every `# notes: AGENTS.md#anchor` breadcrumb in a feature file must resolve to a
# real heading in features/AGENTS.md.
#
# WHY THIS EXISTS. The feature files carry no prose; each scenario ends with a
# one-line pointer to the section of AGENTS.md that explains it. That split only
# works while the pointers land — and they rot silently. Rename a scenario and the
# anchor (its slugified title) stops matching, with nothing to notice: the feature
# still parses, Behat still runs it, CI still passes, and the reasoning behind the
# scenario is simply unreachable. It is the same class of failure as a trailing
# `JSON` — invisible in review, paid for by whoever reads the file next.
#
# It has already happened twice: once from a scenario rename, once from a
# breadcrumb written for a section that was never actually added.
#
# The anchor rule is GitHub's: lowercase, spaces to hyphens, drop anything that is
# not a letter, digit, hyphen or space. Any heading level counts.

set -euo pipefail

cd "$(dirname "$0")/../../.."

notes="features/AGENTS.md"
[ -f "$notes" ] || { echo "✘ $notes not found"; exit 1; }

# Every heading in AGENTS.md, slugified the way GitHub does it.
anchors="$(
	grep -E '^#{1,6} ' "$notes" \
		| sed -E 's/^#{1,6} +//' \
		| tr '[:upper:]' '[:lower:]' \
		| sed -E 's/[^a-z0-9 -]//g; s/ +/-/g'
)"

fail=0
checked=0

while IFS=: read -r file line anchor; do
	checked=$((checked + 1))
	if ! grep -qxF "$anchor" <<<"$anchors"; then
		if [ "$fail" -eq 0 ]; then
			echo "✘ BROKEN notes: breadcrumbs — these point at sections of $notes that"
			echo "  do not exist, so the reasoning behind the scenario is unreachable:"
			fail=1
		fi
		echo "    $file:$line -> #$anchor"
	fi
done < <(
	grep -Hn '# *notes: *AGENTS\.md#' features/*.feature 2>/dev/null \
		| sed -E 's/^([^:]+):([0-9]+):.*AGENTS\.md#([A-Za-z0-9_-]+).*$/\1:\2:\3/' \
		|| true
)

if [ "$fail" -ne 0 ]; then
	echo
	echo "  Either fix the anchor (it is the scenario title, lowercased, spaces to"
	echo "  hyphens, punctuation dropped) or add the missing section to $notes."
	exit 1
fi

echo "✓ notes breadcrumbs: $checked pointers, all resolving in $notes"
