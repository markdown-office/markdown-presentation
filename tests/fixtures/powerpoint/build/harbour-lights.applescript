-- Authors "Harbour Lights" in Microsoft PowerPoint, and saves it where the shell can find it.
--
--   osascript harbour-lights.applescript <out.pptx> <picture.png> <log>
--
-- `build.sh` opens the stub this deck starts from and hands the three paths in; nothing here is
-- written by hand. PowerPoint cannot create a slide through its scripting interface — `make
-- new slide` answers "class slide cannot be created" — so the slides exist before it opens the
-- file, and everything else below is its own work: the design, the palette, the type, the layout
-- each slide is put on, the text, the picture.
--
-- Placeholders are addressed by the names PowerPoint gives them, which is what changing a
-- slide's layout leaves on it. `probe-layouts.applescript` is where those names were read off;
-- they are the same whatever the deck is about.

global logFile

on run argv
	set outputPath to item 1 of argv
	set picturePath to item 2 of argv
	set logFile to item 3 of argv

	tell application "Microsoft PowerPoint"
		set deck to presentation 1

		-- A design of PowerPoint's own, the way a person's deck arrives with one already on it.
		-- Facet brings layouts of its own, a colour scheme and a type hierarchy.
		apply template deck file name "/Applications/Microsoft PowerPoint.app/Contents/Resources/Office Themes/Facet.thmx"
		my logIt("applied the design")

		-- The palette a person picks once the design is on. Only three of the six accents are
		-- reachable through the scripting interface; the rest stay as the design left them,
		-- which is what happens when it is picked by hand and then left alone.
		set palette to color scheme of slide master of deck
		set color for palette at background scheme to color {246, 244, 238}
		set color for palette at foreground scheme to color {23, 42, 58}
		set color for palette at title scheme to color {23, 42, 58}
		set color for palette at fill scheme to color {38, 70, 83}
		set color for palette at accent1 scheme to color {198, 90, 56}
		set color for palette at accent2 scheme to color {38, 70, 83}
		set color for palette at accent3 scheme to color {226, 201, 122}
		my logIt("set the palette")

		set typefaces to theme font scheme of theme of slide master of deck
		set name of major theme font 1 of typefaces to "Avenir Next Demi Bold"
		set name of minor theme font 1 of typefaces to "Avenir Next"
		my logIt("set the type")

		set layout of slide 1 of deck to slide layout title slide
		my fill(slide 1 of deck, "Title 1", "Harbour Lights")
		my fill(slide 1 of deck, "Subtitle 2", "A field guide to the North Sea coast")

		set layout of slide 2 of deck to slide layout section header
		my fill(slide 2 of deck, "Title 1", "What is out there")
		my fill(slide 2 of deck, "Text Placeholder 2", "Three walks, one afternoon")

		set layout of slide 3 of deck to slide layout text slide
		my fill(slide 3 of deck, "Title 1", "Three kinds of shore")
		my fill(slide 3 of deck, "Text Placeholder 2", "Sand runs widest where the tide drops furthest." & linefeed & "Shingle holds the light at the tideline." & linefeed & "Mud is the richest ground and the hardest to walk.")

		set layout of slide 4 of deck to slide layout two column text
		my fill(slide 4 of deck, "Title 1", "Counting what drifts in")
		my fill(slide 4 of deck, "Text Placeholder 2", "Wrack")
		my fill(slide 4 of deck, "Text Placeholder 3", "Litter")

		-- The caption is filled and the picture is dropped beside it rather than into its
		-- placeholder: putting a picture into a placeholder is not something the scripting
		-- interface can do, and a slide that has drifted from its layout is the ordinary case
		-- anyway. The layout still carries the picture placeholder, which is what a template
		-- reader reads.
		set layout of slide 5 of deck to slide layout picture with caption
		my fill(slide 5 of deck, "Title 1", "The wall of the harbour")
		my fill(slide 5 of deck, "Text Placeholder 3", "Granite blocks, cut in Aberdeen and never meant to stay.")
		set photograph to make new picture at slide 5 of deck with properties {file name:picturePath}
		set left position of photograph to 200.0
		set top of photograph to 180.0
		set width of photograph to 240.0
		set height of photograph to 160.0
		my logIt("five slides")

		-- `save as`, not `save`: the fixture has to land where PowerPoint is already allowed to
		-- write, and the deck is opened from the copy the shell staged.
		save presentation 1 in (POSIX file outputPath) as save as Open XML presentation
		my logIt("saved")
		close presentation 1 saving no
	end tell
end run

-- What is done so far, written where the shell can read it even if the script never returns:
-- PowerPoint refuses to answer an AppleEvent while it is showing a dialog, and a run that hangs
-- says nothing about where it hung without this.
on logIt(message_)
	do shell script "echo " & quoted form of message_ & " >> " & quoted form of logFile
end logIt

-- The shape PowerPoint named that, on the slide it is looking at.
--
-- The `tell` is what puts PowerPoint's terms in scope: a handler outside one cannot name a
-- property at all. The answer is a variable rather than the expression because AppleScript will
-- not read a property chain back out of a `return`.
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
