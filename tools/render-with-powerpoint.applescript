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
	set scratch to makeScratchDirectory()
	set opened to {}
	set stagedIn to missing value

	try
		set stagedIn to workingDirectory(scratch)
		set rendered to {}

		repeat with deck in decks
			set end of rendered to render(deck, scratch, stagedIn, opened)
		end repeat

		collect(stagedIn, destination)
		cleanUp(scratch, stagedIn)

		return rendered
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
on render(deckPath, scratch, working, opened)
	set fileName to nameOf(deckPath)
	set stem to deckNameOf(fileName)
	set staged to working & fileName

	try
		do shell script "cp -f " & quoted form of deckPath & " " & quoted form of staged
	on error
		error "Unable to read " & deckPath & "." number 1
	end try

	set target to working & (stem & ".pdf")

	tell application "Microsoft PowerPoint"
		launch

		try
			open POSIX file staged
			set end of opened to stem
		on error errorMessage number errorNumber
			if errorNumber is -9074 then
				error "PowerPoint would not open " & fileName & " (Office -9074). The application is sandboxed and refuses paths outside what the user has granted it, and this script stages the deck inside its own container — so -9074 here means PowerPoint was in a state it could not open a document in. Quit PowerPoint and run it again." number 1
			end if

			error "PowerPoint would not open " & fileName & ": " & errorMessage number 1
		end try

		try
			save presentation stem in POSIX file target as save as PDF
		on error errorMessage
			error "PowerPoint would not export " & fileName & " as PDF: " & errorMessage number 1
		end try

		close presentation stem saving no
	end tell

	-- Both of these run outside the `tell`: PowerPoint's dictionary makes every handler name
	-- a term of its own, so a call to one from inside is sent to the application instead of
	-- run. `settle` in particular has to be outside, because it polls the filesystem while
	-- PowerPoint is still writing and a call inside the block would be PowerPoint's.
	settle(working, stem)
	set opened to dropFrom(opened, stem)

	if not fileExists(target) then
		error "PowerPoint reported success and wrote no PDF for " & fileName & "." number 1
	end if

	return (stem & ".pdf")
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
-- The PDF has to be on disk before the deck's document is closed: PowerPoint writes it lazily,
-- and closing first is a race this script would lose on a large deck and win on a small one.
--
-- The wait is `sleep` rather than AppleScript's `delay`, because PowerPoint's dictionary
-- defines `delay` as a class of its own and this script compiles against that dictionary.
--
on settle(working, stem)
	repeat 40 times
		if fileExists(working & (stem & ".pdf")) then
			return
		end if

		do shell script "sleep 0.25"
	end repeat
end settle

-- A directory name of our own inside it, so two runs cannot collide on one another's slides.
on makeScratchDirectory()
	-- The name is a literal rather than anything read from the environment or the process: a
	-- directory inside another application's container is a good place not to interpolate.
	return "markdown-presentation-render-" & (do shell script "date +%s")
end makeScratchDirectory

on prepareDirectory(outDirectory)
	try
		do shell script "mkdir -p " & quoted form of outDirectory
		return quoted form of outDirectory
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
on collect(working, destination)
	-- Each PDF is copied one at a time rather than by a glob, because `cp` does not glob and a
	-- quoted pattern handed to it is a literal file name that is never there. The directory is
	-- listed first so that a deck PowerPoint opened and then failed to export is a failure
	-- rather than a silent zero.
	set pdfs to listFolder(working, ".pdf")

	if (count of pdfs) is 0 then
		error "PowerPoint opened the decks but exported no PDF." number 1
	end if

	repeat with pdf in pdfs
		try
			do shell script "cp -f " & quoted form of (working & (pdf as text)) & " " & destination
		on error errorMessage
			error "Unable to collect " & (pdf as text) & ": " & errorMessage number 1
		end try
	end repeat
end collect

--
-- Close a presentation and stop listing it as one this run opened.
--
on closeOne(stem)
	try
		tell application "Microsoft PowerPoint" to close presentation stem saving no
	end try
end closeOne

on dropFrom(opened, stem)
	set kept to {}

	repeat with one in opened
		if (one as text) is not stem then
			set end of kept to (one as text)
		end if
	end repeat

	return kept
end dropFrom

-- Every deck this run opened, in the order they were opened.
on closeAll(opened)
	repeat with stem in (reverse of opened)
		closeOne(stem)
	end repeat
end closeAll

-- The files in `$directory` whose names end in `$suffix`.
--
-- `do shell script` replaces every line feed in its answer with a space, so `ls` output is one
-- string however many files the directory holds and splitting it on line feeds finds one
-- entry. A form feed survives the trip, which is why `find` is asked for one between names
-- rather than for one per line: this is the difference between collecting the deck's PDFs and
-- reporting that there were none.
on listFolder(directory, suffix)
	set separator to ASCII character 12
	set listing to do shell script "ls -1 " & quoted form of directory & " | tr '\\n' '\\014'"

	set AppleScript's text item delimiters to separator
	set entries to text items of listing
	set AppleScript's text item delimiters to ""

	set found to {}

	repeat with entry in entries
		set entryName to entry as text

		if entryName ends with suffix then
			set end of found to entryName
		end if
	end repeat

	return found
end listFolder

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