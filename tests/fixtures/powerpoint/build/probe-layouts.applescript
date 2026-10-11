-- Reports what PowerPoint puts on a slide for each layout: the names a script can address the
-- placeholders by, and their types.
--
--   osascript probe-layouts.applescript <design.thmx> <log>
--
-- Kept because the authoring scripts are written against the answer and nothing else records
-- it: PowerPoint names a placeholder "Title 1" or "Text Placeholder 3" or "Picture Placeholder
-- 2", and those names are the only handle `fill()` has.
--
-- Two things here are about PowerPoint's scripting interface rather than about PowerPoint, and
-- are worth keeping if this is ever run again:
--
-- - `repeat with piece in shapes of aSlide` hangs. PowerPoint stops answering AppleEvents while
--   it is laying a slide out, and enumerating the collection is what triggers it. Counting and
--   indexing is safe.
-- - The enumerators are written out inside the `tell` that uses them. A handler outside one
--   cannot name `slide layout title slide` at all, and one carried back out of a handler is a
--   reference the `layout` property will not take on the far side.

on run argv
	set designPath to item 1 of argv
	set logFile to item 2 of argv

	tell application "Microsoft PowerPoint"
		set deck to presentation 1
		apply template deck file name designPath

		set wantedLayouts to {slide layout title slide, slide layout section header, slide layout text slide, slide layout two column text, slide layout picture with caption, slide layout title only, slide layout content with caption, slide layout comparison, slide layout blank}

		set i to 0

		repeat with chosen in wantedLayouts
			set i to i + 1
			set layout of slide i of deck to chosen

			repeat with j from 1 to (count of shapes of slide i of deck)
				set piece to shape j of slide i of deck
				set line_ to (i as text) & ": {" & (name of piece)

				try
					set line_ to line_ & " type=" & (placeholder type of piece as text)
				on error
					set line_ to line_ & " type=?"
				end try

				do shell script "echo " & quoted form of (line_ & "}") & " >> " & quoted form of logFile
			end repeat
		end repeat

		close presentation 1 saving no
	end tell
end run
