#!/usr/bin/env bash
#
# THE SUITES MUST BE A PARTITION: every features/*.feature belongs to exactly one
# behat suite — no gaps, no overlaps.
#
# This exists because both failure modes are SILENT.
#
#   a file in NO suite      never runs. Once the workflow selects a suite per
#                           matrix leg, the scenarios simply vanish from the run
#                           and every leg still reports green. A suite that
#                           quietly skips tests is worse than no suite at all.
#   a file in TWO suites    runs twice, in two legs, against two n8n containers.
#                           Wasted minutes, and doubled odds of a flake.
#
# Neither shows up as a failure anywhere else, which is the whole argument for a
# text check that takes a second instead of a live Nextcloud and a live n8n.
#
# Runs in the quality workflow, not the integration one — it needs no services,
# and a partition error should fail in seconds rather than after a stack boot.
#
# Ported from kubed-io/nextcloud-penpot, where the partition landed first.
set -euo pipefail

cd "$(dirname "$0")/../../.."
config=tests/integration/behat.dist.yml

on_disk=$(find features -name '*.feature' -printf '%P\n' | sort)
in_suites=$(grep -oE "features/[A-Za-z0-9._/-]+\.feature'" "$config" | sed "s|features/||; s|'||" | sort)

missing=$(comm -23 <(echo "$on_disk") <(echo "$in_suites" | uniq))
duplicated=$(echo "$in_suites" | uniq -d)
ghost=$(comm -13 <(echo "$on_disk") <(echo "$in_suites" | uniq))

fail=0
if [ -n "$missing" ]; then
  fail=1
  echo "::error::feature files in NO behat suite — these would never run:"
  echo "$missing" | sed 's/^/  /'
fi
if [ -n "$duplicated" ]; then
  fail=1
  echo "::error::feature files in MORE THAN ONE behat suite — these would run twice:"
  echo "$duplicated" | sed 's/^/  /'
fi
if [ -n "$ghost" ]; then
  fail=1
  echo "::error::suites name feature files that do not exist:"
  echo "$ghost" | sed 's/^/  /'
fi

if [ "$fail" -ne 0 ]; then
  echo
  echo "Fix tests/integration/behat.dist.yml so the four suites partition features/."
  exit 1
fi

# ── the status filter must not drift between the config and the workflow ─────
# A CLI `--tags` REPLACES behat.dist.yml's gherkin filter rather than adding to
# it. Today the workflow passes no --tags at all, so the config filter is the
# only one and there is nothing to drift. The moment a leg needs to APPEND a skip
# (the storage-backend axis will), it has to repeat the status list on the
# command line — and then two copies of one fact exist. Silent failure mode if
# they diverge: a leg quietly starts running @todo scenarios, or quietly stops
# running real ones.
#
# So this only fires once the workflow actually passes --tags, and from that
# moment on it pins the two together.
#
# Comments are stripped first: this file's own prose explains why a CLI `--tags`
# replaces the config filter, and a check that reads its own explanation as an
# invocation fails on the sentence describing it.
workflow=.github/workflows/integration.yml
config_tags=$(grep -oE "tags: '[^']+'" "$config" | head -1 | sed "s/tags: '//; s/'//")
workflow_code=$(sed 's/#.*$//' "$workflow")
if echo "$workflow_code" | grep -q -- '--tags' && ! echo "$workflow_code" | grep -q -- "$config_tags"; then
  echo "::error::the status tag filter has drifted."
  echo "  behat.dist.yml : $config_tags"
  echo "  $workflow passes --tags but does not contain that exact expression."
  exit 1
fi

echo "ok: $(echo "$on_disk" | wc -l) feature files, each in exactly one suite"
