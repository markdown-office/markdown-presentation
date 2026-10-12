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

		add effect (main sequence of timeline of slide 1 of deck) for (my shapeOf(slide 1 of deck, "Title 1")) fx animation type appear trigger on page click
		add effect (main sequence of timeline of slide 1 of deck) for (my shapeOf(slide 1 of deck, "Text Placeholder 2")) fx animation type appear trigger on page click
		my logIt("slide 1: two effects, on click")

		set layout of slide 2 of deck to slide layout title only
		my fill(slide 2 of deck, "Title 1", "A build")

		-- Text boxes rather than placeholders, so that the shapes animated here are ones this
		-- library also writes: a box with an id in write order and a `p:txBody`, and nothing
		-- else in it. The `<p:spid>` each effect names is the thing worth reading off this.
		set firstBox to my textBox(slide 2 of deck, "First", 150.0)
		set secondBox to my textBox(slide 2 of deck, "Second", 240.0)
		set thirdBox to my textBox(slide 2 of deck, "Third", 330.0)
		my logIt("slide 2: three text boxes")

		-- `add effect` takes the main sequence as its direct parameter and the shape it is for as
		-- a labelled one, which is why this reads the way it does. The trigger is written out
		-- rather than left to the default: a build that advances on its own is a different thing
		-- from one that waits, and this deck is here to be the latter.
		add effect (main sequence of timeline of slide 2 of deck) for firstBox fx animation type appear trigger on page click
		add effect (main sequence of timeline of slide 2 of deck) for secondBox fx animation type fade trigger on page click
		add effect (main sequence of timeline of slide 2 of deck) for thirdBox fx animation type appear trigger on page click
		my logIt("slide 2: three effects, on click, the middle one a fade")
		my logIt("slide 2 main sequence holds " & ((count of effects of (main sequence of timeline of slide 2 of deck)) as text) & " effects")

		-- A step here is a group of shapes rather than a single shape, which is what a build
		-- with more than one bullet in it is. `with previous` is what puts an effect in the
		-- click before it instead of opening a click of its own, and whether that is a wider
		-- branch or a fourth effect in the same one is the whole of what this slide asks.
		set layout of slide 3 of deck to slide layout title only
		my fill(slide 3 of deck, "Title 1", "Two steps of two")

		set oneBox to my textBox(slide 3 of deck, "One", 150.0)
		set twoBox to my textBox(slide 3 of deck, "Two", 210.0)
		set threeBox to my textBox(slide 3 of deck, "Three", 270.0)
		set fourBox to my textBox(slide 3 of deck, "Four", 330.0)

		add effect (main sequence of timeline of slide 3 of deck) for oneBox fx animation type appear trigger on page click
		add effect (main sequence of timeline of slide 3 of deck) for twoBox fx animation type appear trigger with previous
		add effect (main sequence of timeline of slide 3 of deck) for threeBox fx animation type appear trigger on page click
		add effect (main sequence of timeline of slide 3 of deck) for fourBox fx animation type appear trigger with previous
		my logIt("slide 3: four effects, on click, with previous, on click, with previous")

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

-- A text box in the left column, at a height that keeps the boxes off each other.
on textBox(aSlide, wording, top_)
	tell application "Microsoft PowerPoint"
		set box_ to make new text box at aSlide
		set content of text range of text frame of box_ to wording
		set left position of box_ to 60.0
		set top of box_ to top_
		set width of box_ to 300.0
		set height of box_ to 60.0

		return box_
	end tell
end textBox
