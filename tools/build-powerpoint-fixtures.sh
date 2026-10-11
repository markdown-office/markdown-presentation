#!/bin/bash
#
# Rebuilds the PowerPoint decks this directory carries.
#
#   tools/build-powerpoint-fixtures.sh [--keep] [deck ...]
#
# PowerPoint authors every one of them: this writes an empty stub for it to open, drives it
# through AppleScript, and takes the name it wrote back out. See README.md for what each deck is
# and why they are built rather than committed blind.
#
# Naming decks rebuilds only those. A deck PowerPoint writes is not byte-identical to the one it
# wrote last time — the timestamps in `docProps` move — so rebuilding all of them to change one
# leaves a diff in the other two that says nothing.
#
# Three things about PowerPoint on macOS are load-bearing here and are why this is a script and
# not three commands:
#
# - `open -a` is the only thing that grants PowerPoint's sandbox access to a path. Started with
#   `launch` from AppleScript, the application has no extension for the file and every `open`
#   after it fails with Office -9074, so the deck is opened by LaunchServices and everything
#   else is driven afterwards.
# - PowerPoint is staged inside its own container, because that is the one directory it is
#   always allowed to read and write.
# - PowerPoint answers no AppleEvent while a dialog is up, and a run that has stopped making
#   progress says nothing about why unless each step writes what it did. Hence the log, and
#   hence the watchdog.

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILDER="$HERE/tests/fixtures/powerpoint/build"
CONTAINER="$HOME/Library/Containers/com.microsoft.Powerpoint/Data/markdown-presentation-fixtures"
WORK="${TMPDIR:-/tmp}/markdown-presentation-fixtures"
PICTURE="$HERE/examples/markdown/assets/logo.png"

export PATH="/usr/bin:/bin:/usr/sbin:/sbin:$PATH"

chosen=()
for argument in "$@"; do
	[[ "$argument" == "--keep" ]] && continue
	chosen+=("$argument")
done

# A name that matches nothing rebuilds nothing and then reports success, which reads exactly like
# a run that did what it was asked. `want` is what a name has to pass, so the check is here.
for argument in "${chosen[@]}"; do
	case "$argument" in
		harbour-lights | workshop | entrance-animation) ;;
		*) echo "No such deck: $argument. Known: harbour-lights, workshop, entrance-animation." >&2; exit 1 ;;
	esac
done

if [[ ! -d '/Applications/Microsoft PowerPoint.app' ]]; then
	echo "Microsoft PowerPoint is not installed; these fixtures are authored in it and cannot" >&2
	echo "be rebuilt anywhere else." >&2
	exit 1
fi

mkdir -p "$WORK" "$CONTAINER"
cp -f "$PICTURE" "$CONTAINER/logo.png"

# The English names are the ones PowerPoint writes on an English installation, and the layout
# name is what a `layouts.yml` has to match. This machine's PowerPoint is otherwise German,
# which it would otherwise be for the whole run.
LANGUAGE=(--args -AppleLanguages "(en)")

author () {
	local deck="$1" slides="$2" cx="$3" cy="$4" script="$5"
	local stub="$WORK/$deck.stub.pptx"
	local log="$WORK/$deck.log"
	local waited=0

	rm -f "$log"
	php "$BUILDER/stub.php" "$stub" "$slides" "$cx" "$cy" || return 1

	pkill -9 -f "Microsoft PowerPoint" >/dev/null 2>&1
	sleep 2
	find "$CONTAINER" -name '~$*' -delete 2>/dev/null
	cp -f "$stub" "$CONTAINER/$deck.stub.pptx"
	open -a "Microsoft PowerPoint" "${LANGUAGE[@]}" "$CONTAINER/$deck.stub.pptx"
	sleep 12

	osascript "$BUILDER/$script" "$CONTAINER/$deck.pptx" "$CONTAINER/logo.png" "$log" >>"$log" 2>&1 &
	local running=$!

	while kill -0 "$running" 2>/dev/null && [[ "$waited" -lt 600 ]]; do
		sleep 1
		waited=$((waited + 1))
	done

	if kill -0 "$running" 2>/dev/null; then
		echo "$deck: PowerPoint stopped answering after ${waited}s. Last thing it said:" >&2
		tail -3 "$log" >&2
		kill -9 "$running" 2>/dev/null

		return 1
	fi

	wait "$running" || { tail -5 "$log" >&2; return 1; }

	php "$BUILDER/scrub.php" "$CONTAINER/$deck.pptx" || return 1
	cp -f "$CONTAINER/$deck.pptx" "$HERE/tests/fixtures/powerpoint/$deck.pptx"
	echo "$deck: rebuilt"
}

want () {
	[[ ${#chosen[@]} -eq 0 ]] && return 0

	local asked
	for asked in "${chosen[@]}"; do
		[[ "$asked" == "$1" ]] && return 0
	done

	return 1
}

# A4 rather than a slide shape: 9906000 x 6858000 EMU, which is what PowerPoint writes for it.
want harbour-lights && { author harbour-lights 5 12192000 6858000 harbour-lights.applescript || exit 1; }
want workshop && { author workshop 6 9906000 6858000 workshop.applescript || exit 1; }

# Two slides rather than the deck either of the others is: this one exists for its `<p:timing>`.
want entrance-animation && { author entrance-animation 2 12192000 6858000 entrance-animation.applescript || exit 1; }

pkill -9 -f "Microsoft PowerPoint" >/dev/null 2>&1
rm -rf "$CONTAINER"

if [[ "${1:-}" != "--keep" ]]; then
	rm -rf "$WORK"
fi

echo "The rebuilt decks are in tests/fixtures/powerpoint/."
