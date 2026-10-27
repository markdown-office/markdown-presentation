-- Opens each deck in Microsoft PowerPoint and exports it to PDF.
--
--   osascript tools/render-with-powerpoint.applescript <deck.pptx> [...] --out <directory>
--
-- PowerPoint rather than LibreOffice because PowerPoint is the only application that will tell
-- the truth about whether these decks open. LibreOffice is a different implementation of the
-- same format and renders what it believes the format means; Word cannot open a presentation
-- at all — a `.pptx` is OOXML from Office 2007 and Word was never a PowerPoint product, so it
-- would import the file through a lossy converter and report nothing meaningful.
--
-- The decks are opened inside PowerPoint's own sandbox container and the PDFs are written back
-- out of it. PowerPoint is sandboxed and refuses to read a path outside what the user has
-- granted it with Office error -9074.
--
-- A validator that cannot run must never report success. Every step here either produces the
-- file it promised or raises, and a raised AppleScript error exits non-zero, so a caller
-- reading only the exit status learns the render did not happen.
--
-- Two races are closed here. Both of them end in the wrong deck rather than in an error, which
-- is what makes them worth closing:
--
--   * A PDF is written lazily, so a file that exists is a file that may still be being
--     written. The export is finished when the file has stopped growing and carries the
--     end-of-file marker every PDF ends with, and only then is it copied out. The question
--     has an answer of its own in `tools/pdf-is-complete.sh`, which is a file of its own so
--     that the answer can be checked without PowerPoint.
--
--   * PowerPoint knows a presentation by the name of its file, so a deck staged under the
--     name of a presentation that is already open is that presentation: the export is the one
--     that was open before, the deck that was asked for is nowhere, and the document the user
--     had open is closed by a script that was asked to render something else. Every deck is
--     therefore staged under a name of this run's own, which no open presentation can have.

on run argv
	set decks to {}
	set outDirectory to missing value
	set sawOut to false

	repeat with argument in argv
		-- `text` rather than `item`, both because it is the descriptive word here and because
		-- PowerPoint's dictionary defines `text` as a class of its own and a variable by that
		-- name would not compile against it.
		set word_ to argument as text

		if word_ is "--out" then
			set sawOut to true
			set outDirectory to missing value
		else if sawOut and outDirectory is missing value then
			set outDirectory to word_
		else
			set end of decks to word_
		end if
	end repeat

	if (count of decks) is 0 then
		error "Nothing to render. Pass one or more .pptx paths, then --out <directory>." number 1
	end if

	if outDirectory is missing value then
		error "No output directory. Pass --out <directory>." number 1
	end if

	-- The output directory is created before PowerPoint is started at all, so a path that
	-- cannot be written costs nothing and starts no application.
	set destination to prepareDirectory(outDirectory)
	set stamp to runStamp()
	set scratch to makeScratchDirectory(stamp)
	set opened to {}
	set stagedIn to missing value

	try
		set stagedIn to workingDirectory(scratch)
		set rendered to {}
		set names to {}
		set place to 0

		repeat with deck in decks
			set place to place + 1
			set one to render(deck, place, stamp, stagedIn, opened)
			set end of rendered to one
			set end of names to (one's stem) & ".pdf"
		end repeat

		collect(stagedIn, destination, rendered)
		cleanUp(scratch, stagedIn)

		return names
	on error messageText number errorNumber
		-- The decks this run opened are closed before anything else, whatever went wrong: a
		-- document window left behind is a window the person using the machine finds open on
		-- a deck they did not ask for. Only decks this run opened are touched, so a deck
		-- somebody had open before is still open afterwards.
		closeAll(opened)
		cleanUp(scratch, stagedIn)

		if errorNumber is -128 then
			error "Cancelled." number 1
		end if

		error messageText number errorNumber
	end try
end run

--
-- One deck in, one PDF file name out.
--
-- Raises rather than returning an empty string, because a caller counting files would read a
-- failed conversion as a short deck.
--
-- The deck is staged under a name of this run's own, because PowerPoint knows a presentation
-- by the name of its file: a deck staged under the name of a presentation that is already open
-- is that presentation, and what is exported is the one that was open before.
--
on render(deckPath, place, stamp, working, opened)
	set fileName to nameOf(deckPath)
	set stem to deckNameOf(fileName)
	set stagedStem to stem & "-" & place & "-" & stamp
	set staged to working & (stagedStem & ".pptx")
	set target to working & (stagedStem & ".pdf")

	try
		do shell script "cp -f " & quoted form of deckPath & " " & quoted form of staged
	on error
		error "Unable to read " & deckPath & "." number 1
	end try

	tell application "Microsoft PowerPoint"
		launch

		try
			open POSIX file staged
			set end of opened to stagedStem
		on error errorMessage number errorNumber
			if errorNumber is -9074 then
				error "PowerPoint would not open " & fileName & " (Office -9074). The application is sandboxed and refuses paths outside what the user has granted it, and this script stages the deck inside its own container — so -9074 here means PowerPoint was in a state it could not open a document in. Quit PowerPoint and run it again." number 1
			end if

			error "PowerPoint would not open " & fileName & ": " & errorMessage number 1
		end try

		try
			save presentation stagedStem in POSIX file target as save as PDF
		on error errorMessage
			error "PowerPoint would not export " & fileName & " as PDF: " & errorMessage number 1
		end try
	end tell

	-- Both of these run outside the `tell`: PowerPoint's dictionary makes every handler name
	-- a term of its own, so a call to one from inside is sent to the application instead of
	-- run. `settle` in particular has to be outside, because it polls the filesystem while
	-- PowerPoint is still writing and a call inside the block would be PowerPoint's.
	settle(working, stagedStem)

	-- The document is closed only once the PDF is whole: PowerPoint writes it lazily, and a
	-- document closed while the export is still being written is a PDF that stops in the
	-- middle.
	if not pdfIsComplete(target) then
		error "PowerPoint reported success and wrote no finished PDF for " & fileName & "." number 1
	end if

	closeOne(stagedStem)
	set opened to dropFrom(opened, stagedStem)

	return {stem: stem, container: stagedStem & ".pdf"}
end render

--
-- PowerPoint's sandbox container, which is the one directory it will always read.
--
on workingDirectory(scratch)
	try
		set home to POSIX path of (path to home folder)
		set base to home & "Library/Containers/com.microsoft.Powerpoint/Data/" & scratch

		do shell script "mkdir -p " & quoted form of base

		return base & "/"
	on error errorMessage
		error "Unable to prepare PowerPoint's container: " & errorMessage number 1
	end try
end workingDirectory

--
-- The PDF has to be finished before the deck's document is closed: PowerPoint writes it lazily,
-- and closing first is a race this script would lose on a large deck and win on a small one.
--
-- "Finished" is two questions and not one: the file has stopped growing, and it carries the
-- end-of-file marker every PDF ends with. A file that exists is a file that may still be
-- written, and a copy of that is a copy of half a PDF.
--
-- The wait is `sleep` rather than AppleScript's `delay`, because PowerPoint's dictionary
-- defines `delay` as a class of its own and this script compiles against that dictionary.
--
on settle(working, stem)
	set candidate to working & (stem & ".pdf")
	set bytes to -1

	repeat 120 times
		set current to fileSize(candidate)

		if current > 0 and current is bytes and pdfIsComplete(candidate) then
			return
		end if

		set bytes to current

		do shell script "sleep 0.25"
	end repeat
end settle

--
-- The size of a file in bytes, or -1 where there is no file.
--
on fileSize(candidate)
	try
		return (do shell script "stat -f %z " & quoted form of candidate) as integer
	on error
		return -1
	end try
end fileSize

--
-- Whether a PDF has been written whole.
--
-- `tools/pdf-is-complete.sh` answers it, and it is a file of its own because the answer can
-- be checked without PowerPoint: the script that asks the question needs the application and
-- the answer does not.
--
on pdfIsComplete(candidate)
	try
		do shell script "sh " & quoted form of (helperPath()) & " " & quoted form of candidate
		return true
	on error
		return false
	end try
end pdfIsComplete

--
-- The helper beside this script, found through the script's own path rather than through the
-- working directory: the tool is run from wherever the caller happens to be.
--
on helperPath()
	set here to POSIX path of (path to me)
	set base to nameOf(here)

	return (text 1 thru -(length of base + 1) of here) & "pdf-is-complete.sh"
end helperPath

--
-- What tells this run's files from any other's.
--
-- The epoch second and the shell's own process id, in one shell: two runs a second apart must
-- not stage a deck under a name the other is using, because PowerPoint knows a presentation by
-- its name and would hand back the one it already had.
--
on runStamp()
	return do shell script "date +%s-$$"
end runStamp

-- A directory name of our own inside it, so two runs cannot collide on one another's slides.
on makeScratchDirectory(stamp)
	-- The name is the run's own stamp rather than anything read from the environment: a
	-- directory inside another application's container is a good place not to interpolate.
	return "markdown-presentation-render-" & stamp
end makeScratchDirectory

on prepareDirectory(outDirectory)
	try
		do shell script "mkdir -p " & quoted form of outDirectory
		return outDirectory
	on error errorMessage
		error "Unable to create the output directory " & outDirectory & ": " & errorMessage number 1
	end try
end prepareDirectory

--
-- Moves the PDFs out of the container.
--
-- Copied rather than moved so that a deck that rendered and one that did not leave the same
-- trail: an export that PowerPoint wrote into its own sandbox and then failed to hand back is
-- the one failure a caller cannot see from the output directory alone.
--
-- Each PDF is the one this run rendered rather than every PDF in the directory, and it is
-- copied under the name of the deck it came from: the files in the container carry the names
-- they were staged under, which are not the names the caller asked for.
--
on collect(working, destination, rendered)
	repeat with one in rendered
		set source to working & (one's container)
		set name_ to (one's stem) & ".pdf"
		set copied to destination & "/" & name_

		try
			do shell script "cp -f " & quoted form of source & " " & quoted form of copied
		on error errorMessage
			error "Unable to collect " & name_ & ": " & errorMessage number 1
		end try

		if not fileExists(copied) then
			error "The PDF for " & name_ & " was not written to " & destination & "." number 1
		end if
	end repeat
end collect

--
-- Close a presentation and stop listing it as one this run opened.
--
on closeOne(presentationName)
	try
		tell application "Microsoft PowerPoint" to close presentation presentationName saving no
	end try
end closeOne

on dropFrom(opened, presentationName)
	set kept to {}

	repeat with one in opened
		if (one as text) is not presentationName then
			set end of kept to (one as text)
		end if
	end repeat

	return kept
end dropFrom

-- Every deck this run opened, in the order they were opened.
on closeAll(opened)
	repeat with presentationName in (reverse of opened)
		closeOne(presentationName)
	end repeat
end closeAll

--
-- Removes the directory this run made inside PowerPoint's container.
--
-- The container path rather than the bare name, because `rm -rf markdown-presentation-render-`
-- in whatever directory the caller happened to be in removes nothing at all and reports
-- success: a scratch directory left inside another application's container is a directory of
-- a stranger's slides, and this is the only place it goes away.
--
on cleanUp(scratch, stagedIn)
	if stagedIn is missing value then
		return
	end if

	try
		do shell script "rm -rf " & quoted form of stagedIn
	end try
end cleanUp

-- The file name of a deck, with its extension kept.
on nameOf(deckPath)
	set AppleScript's text item delimiters to "/"
	set parts to text items of (deckPath as text)
	set AppleScript's text item delimiters to ""

	if (count of parts) is 0 then
		error "Not a path: " & deckPath number 1
	end if

	-- Indexed rather than `last part of`: PowerPoint's dictionary defines `part` as a class
	-- of its own, and this script is told to compile against that dictionary.
	return item (count of parts) of parts
end nameOf

-- The file name of a deck without its extension, which is what PowerPoint calls the
-- presentation it opened.
--
-- `$name` rather than `$fileName`, which AppleScript parses as its own `file name` class and
-- then rejects wherever it appears.
on deckNameOf(name)
	set suffix to ".pptx"

	if name ends with suffix then
		return text 1 thru -(length of suffix + 1) of name
	end if

	return name
end deckNameOf

-- `$candidate` rather than `$path`, which PowerPoint's dictionary defines as a class of its
-- own and this script has to compile against that dictionary.
on fileExists(candidate)
	try
		do shell script "test -s " & quoted form of candidate
		return true
	on error
		return false
	end try
end fileExists
