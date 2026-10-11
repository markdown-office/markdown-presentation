#!/usr/bin/env bash
#
# Run the suite against a reverted change, to prove the tests for it fail without it.
#
# Usage: tools/revert-check.sh <file> <line-to-delete>
#
# The line is removed by line number rather than by text, because a pattern full of `$` and
# `->` is quoting trouble in every shell that ever had to do this. Copies the file, runs
# pest, restores.
#
# Exits 0 when the suite FAILS — a change whose tests still pass is the thing being looked
# for, so "passes" here is a red build on purpose.

set -uo pipefail

cd "$(dirname "$0")/.."

file="$1"
line="$2"

if [ ! -f "$file" ]; then
  echo "No such file: $file"
  exit 2
fi

total="$(wc -l < "$file" | tr -d ' ')"

if [ "$line" -gt "$total" ]; then
  echo "Line $line is past the end of $file, which has $total lines"
  exit 2
fi

removed="$(sed -n "${line}p" "$file")"

if [ -z "$removed" ]; then
  echo "Line $line of $file is empty, so there is no change to revert"
  exit 2
fi

cp "$file" "$file.orig"

restore() { mv "$file.orig" "$file"; }
trap restore EXIT

sed -i '' "${line}d" "$file"

echo "Reverted line $line: ${removed}"

# The summary line rather than the per-test failures: a revert that makes every test error
# reports no "FAILED" lines at all, and only the summary still says so.
summary="$(vendor/bin/pest 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | grep -E 'Tests:' | tail -1)"

echo "$summary"

if echo "$summary" | grep -qE '[0-9]+ failed'; then
  echo "BITES: the suite fails without this line"
  exit 0
fi

echo "DID NOT BITE: the suite passes with this line removed"
exit 1
