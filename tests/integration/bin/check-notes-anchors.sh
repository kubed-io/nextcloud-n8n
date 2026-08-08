#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 kubed-io
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
# It has already happened twice in the n8n sibling this is ported from: once from
# a scenario rename, once from a breadcrumb written for a section never added.
#
# ## AND THE BUDGET, WHICH IS THE OTHER HALF OF THE SAME RULE
#
# The split only pays for itself while the feature files stay THIN. Prose that
# creeps back into a scenario is worse than prose in AGENTS.md, because a reader
# scanning for the specification has to step over it — and it drifts from the
# section that was supposed to own it. So a comment block above two lines of prose
# is a failure here, and its content belongs in AGENTS.md behind a breadcrumb.
#
# Dividers, `@blocked`-style status reasons and the breadcrumbs themselves are not
# prose and do not count.
#
# The anchor rule is GitHub's: lowercase, spaces to hyphens, drop anything that is
# not a letter, digit, hyphen or space. Any heading level counts.
#
# BOTH KINDS OF POINTER ARE CHECKED: the per-scenario `# notes:` breadcrumb AND
# the `# Notes, decisions and history…` header on line 1. Only the first was
# checked once, and the second rotted silently in exactly the way this file warns
# about — the noun/verb restructure renamed every section to a PATH
# (`## designs/create`), whose slug drops the slash (`designscreate`), and 22 of
# 29 headers kept pointing at their old names. Every one of them passed.

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
	grep -Hrn --include='*.feature' -e '# *notes: *\(\.\./\)\?AGENTS\.md#' \
		-e '^# Notes.*AGENTS\.md#' features 2>/dev/null \
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

# ── the comment budget ───────────────────────────────────────────────────────
python3 - <<'PYEOF'
import pathlib, re, sys

LIMIT = 2
def divider(s): return '\u2500\u2500\u2500' in s or '\u2550\u2550\u2550' in s
def bread(s):   return s.startswith('# notes:')
def status(s):  return re.match(r'^#\s*@(blocked|todo|unbuilt|decision)\b', s) is not None

bad = []
for f in sorted(pathlib.Path('features').rglob('*.feature')):
    lines = f.read_text().splitlines()
    i = 0
    while i < len(lines):
        if not lines[i].strip().startswith('#'):
            i += 1
            continue
        start, block = i, []
        while i < len(lines) and lines[i].strip().startswith('#'):
            block.append(lines[i].strip())
            i += 1
        prose = [b for b in block if not (bread(b) or divider(b) or status(b))]
        if len(prose) > LIMIT:
            bad.append((f.relative_to('features'), start + 1, len(prose)))

if bad:
    print(f'\u2718 COMMENT BUDGET \u2014 a block may carry at most {LIMIT} lines of prose;')
    print('  anything longer belongs in features/AGENTS.md behind a "# notes:" breadcrumb:')
    for name, line, n in bad:
        print(f'    {name}:{line}  ({n} lines)')
    sys.exit(1)

print(f'\u2713 comment budget: no block over {LIMIT} lines of prose')
PYEOF
