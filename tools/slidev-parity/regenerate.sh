#!/usr/bin/env bash
#
# Re-record tests/fixtures/slidev/expected.json by running Slidev's own parser.
#
# The parity test checks this library's splitter against decks that Slidev really
# split, so this is how the recording is made rather than how it is trusted: point
# SLIDEV_COMMIT at a commit of slidevjs/slidev, run this, and read the diff. A
# deliberate divergence is then added to divergences.json with the reason, or the
# port is changed to match.
#
# Needs Node 22.6 or newer, for running the TypeScript source as it is written.

set -euo pipefail

cd "$(dirname "$0")"

SLIDEV_COMMIT="${SLIDEV_COMMIT:-11fe3f79e45c26e22658e67f52e5cd4222aa41bf}"
SOURCE="https://raw.githubusercontent.com/slidevjs/slidev/${SLIDEV_COMMIT}/packages/parser/src/core.ts"
FIXTURE="$(cd ../.. && pwd)/tests/fixtures/slidev/expected.json"

[ -d node_modules/yaml ] || npm install --silent --no-save yaml

curl --fail --silent --show-error --location "$SOURCE" --output core.ts

# `core.ts` imports two things that do not resolve outside the monorepo: a type-only
# import, and `ensurePrefix` for `stringify`, which no part of the splitting touches.
# Both are removed so that everything `parseSync` uses is the file as published.
sed -e "/^import .*@slidev\/types/d" \
    -e "/^import .*@antfu\/utils/d" \
    -e "/^export \* from/d" \
    -e "s/^const RE_FRONTMATTER/const ensurePrefix = (prefix, str) => (str.startsWith(prefix) ? str : prefix + str)\nconst RE_FRONTMATTER/" \
    core.ts > core.runnable.ts

node --experimental-strip-types record.mjs "$FIXTURE"

rm -f core.ts core.runnable.ts

echo "Recorded Slidev ${SLIDEV_COMMIT} into ${FIXTURE}."