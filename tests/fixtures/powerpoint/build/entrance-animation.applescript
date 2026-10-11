-- Authors "Entrance animation" in Microsoft PowerPoint, and saves it where the shell can find it.
--
--   osascript entrance-animation.applescript <out.pptx> <log>
--
-- This is the oracle for `p:timing`: there is no other source in this repository for what
-- PowerPoint writes for an entrance animation, and markup written from a description of the
-- format rather than from PowerPoint proves nothing about what PowerPoint reads. Every effect
-- here is therefore added by PowerPoint, and the `<p:timing>` it saves is what the animation
-- code is written against.
--
-- Two slides, because the two questions are different. The first is what one effect on a shape
-- looks like, on the two shapes a slide of this library's own shape of things is made of. The
-- second is what a chain of three looks like, because that is the shape of a build: a click
-- opens one node and the effects after it hang off it in a tree, and the nesting is the part
-- that cannot be reasoned out from a single effect.
--
-- `appear` is used because it is the plainest entrance there is — nothing moves, nothing fades,
-- the shape is simply there afterwards — so whatever the format does beyond that is the
-- structure and not the effect. One `fade` is put in the middle of the second slide's chain so
-- the difference between two effects in one chain is visible rather than assumed.

global logFile

on run argv
	set outputPath to item 1 of argv
	set logFile to item 2 of argv

	tell application "Microsoft PowerPoint"
		set deck to presentation 1

		apply template deck file name "/Applications/Microsoft PowerPoint.app/Contents/Resources/Office Themes/Office Theme.thmx"
		my logIt("applied the default design")

		set layout of slide 1 of deck to slide layout text slide
		my fill(slide 1 of deck, "Title 1", "Three steps")
		my fill(slide 1 of deck, "Text Placeholder 2", "One" & linefeed & "Two" & linefeed & "Three")
		my logIt("slide 1: a title and a body")

		set layout of slide 2 of deck to slide layout title only
		my fill(slide 2 of deck, "Title 1", "A build")

		-- Text boxes rather than placeholders, so that the shapes animated here are ones this
		-- library also writes: a box with an id in write order and a `p:txBody`, and nothing
		-- else in it. The `<p:spid>` each effect names is the thing worth reading off this.
		set firstBox to make new text box at slide 2 of deck
		set content of text range of text frame of firstBox to "First"
		set left position of firstBox to 60.0
		set top of firstBox to 150.0
		set width of firstBox to 300.0
		set height of firstBox to 60.0

		set secondBox to make new text box at slide 2 of deck
		set content of text range of text frame of secondBox to "Second"
		set left position of secondBox to 60.0
		set top of secondBox to 240.0
		set width of secondBox to 300.0
		set height of secondBox to 60.0

		set thirdBox to make new text box at slide 2 of deck
		set content of text range of text frame of thirdBox to "Third"
		set left position of thirdBox to 60.0
		set top of thirdBox to 330.0
		set width of thirdBox to 300.0
		set height of thirdBox to 60.0
		my logIt("slide 2: three text boxes")

		-- `add effect` takes the main sequence as its direct parameter and the shape it is for as
		-- a labelled one, which is why this reads the way it does. The trigger is written out
		-- rather than left to the default: a build that advances on its own is a different thing
		-- from one that waits, and this deck is here to be the latter.
		add effect (main sequence of timeline of slide 1 of deck) for (my shapeOf(slide 1 of deck, "Title 1")) fx animation type appear trigger on page click
		add effect (main sequence of timeline of slide 1 of deck) for (my shapeOf(slide 1 of deck, "Text Placeholder 2")) fx animation type appear trigger on page click
		my logIt("slide 1: two effects, on click")

		add effect (main sequence of timeline of slide 2 of deck) for firstBox fx animation type appear trigger on page click
		add effect (main sequence of timeline of slide 2 of deck) for secondBox fx animation type fade trigger on page click
		add effect (main sequence of timeline of slide 2 of deck) for thirdBox fx animation type appear trigger on page click
		my logIt("slide 2: three effects, on click, the middle one a fade")
		my logIt("slide 2 main sequence holds " & ((count of effects of (main sequence of timeline of slide 2 of deck)) as text) & " effects")

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