-- Authors "Workshop notes" in Microsoft PowerPoint, and saves it where the shell can find it.
--
--   osascript workshop.applescript <out.pptx> <picture.png> <log>
--
-- The deck a person ends up with by working rather than by designing: a page that is a sheet of
-- A4 rather than a slide, the layouts the default design brought and no others, a table nobody
-- meant to put there, and a slide that has drifted away from the layout it is nominally on. The
-- comment on each step says which of those it is.
--
-- See harbour-lights.applescript for why the slides already exist before PowerPoint opens the
-- file, and probe-layouts.applescript for where the placeholder names came from.

global logFile

on run argv
	set outputPath to item 1 of argv
	set picturePath to item 2 of argv
	set logFile to item 3 of argv

	tell application "Microsoft PowerPoint"
		set deck to presentation 1

		-- The design a person gets without choosing one. Nothing below renames a layout,
		-- recolours a theme or retypes a heading: this deck is the default, used.
		apply template deck file name "/Applications/Microsoft PowerPoint.app/Contents/Resources/Office Themes/Office Theme.thmx"
		my logIt("applied the default design")

		-- A4, because this deck is projected off a printout as often as off a screen. A page that
		-- is neither 16:9 nor 4:3 is what a template reader is most likely to get wrong, and it
		-- is a size PowerPoint itself offers in its own list.
		set slide size of page setup of deck to slide size A4 paper
		my logIt("page is " & (slide width of page setup of deck as text) & " points wide")

		set layout of slide 1 of deck to slide layout title slide
		my fill(slide 1 of deck, "Title 1", "Workshop notes")
		my fill(slide 1 of deck, "Subtitle 2", "Tuesday")

		set layout of slide 2 of deck to slide layout text slide
		my fill(slide 2 of deck, "Title 1", "Agenda")
		my fill(slide 2 of deck, "Text Placeholder 2", "Where we are" & linefeed & "What broke" & linefeed & "What we do about it")

		-- A picture dropped on top rather than into its placeholder, which is what happens to a
		-- picture inserted into a slide that was laid out first.
		set layout of slide 3 of deck to slide layout picture with caption
		my fill(slide 3 of deck, "Title 1", "The whiteboard")
		set snapshot to make new picture at slide 3 of deck with properties {file name:picturePath}
		set left position of snapshot to 60.0
		set top of snapshot to 150.0
		set width of snapshot to 300.0
		set height of snapshot to 200.0

		-- A table on a slide whose layout has no table in it: *Title Only*, which is what a
		-- person picks before typing into a table.
		set layout of slide 4 of deck to slide layout title only
		my fill(slide 4 of deck, "Title 1", "Numbers we quoted")
		set grid to make new shape table at slide 4 of deck
		set left position of grid to 60.0
		set top of grid to 150.0
		set width of grid to 460.0
		set height of grid to 160.0
		-- A table's cell is only reachable through the shape inside it, and a shape of a table is
		-- not a shape of the slide: `shape 2 of slide 4` is the `graphicFrame` and never a cell.
		-- The four cells are written out rather than looped because `row (n) of board` does not
		-- resolve when `n` is a variable — `row` is a class of PowerPoint's own and the bare
		-- variable reads as the start of a property name.
		set board to table object of grid
		set content of text range of text frame of shape of cell 1 of row 1 of board to "Deck"
		set content of text range of text frame of shape of cell 2 of row 1 of board to "Slides"
		set content of text range of text frame of shape of cell 1 of row 2 of board to "worked in"
		set content of text range of text frame of shape of cell 2 of row 2 of board to "six"
		my logIt("table is " & ((number of rows of grid) as text) & " by " & ((number of columns of grid) as text))

		-- A slide that no longer matches its layout: the left column has been dragged down and
		-- made smaller, and a text box belonging to nothing has been added beside it. Both are
		-- ordinary, and neither is visible to a reader that only ever looks at layouts.
		set layout of slide 5 of deck to slide layout two column text
		my fill(slide 5 of deck, "Title 1", "What broke")
		my fill(slide 5 of deck, "Text Placeholder 2", "The exporter")
		my fill(slide 5 of deck, "Text Placeholder 3", "The theme")
		set left position of my shapeOf(slide 5 of deck, "Text Placeholder 2") to 60.0
		set top of my shapeOf(slide 5 of deck, "Text Placeholder 2") to 240.0
		set width of my shapeOf(slide 5 of deck, "Text Placeholder 2") to 300.0
		set height of my shapeOf(slide 5 of deck, "Text Placeholder 2") to 90.0
		set aside to make new text box at slide 5 of deck
		set content of text range of text frame of aside to "and the build"
		set left position of aside to 420.0
		set top of aside to 150.0
		set width of aside to 200.0
		set height of aside to 60.0

		set layout of slide 6 of deck to slide layout text slide
		my fill(slide 6 of deck, "Title 1", "What we do about it")
		my fill(slide 6 of deck, "Text Placeholder 2", "Write it down first, then draw it.")

		save presentation 1 in (POSIX file outputPath) as save as Open XML presentation
		my logIt("saved")
		close presentation 1 saving no
	end tell
end run

on logIt(message_)
	do shell script "echo " & quoted form of message_ & " >> " & quoted form of logFile
end logIt

on shapeOf(aSlide, wanted)
	tell application "Microsoft PowerPoint"
		set howMany to count of shapes of aSlide
		set j to 0

		repeat while j < howMany
			set j to j + 1

			if (name of shape j of aSlide) is wanted then
				set found to shape j of aSlide

				return found
			end if
		end repeat
	end tell

	error "No shape named " & wanted
end shapeOf

on fill(aSlide, wanted, wording)
	tell application "Microsoft PowerPoint"
		set content of text range of text frame of my shapeOf(aSlide, wanted) to wording
	end tell
end fill
