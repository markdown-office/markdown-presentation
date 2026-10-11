# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **A template's *Title and Content* lost its body.** `TemplateGeometry` keyed a placeholder by
  the `type` attribute as written, so PowerPoint's ordinary content placeholder — written
  `<p:ph idx="1"/>`, with no `type`, because `body` is the default the schema gives it — was
  keyed `/1` and asked the master for `/1`. The master says `body/1`, so it inherited nothing,
  stated no position of its own, and was dropped by `cover()`. A theme mapped to that layout
  got the title box the template states and the library's own body box instead, silently and
  with no error anywhere.

  | | Before | After |
  | --- | --- | --- |
  | a template whose *Title and Content* inherits its body from its master | a title box and no body | both, the body the master's |
  | `workshop.pptx`, `Title and Content` | `title(66,29 828×104)` | plus `body0(66,144 828×343)` |

  The default is now applied before the key as well as before the value. Every `.pptx` this
  library writes spells out `type="body"`, so no test written against one could have shown it;
  the two decks in `tests/fixtures/powerpoint/`, which PowerPoint authored and saved, both
  fail without it.

- **32 bytes of deck asked for half a billion slides.** `<!-- v-click=500 -->` rendered 500
  slides in 0.1s and 0.6MB, and the relationship is linear, so
  `<!-- v-click=2147483647 -->` asked for 2.1 billion. On 64-bit,
  `v-click=99999999999999999999` saturates `(int)` to `PHP_INT_MAX`, and the loop
  `for ($step = self::FIRST_STEP; $step <= $steps; $step++)` never finished: the process hung
  rather than died. Nothing in `src/` bounded it — `BlockParser::jump()` rejected a step only
  when it went backwards.

  | | Before | After |
  | --- | --- | --- |
  | `<!-- v-click=2147483647 -->` | renders until the machine is out of memory | `UnknownMarker`: `Slide 1 has a v-click step "2147483647", which is past the 500 steps one slide may carry: every step is written as a slide of its own. Write "<!-- v-click -->" for the next step, or name one up to 500.` |
  | `<!-- v-click=99999999999999999999 -->` | hangs the process | the same refusal, quoting the twenty digits rather than the `PHP_INT_MAX` they saturated to |
  | 40 slides of 20 steps each | 800 written slides | `TooManySlides`: `This deck asks for 1200 slides, and one presentation may carry 1000...` |

  **Breaking**, in the sense that a deck which rendered now fails. Both ceilings are far
  above what a deck written by a person contains — a build-up of 500 clicks is not a slide,
  and a talk of 1000 slides does not exist — and both messages name the number that would
  have worked.

  Two ceilings rather than one because they bound two different attacks, and a deck gets
  past the first with the second: the per-slide step check cannot see a deck of forty slides
  that each stay inside it. `PresentationRenderer::blocks()` therefore reads every source
  slide once, adds up the steps, and refuses before the first slide is built rather than
  while the four hundred and ninety-ninth is being written. Reading the blocks there rather
  than in `write()` also keeps each slide parsed once.

- **A deck could read any file on the host into the download.** `BlockParser::image()`
  resolved a picture by string concatenation:

  ```php
  $resolved = str_starts_with($written, '/') ? $written : rtrim($directory, '/') . '/' . $written;
  ```

  No `realpath()`, no containment. `../` walked out of the deck's own directory and an
  absolute path was used verbatim, and `ImageConverter` decides a format from the extension
  without opening the file, so any readable `*.svg`, `*.png`, `*.jpg` or `*.gif` was embedded
  byte for byte. Reproduced end to end: a 62-byte deck naming
  `![](/private/var/.../secret/creds.svg)` produced `ppt/media/creds1.svg` containing the host
  file verbatim. SVG is the sharp case because SVG is arbitrary text a person then opens.

  After: the path is resolved with `realpath()` and has to be under a directory the caller
  allowed. `Filesystem\PathScope` is that rule, and `realpath()` rather than a prefix because
  `..` and a symlink both walk out of a directory a string test says they are inside.

  | Deck writes | Before | After |
  | --- | --- | --- |
  | `![](/etc/hosts)` | `ppt/media/hosts1`, verbatim | `ImageNotFound`: `... which is a file at "/private/etc/hosts" rather than one this render may read` |
  | `![](../secrets/creds.svg)` | the same, one directory up | the same failure |
  | a symlink inside the deck directory pointing out of it | the target, verbatim | the same failure |
  | `![](phar://payload.phar/dot.png)` | joined onto the deck directory, refused as "not a file" | refused as a URL, which is what it is |

  **Breaking**, and the deck that could no longer be rendered is the point: an absolute path,
  a `../`, or a symlink out of the deck directory that resolved before is refused now. A
  curated image library is a real case and is still reachable, as a constructor argument —
  `new PresentationRenderer($directory, imageRoots: ['/srv/brand'])` — because that is the
  calling program's decision rather than the deck's.

  `tests/Unit/rendering.php` carried a test named *"takes an absolute image path as it is"*
  which asserted the vulnerability: it rendered a deck naming `/…/2x2.png` with the renderer
  pointed at `/nowhere` and expected the picture in the file. It is now *"refuses an absolute
  image path outside the directory the renderer was given"*, with the other half of the
  original assertion — a picture under an allowed root still embeds — kept as *"reads an image
  from a root the calling program allowed"*. A test asserting the vulnerability is a test that
  fails when the vulnerability is fixed, which is the wrong way round.

- **`theme:` was a path the deck controlled.** `ThemeResolver::resolve()` treated anything
  with a `/` in it as a directory and loaded `<dir>/theme.yml` from wherever it pointed:
  `../` walked out and an absolute path was used verbatim. What a theme load reaches is not
  only colours — a deck naming no `author` takes `dc:creator` from the theme it was rendered
  in (`PresentationRenderer::properties()`), so `theme: ../../secrets/theme.yml` was a way to
  put an arbitrary file's `author:` line into `docProps/core.xml` of the download.

  | Deck names | Before | After |
  | --- | --- | --- |
  | `theme: ../secrets/theme` | loaded; its `author:` is in `docProps/core.xml` | `ThemeNotFound`: `The theme "../secrets/theme" is a directory at "/srv/secrets/theme" rather than one this render may read (/srv/decks). Copy it under the working directory, or give the ThemeResolver that directory as one of its additional roots.` |
  | `theme: /elsewhere/acme` | loaded | the same failure — a spelling, not a permission |
  | `theme: phar://payload.phar/theme` | joined onto the working directory, refused as "no directory" | refused as a URL, which is what it is |

  The rule is `Filesystem\PathScope`, the same one an image path has to pass, over the same two
  ways out: `..` and a symlink. A shared library of themes beside the project is still
  reachable, as `new ThemeResolver($root, roots: ['/srv/themes'])`.

  **Breaking**: a deck whose `theme:` resolved outside the working directory or the built-in
  themes rendered before and is refused now. `tests/Unit/theme-resolver.php` carried two
  tests asserting that — *"resolves a path, so a theme can live anywhere"* and *"resolves an
  absolute path as it stands"* — and both are now the refusal, with the shapes they were
  reaching for kept as the other half: an absolute path *inside* the working directory still
  resolves, and a directory the caller allowed still loads.

- **Any URL scheme in a deck became a live hyperlink.** `[text](anything)` was written into
  the package as a hyperlink on the run, so the target decided what happened when a reader
  clicked it:

  | Target | What it is when a reader follows it |
  | --- | --- |
  | `\\host\share\payload.exe` | an SMB connection made with no prompt. The reader's credentials go to whoever answers as NTLM: a relay primitive that costs the reader nothing |
  | `javascript:…` | a script in every reader that renders the deck as a document, and a stored payload in every pipeline that converts a deck to HTML |
  | `data:`, `vbscript:`, `file://`, `phar://` | the same class of thing |

  Now refused at parse time by `InlineParser::link()` with the alternatives in the message:
  a target naming a scheme other than `https`, `http` or `mailto`, and a share in either
  spelling (`\\host\share`, `//host/share`).

  **A relative target is carried, not refused**, which is a narrower fix than an
  allow-list. `![alt](logo.png)` inside a sentence is a picture this renderer draws as text,
  and an allow-list would refuse every deck that writes one — including this package's own
  `tests/Unit/block-parsing.php`, whose *"reads an image inside a paragraph as text"* test
  passes an absolute path. What is refused is the two shapes a reader's machine acts on, and
  what is carried is a path beside the deck.

  **Breaking**: a deck carrying a refused target rendered before and is refused now. A deck
  whose links are URLs, mail addresses or relative paths is unaffected.

- **A kilobyte of PNG could exhaust the machine.** `ImageConverter::format()` called
  `getimagesize()`, kept the MIME type and discarded `$size[0]`/`$size[1]` — and then
  `convert()` decoded the whole image into a `truecolor` bitmap. A picture is 4 bytes to the
  pixel once decoded, so a PNG whose header claims 50000 × 50000 is about a hundred bytes on
  disk and 9.3 GB of memory when GD opens it. GD's own ceiling stops it, as a fatal error
  halfway through a render with no message naming the deck that caused it.

  Now refused, from the width and the height `getimagesize()` had already returned and
  before anything opens the file:

  | | Before | After |
  | --- | --- | --- |
  | a PNG whose header claims 50000 × 50000 | decoded until the process is killed | `UnsupportedImageFormat`: `Slide 1 shows "bomb.png", which measures 50000 x 50000 pixels. Decoding it is about 9.3 GB of memory, and this render opens no picture over 16777216 pixels: scale it down before putting it in a deck.` |

  The bound is 4096 × 4096 — `ImageConverter::MAX_PIXELS`, a public constant so the number
  is a fact about this library rather than a number inside a method. A slide is drawn at
  1920 × 1080, so it is four times the resolution of anything the output can show.

- **An SVG was embedded unexamined, and nothing said so.** The format check has always been
  an extension check for SVG, because `getimagesize()` cannot read a `viewBox`-sized one
  before PHP 8.5. What that means is that the file goes into `ppt/media/` byte for byte:
  whatever `<script>`, `<foreignObject>` or `<image href="https://tracker/…">` it contains is
  in the deck, and whatever a reader does with it is the reader's decision.

  Not fixed here, and the README says so next to the extension-only check rather than
  leaving a reader to find out: sanitising SVG properly is a parser and a policy about what
  to drop, and this library has no claim to either. What it now has is the sentence, its
  consequences spelled out one by one, and the note that `MAX_PIXELS` does not reach an SVG
  because nothing here opens one.

- **The notes pass deleted a part it had failed to read.**
  `NotesColourMapPass::applyTo()` called `$zip->getFromIndex($index)` twice — once to patch the
  part, once to compare the patched part against it — which decompresses the notes slide twice
  for a comparison that needs no second copy. On an entry the archive could not decompress the
  second call answered `false`, the guard compared `'' === false` (which is false), and the
  entry was deleted and written back as **zero bytes**: a deck that opens, with a corrupt
  speaker note and no complaint. The part is now read once, and `false` is
  `UnreadablePackage` rather than an empty string.

  The loop's own invariant is written down where it is relied on: it terminates because
  `deleteName()` and `addFromString()` keep `numFiles` constant, one part going and one of
  the same name coming back. Deleting without adding would take the archive apart; adding
  without deleting would grow it by an entry per patched slide for the loop to walk again.

- **A `RuntimeException` outside this package's hierarchy.** The README promised "everything
  this library reports extends `MarkdownPresentation\Exception\Exception`, so one `catch`
  covers it", and `NotesColourMapPass` threw a bare `RuntimeException` — which does *not* match
  that `catch`, since `Exception` is the subclass and not the superclass. A caller following
  the page therefore caught nothing. There is now
  `MarkdownPresentation\Exception\UnreadablePackage`, extending `Exception` directly, and it
  is under the base class as the page says.

  It sits beside `InvalidInput` rather than under it, and
  `tests/Unit/exceptions.php` now has the list of failures that are the caller's rather than
  the deck's — the day that test was written for. A file that is not a package and an entry
  that does not decompress are a disk or a download that went wrong; telling a deck author to
  rename something would send them looking for a typo they did not write.

- **A layout part was a name and nothing else.** Before: `themes/default/layouts.yml` mapped
  the portable roles onto Office's own layout names and the file recorded those names — and
  then wrote a layout part whose shape tree held nothing at all:

  ```xml
  <p:sldLayout …><p:cSld name="Title and Content"><p:spTree>
    <p:nvGrpSpPr/><p:grpSpPr><a:xfrm>…0,0…</a:xfrm></p:grpSpPr>
  </p:spTree></p:cSld></p:sldLayout>
  ```

  Every shape on every slide was a free-floating text box positioned by `SlideGeometry`, which
  is hardcoded in PHP and not themeable. So PowerPoint's *Reset Slide* and *Reset* did
  nothing, the outline view was empty, the layout picker offered five blank rectangles, and
  no theme could move anything. After: every layout part a deck uses carries a placeholder for
  its title and one for each of its columns, and the title shape on each slide refers to the
  layout's.

  | Role | Placeholders in the layout part |
  | --- | --- |
  | `title` | `title`, `body 1` |
  | `content` | `title`, `body 1` |
  | `two-column` | `title`, `body 1`, `body 2` |
  | `section` | `title`, `body 1` |
  | `blank` | `body 1` — the role has no title, so its layout has no title placeholder |

  Written at the boxes the slides are drawn in, so *Reset* restores the geometry a slide
  already has and moves nothing; a test compares the two boxes rather than assuming.

  Nothing on a slide moved. All 42 preview images of the eleven example decks are
  byte-identical before and after, and the only parts of each package that changed are the
  layout parts and the `<p:nvPr/>` of each slide's title shape.

  This is a pass over the written package (`Render\PlaceholderPass`) rather than shapes added
  through PHPPresentation, because `Shape\Placeholder` is written for a shape that is *only* a
  placeholder and this library's title is not that:
  `AbstractSlide::writeShapeText()` names a placeholder `"Placeholder for title"` whatever it
  was called, and writes an **empty** `<a:bodyPr/>` for one — dropping the `normAutofit` and
  the insets `ShapeWriter::textBox()` sets, which hands the shape back to the reader to
  resize.

  What is deliberately *not* written: prompt text (PowerPoint shows "Click to add title" of
  its own accord for an empty placeholder), `idx` on a title (absent is `0`, and that is how
  Office writes the pair), `txBox` on a placeholder (it says the shape is a free text box and
  takes it out of the layout's inheritance), and a `<p:ph>` on anything but the title. A
  slide's body is a stack of differently shaped blocks — a table, a picture, a code block —
  and promoting one of them to a placeholder would make *Reset* move that block and leave the
  rest of the column floating. The outline view therefore shows a deck's titles and still
  shows no bullets.

- **`corporate-dark` was a dark palette on a white page.** Before: the theme named
  itself dark and had a near-black `dk1` and a near-white `lt1`, and every deck rendered
  with the light token painting the page and the dark token painting the type: a
  `#CDD6F4` page under a `#1E1E2E` heading, in a deck called *Corporate Dark*. The palette
  was never wrong; the mapping was. `PresentationRenderer::master()` wrote the same colour
  map for every theme — `<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2"/>` — and
  `bg1="lt1"` says the page is painted with the light one. After: a theme says `dark: true`
  and the map swaps to `bg1="dk1" tx1="lt1" bg2="dk2" tx2="lt2"`, which is what
  PowerPoint's own dark themes do. The same twelve colours now produce a `#1E1E2E` page
  under a `#CDD6F4` heading.

  The swap had to reach further than the map. `AbstractDecoratorWriter::writeColor()`
  writes `a:srgbClr` for every `Color` it is given, so every shape on every slide carries
  a literal hex and never consults the colour map — swapping it alone would have produced
  a dark page with `#1E1E2E` text on it. So the four roles the map maps are now asked of
  the theme (`Theme::page()`, `ink()`, `subtleInk()`, `panel()`) at every point a shape
  picks a colour, which is also what keeps the map and the shapes from disagreeing:

  | Drawn as | Slot it was named | Slot on a dark theme |
  | --- | --- | --- |
  | the page behind every slide | `lt1` | `dk1` |
  | body text, headings, titles, table cells | `dk1` | `lt1` |
  | a quote, a code block's own text | `dk2` | `lt2` |
  | a code block's fill, a table's header row | `lt2` | `dk2` |
  | rules, bullets, links | `accent1`, `hlink` | unchanged — the map does not move them |

  `default` and `minimal` write byte-identical parts: for a light theme the four roles
  resolve to the slots the map already named.

  The palette needed no adjustment. Measured against the page it now paints: body text
  11.3:1, a quote 9.3:1, the code block's text on its own fill 9.9:1, the table header's
  text on its own fill 12.1:1, bullets and rules 7.8:1 — all far above the 4.5:1 AA floor.
  `dk2` is `#181825`, one step *below* the page rather than above it, so a code block and
  a table header read as a recessed band at 1.07:1 against the page; that is the same
  subtlety `default`'s `#E7E6E6` header has against white, and it is legible in the
  rendered previews. A test now fails a shipped dark theme whose ink drops under 4.5:1.

- **A deck with a speaker note could not be opened by Keynote.** PHPPresentation writes a
  colour map for a slide and for a slide layout but not for a notes slide. Keynote refuses
  such a file outright — "deck.pptx can't be imported" — while the same deck without a note
  imports fine, and PowerPoint and LibreOffice never notice. Every notes slide is now given
  `<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>`, where `CT_NotesSlide` requires it in
  order.

  Upstream has this open as
  [PHPOffice/PHPPresentation#1034](https://github.com/PHPOffice/PHPPresentation/pull/1034).
  `NotesColourMapPass` should be deleted once this package takes that release, rather than
  kept as a second implementation of a fix upstream then owns.

### Notes

- **Exception messages repeat absolute server paths, and that is a choice.** Not a finding
  fixed here: for a person running this from a terminal the path is the useful half of the
  message, because the difference between `logo.png` and `/Users/someone/deck/logo.png` is
  the whole of what is wrong, and a failure that would not say where it looked would send
  the author to `find`. The cost — a deck whose failure ends up in a log, an issue tracker
  or a CI transcript carries the layout of the machine that produced it — is now written down
  in the README under *What a failure repeats*, along with what bounds it: a deck cannot
  choose the paths in a message, because the two things a deck writes paths for (an image and
  a theme) are contained to directories the calling program allowed.

- **Two decks Microsoft PowerPoint authored are carried as fixtures.** Every template test was
  checked against `tests/fixtures/themes/templated/template.pptx`, which this repository writes
  from a description — a good template, and a poor PowerPoint file: one master, layouts written
  by the same hand as the reader, and placeholders that spell out every attribute the format
  lets them spell out. `tests/fixtures/powerpoint/` holds two decks saved by PowerPoint 16.113.4
  instead: one designed (Facet, a changed palette, two changed theme fonts, eighteen named
  layouts) and one merely used (A4 rather than 16:9, a table on a layout with no table in it, a
  slide that has drifted away from the layout it is on). That is what found the bug above. They
  are validation and documentation, not themes — the three built-in themes stay library-owned.

  `tools/build-powerpoint-fixtures.sh` rebuilds both. PowerPoint is required rather than
  optional: `make new slide` answers "class slide cannot be created", so the slides have to
  exist before it opens the file and everything after that is its own work. The authorship
  metadata PowerPoint writes is stripped afterwards, and a test checks that it was.

The first slice of the PowerPoint half of `markdown-office`. It decides where a
slide begins and which theme a deck means, and renders the deck to a `.pptx`.

### Added

- **`ThemeLoader` had been storing a theme's `template.pptx` path and nothing ever opened
  it.** Before: a theme directory's `template.pptx` was read as a path, kept, and never read,
  so a theme that shipped one got exactly the geometry every other theme got. After:
  `Render\TemplateGeometry` reads the file, takes the placeholder boxes each of its layouts
  carries, and the deck is laid out in them — the slides *and* the layout parts this renderer
  writes, so *Reset* stays a no-op there too.

  | Read from the template | Stays with `SlideGeometry` |
  | --- | --- |
  | the position of each layout's `title` and `body` placeholders | the size of the type and its alignment |
  | a placeholder a layout does not place, from its master | the page, the colours, the fonts |
  | which column is which, by `p:ph@idx` | the shapes on the slide, one per block |
  | — | the master's own drawings: a logo, a rule, a decorated background |

  The precedence is per role: **a template fills in the roles it covers and says nothing
  about the rest.** A role whose layout the file does not have, and one whose layout it has
  with no placeholders in it — Office's own *Blank* — keep the geometry the library puts them
  at. That is the same rule `layouts.yml` already follows, where a theme that maps three of
  the five roles is a theme that works rather than one that fails on the two.

  EMU to pixels is a **ratio, not a division by 9525**. A stock 16:9 PowerPoint page is
  12192000 × 6858000 EMU, which is 1280 × 720 of the pixels a `Box` counts in rather than
  960 × 540; dividing by the constant would put every placeholder of a stock template off the
  right and the bottom of the slide. Each axis is scaled by the ratio of the template's page
  to this one's, so a placeholder keeps the position its designer chose.

  A placeholder that states no position takes its master's, which is what PowerPoint writes
  for a layout that does not move the one it inherited; a placeholder whose box has been
  dragged off the page — an `a:xfrm` full of zeroes — states nothing and is skipped. A footer,
  a date and a slide number are placeholders too and are left out rather than laid out as body
  text.

  A template that cannot be read is a named failure — `Exception\MalformedTemplate`, under
  `InvalidInput` — rather than a theme that renders as though it had not said where its
  geometry comes from. It names the file and, where a part is at fault, the part. Measurement
  and overflow clamping are unchanged: they read the box they are given, so the same deck
  overflows in a theme whose content column is shorter and does not in one whose is not.

  No shipped theme ships a template, so `default`, `minimal` and `corporate-dark` are
  untouched: all 42 preview images of the eleven example decks are byte-identical, and the
  only parts of each package that differ from before are the layout parts and each slide
  title's `<p:nvPr/>`.

  A new theme is `tests/fixtures/themes/templated/`, whose `template.pptx` is written by
  `tests/fixtures/themes/templated/build.php` rather than kept as an opaque binary. It is
  deliberately a foreign file — a page that is not 960 × 540, placeholders written out of
  order, one that inherits from its master and one that is not a placeholder at all — because
  a template built by this library would agree with the reader about everything that matters.

- `dark: true` in a `theme.yml` — the key that says the page is dark. It renames no
  colour; `dk1` is still the dark one and `lt1` the light one. It says which of them
  paints the page and which paints the type on it, which is what the colour map on the
  master then records and what every shape's colour is resolved through. A value that is
  not a boolean is refused — `dark: yes` is a YAML 1.1 truthy *string*, and a theme
  written that way would otherwise render light with nothing saying so — and a key that is
  not one names the ones that are, as every other key in the file does.
- `Document\DeckParser` — a Slidev-compatible deck splitter, a port of `parseSync`
  in `slidev/packages/parser/src/core.ts`. `---` closes a slide, a fenced code
  block is skipped whole, an HTML comment is tracked across lines, and the first
  block is the deck's frontmatter.
  - The dialect is written down in `DeckParser::dialect()`, so a deck author — or a
    model writing one — does not have to read the parser to know its rules. A
    top-level thematic break cannot be written as a bare `---` inside a slide;
    `***` is one. Slidev behaves the same way.
  - `tests/fixtures/slidev/` holds decks Slidev's own parser was run against, so the
    port is checked against the real thing rather than against a reading of it.
    `divergences.json` lists the ones it is meant to differ on, each with a reason.
- `Render\PresentationRenderer` — writes a deck and the theme it names out as a
  `.pptx`. Every block of a slide becomes a shape named after the kind of block it
  was, so the mapping can be read off `ppt/slides/slideN.xml` without the library.
  - One slide part per slide, and one per `v-click` step, each revealing the steps
    before it: PowerPoint has no "press a key to reveal this".
  - A `layout:` role becomes a slide layout part named by the theme, so the deck's
    portable vocabulary and the template's private one stay apart.
  - The theme's twelve colours become the presentation's colour scheme and `lt1`
    the page behind every slide, which is what makes `accent1` mean the theme's
    accent everywhere in the file.
- `Render\BlockParser` and `Render\ShapeWriter` — the block grammar a slide uses,
  and the shapes those blocks become. Measure and draw are one object on purpose: a
  height computed by one pass and a shape drawn by another drift apart the first
  time somebody edits one of them.
- `Render\ImageConverter` — checks an image's format before anything is built. A
  `.pptx` carries GIF, JPEG, PNG and SVG, and PHPPresentation declares whatever media
  type it is handed a path to, so a WebP was written into the package as
  `image/webp` and the deck opened with the picture missing. A format GD can decode
  is converted to PNG; one it cannot, and a file that is not an image at all, are
  refused with a message naming what would have worked.
- `Theme\Theme` — a theme's colours, fonts and metadata, over DrawingML's twelve
  colour slots. The spellings an earlier draft of the concept used — `dark1`,
  `light1`, `dark2`, `light2`, `hyperlink` — are accepted and normalised, because a
  theme written with them is otherwise a colour slot PowerPoint does not have. A
  colour or font a file does not name keeps Office's default, so a theme that names
  one accent still produces a complete colour scheme.
- `Theme\ThemeLoader` — reads a theme directory. Only `theme.yml` is required;
  `styles.yml`, `layouts.yml` and `template.pptx` are read when they are there.
- `Theme\ThemeResolver` — resolves a `theme:` in the order a theme is looked for:
  an explicit path, `./themes/<name>` beside the deck, a theme that ships with the
  library, and otherwise a failure listing every name that resolved.
- `Theme\LayoutRole` and `Theme\StyleSlots` — the portable layout roles a deck may
  write, and the Word style slots a `styles.yml` may name.
- Three base themes: `default`, `corporate-dark` and `minimal`.

### Fixed

Places where the library rendered silence rather than a failure over a directive a
deck could name, each now either acted on or refused with the spelling that
would have worked; places where a block was drawn at a place nothing had
measured; and places where what the deck said did not survive into the file.

- **`author:` reaches the file.** Before: `author:` was in the directive list,
  was enforced as a slide-1 directive and was read by nobody —
  `docProps/core.xml` carried the *theme's* author whatever the deck said, so a
  deck written by one person was credited to whoever wrote the theme. After: the
  deck's `author:` is `dc:creator`, the theme's is what a deck that names none
  falls back to, and an `author:` that is not a string is a failure like a
  `title:` that is not one. The deck wins because it is the document and the
  theme is the template it was written in.
- **`level:` governs the size of the title.** Before: validated by
  `DeckParser`, carried on `Slide::level()`, and read by nothing — a directive a
  deck could set correctly and that could not change a pixel. After: it is the
  size the slide's title is drawn at. `level: 1` is the layout's own title size
  and every level below it is the size a heading of that depth is drawn at in the
  same body, so a title named in the frontmatter reads the same as one written as
  a heading. Where a slide has both a `title:` and a `#` in its body, `level:`
  moves the title and the `#` keeps its own depth — they are two shapes in two
  boxes, and only one of them is the title. A `blank` layout has no title box,
  so its title is the heading in its body, and `level:` governs that one too.
- **A marker on the last line of a slide is a step.** Before: any HTML comment
  running to the end of a slide was taken as its speaker note, and the note was
  taken before anything was parsed into blocks, so `<!-- v-click -->` as the
  last line cost the deck its step and wrote the literal text `v-click` into the
  notes pane. After: a comment that is nothing but a step marker is that step.
  `<!-- v-click=two -->` in the same position is now refused by name instead of
  hidden in the notes. A note that merely mentions `v-click` is still a note,
  and a step nothing was written on is still not a slide: the file would carry
  two slides drawn the same way, which is a copy rather than what the deck said.
- **A directive in the body of a slide is refused, not drawn.** Before:
  `BlockParser` matched one comment, `<!-- v-click -->`, and everything else
  fell through to the paragraph branch — so `<!-- v-if false -->` became a
  shape holding that literal text, and so did `<v-click>` and `::: note`. After:
  an HTML comment that is neither a step nor the note after the last line, a
  `v-*` element or any element carrying a `v-*` directive, and a `:::` container
  are all `UnknownDirective`, naming what was found and the spelling that would
  have worked. The whole slide is judged before it is parsed into blocks, so a
  directive in a table row or a wrapped list item is refused like one standing on
  a line of its own; a comment inside a fenced code block is left to the code
  block, which is how a deck shows one. A marker sharing its line with a word is
  not a marker, and now raises rather than printing. A plain HTML tag is not in
  Slidev's vocabulary and is still text — `<b>` is a deck saying something about
  HTML, not a directive.
- **A frontmatter block is closed by a delimiter line.** Before: the port of
  Slidev's `RE_FRONTMATTER` matched three dashes *anywhere*, so the lazy closer
  stopped inside the block — `description: a---b` read `a` and put `-b` and the
  closer into the slide's body, and a quoted value holding `---` was not YAML at
  all. After: the closer is anchored to a line, indented by no more than three
  spaces as every other block construct here is, and the match ends at the end of
  that line. Parity is unchanged — 94 decks, 77 identical, the same 17 declared
  divergences — and `tests/fixtures/slidev/divergences.json` is untouched because
  nothing diverged. Anchoring at column zero instead would have fixed this bug
  and introduced two worse ones, which the `closing delimiter indented` deck in
  the fixture shows.
- **A table is measured by the rows it writes.** Before: `SlideGeometry::TABLE_ROW`
  was 26 while PHPPresentation's `Row` writes `<a:tr h>` at 38, so a four-row
  table declared 104px, was drawn at 152px, and the block after it was placed
  at 208 — every table overlapped whatever followed it. After: the constant is
  38 and `ShapeWriter` sets it on every row it creates, so the height in the
  frame and the height on the row are the same number by construction.
- **No shape is written at a geometry the format refuses.** Before: the room
  left in a column was whatever was under the cursor, which goes negative once
  the cursor passes the bottom of the box. Thirty paragraphs followed by an
  image wrote `<a:ext cx="-381000" cy="-95250"/>`, and `a:ext` is an
  `ST_PositiveCoordinate`: PowerPoint offers to repair the file or drops the
  shape without saying so. After: the renderer holds both the cursor and the
  block's height inside the column's box, and the writer clamps whatever it is
  handed, so a block that does not fit is drawn as small as the room allows
  rather than over what is under it.
- **Blocks are measured by how many lines they wrap to.** Before: a paragraph
  was 26px whatever it said — a 200-word paragraph wraps to about ten lines —
  a code block was `lines × 18 + 24`, so sixty lines measured 1104px on a 540px
  slide, and a list was `items × 26`. After: `Render\TextMetric` counts the
  lines, from an advance-width table bundled in the package rather than from a
  font file — `imagettfbbox()` measures whichever face the build machine has, so
  the same deck would lay out differently on two machines and the output would be
  a property of the machine. The reference is Helvetica's own widths, within a
  few percent of Calibri, Inter and Roboto and on the wide side, so the estimate
  over-allocates height rather than under-allocating it. The monospaced half is
  exact: every character advances 600/1000 of an em, which is what Consolas,
  Menlo and JetBrains Mono use.
- **A text box shrinks rather than grows.** PHPPresentation's default is
  `a:spAutoFit`, which hands the box's height to the reader: a block whose
  measurement came out a line short grows downwards over whatever was below it.
  Every text shape is now written `a:normAutofit` instead, which keeps the
  height the layout gave it and scales the type down to fit. The measurement is
  an estimate, so a mis-estimate has to land somewhere, and this is the direction
  where it costs a little whitespace rather than an overlap.
- **A table's header row is a header row.** Every row was `sz="1200"` in
  `dk1`, so the row naming the columns looked exactly like the rows under
  it. It is now bold, filled with `lt2`, and the table is written with
  `a:tblPr firstRow="1"` and `bandRow` off — the flag PowerPoint's own table
  styles hang a header treatment on, spelled out here because this library
  writes no table style for one to come from, and with banding off because a
  band drawn behind a fill the writer chose would fight it.
  `<a:tblHeader/>`, which repeats the row where a table splits across
  slides, is not written: PHPPresentation's `Row` carries no such flag, and a
  table clamped to one slide is not a table that splits.
- **A block that does not fit is clamped and reported.** Before: a slide with
  more on it than fits was laid out as though it fitted — and whatever came
  out was whatever came out. After: the block is drawn as small as the room
  allows, `PresentationRenderer::overflows()` lists every block that had to be
  clamped with the slide, step, column, height wanted and height left, and
  `new PresentationRenderer(strict: true)` raises `ContentOverflow` instead, by
  name, with the slide and the block in the message. The report is emptied by
  each render rather than accumulated: a layout belongs to one deck.
- **A link inside a heading or a quote is coloured as a link.** Before:
  `$font ?? …` short-circuited the link branch, so a heading passed `dk1` for
  every one of its runs and a quote `dk2` — and a link in either was painted
  that colour, underlined but otherwise indistinguishable from the sentence
  around it. After: the branch is asked about the link first, so `hlink` wins
  over the block's own colour everywhere it applies.
- **Two blocks no longer abut.** Every paragraph was written with
  `setSpacingBefore(0)` and `setSpacingAfter(0)`, which is right — the heights decide
  where the next block starts — but the gap that leaves was nowhere, so two
  consecutive paragraphs sat with one line of text against the next. The gap is
  in the layout now (`SlideGeometry::BLOCK_GAP`), where the heights can see it.
- **A table's columns are divided exactly, and are not all the same.** Before:
  `intdiv(880, $columns)` per column, which drops up to a pixel each — twelve
  columns came to 876 and three to 879, and a grid narrower than the shape it
  sits in is one PowerPoint lays out again itself — and every column was that
  width whatever it held. After: the last column takes the remainder so the grid
  is the shape's width exactly, and the columns are proportional to the widest
  cell in each, with a floor wide enough for a label to show in.
- **A code block is padded by its real insets.** `CODE_PADDING` added twelve
  pixels to the height of a block whose body insets are 91440 EMU across and
  45720 down — 9.6px and 4.8px, and cost to a text block's width as well as its
  height. Both are measured now, as `SlideGeometry::INSET_X` and `INSET_Y`.
- **A title with inline markup is drawn once.** Before: `DeckParser` stores a
  slide's title as it was written and the renderer compared it against the
  heading's text with its markup already taken out, so the two could never match
  as soon as either carried any: `# Release **v2** notes` drew a title reading
  `Release **v2** notes` *and* the same line again in the body as three runs.
  After: both sides are stripped by the one `InlineParser` that read the
  heading — `InlineParser::text()` on its runs and `InlineParser::plain()` on the
  title — so the comparison cannot be made differently on the two sides. The
  heading that became the title is now looked for among the slide's blocks rather
  than assumed to be the first one, because the title is the first *heading* and a
  paragraph written above one does not stop it being the title; a heading further
  down the slide is still content and is still drawn.
- **A title carries its inline markup.** Before: `ShapeWriter::title()` was handed
  the title as a string and wrote it as one run, so `title: Ops **runbook**`
  reached the slide as asterisks. After: the title goes through the same inline
  pipeline as any other text. `docProps/core.xml` is the exception and is
  deliberately so: `dc:title` is a metadata string with nothing on the other end
  to interpret emphasis, so it carries the stripped text.
- **`***x***` is one run, and `__x__`, `_x_` and `~~x~~` are parsed at all.**
  Before: four constructs — `` `code` ``, `**bold**`, `*italic*` and `[text](url)`.
  The italic pattern matched the inner `**` of a `***bold italic***` before the bold
  pattern could, so it came out as `"*"`, `"bolditalic"` and `"* here"`: the words
  ran together and the delimiters were printed on the slide. `__bold__`, `_italic_`
  and `~~strike~~` were not in the grammar at all and reached the slide as the
  characters they were written as. After: each three-character delimiter is
  matched before the two-character one it starts with, the other three are
  parsed, and `Run` carries a `strike`, which the writer puts on the run's font
  as `strike="sngStrike"`. Two rules are kept deliberately: the asterisks and
  underscores inside a code span are characters, and an underscore between two
  word characters is not a delimiter, so `snake_case_name` is a name. Unclosed
  markup still renders as the characters it was written as.
- **A blank layout draws the title its frontmatter names.** Before: `layout:
  blank` has no title box and a `title:` was never pushed into the body, so a
  slide that named one drew nothing it was named after — the fifth instance of
  the same species as `author:` and `level:` until they were fixed, a directive
  accepted and producing nothing. After: a blank slide with no heading to be its
  title draws the frontmatter title as a heading, at the depth `level:` names,
  which is exactly what the role already does with a heading it wrote itself. It
  is drawn rather than refused because the alternative is refusing a deck for
  the one thing the role does draw well; the shape is named `heading`, because
  that is the block it became.
- **A quote written over several lines is one shape.** Before: every `>` line
  flushed the buffers and became a `Quote` of its own, so a sentence written
  across two lines came out as two shapes with two indents, two gaps and two line
  heights between them — while the list right above it joined its own
  continuation lines. After: consecutive `>` lines are collected into one quote
  and joined with the line break the deck wrote, the way a paragraph joins its
  own lines. A blank line still ends the quote, and a block after it still comes
  after it.
- **An image's alt text is its alt text.** Before: the `![…]` was parsed and
  stored on `Block\Image` and read by nothing — `ShapeWriter` wrote the *file
  path* into `descr` instead, which is what a screen reader reads out and what
  PowerPoint's alt-text pane shows. After: `descr` carries the alt text, and the
  written path only where the deck named none, because an empty description says
  nothing at all. Nothing in the file records where the deck found the picture:
  the media part carries its bytes under a name of the writer's, and the slide's
  relationships name no image at all, so the alt text is the only description of
  it that reaches the file.
- **Four spaces of indent is one level, not two.** Before: `depth()` was
  `intdiv(indent, 2) + 1`, counted from column zero, so a sub-list written `-
  one` / `    - deep` — a tab's width, and what a model writes — came out at
  level three, skipping the level below the item it belongs to. After: a level
  per step of indent, the way CommonMark reads it, with the levels open above
  an item tracked as they open and close, so an item that comes back out of two
  levels lands on the level it was written at.
- **A list keeps the marker each of its items was written with.** Before:
  `ListBlock::$ordered` was one flag for the whole block, taken from the item the list
  started with, so `- a` / `1. b` / `- c` came out as three bullets. After: the
  flag is per item, and an item is numbered exactly when the marker it was
  written with is a number — which is the same question its `start` answers, so
  one capture answers both and a bullet never carries one.
- **An ordered list starts at the number it is written with.** Before: the
  number was captured into a variable nothing read and the writer never set
  `startAt`, so `3. three` was numbered `1.`. After: every item carries the
  number it was written with, the item that begins a numbering writes it as
  `startAt` and the rest carry on from it. The item below a change of level
  begins a numbering again — PHPPresentation ends one when a paragraph at a
  deeper level interrupts it, so `2. two` after a sub-list is the only thing that
  reads as two — and every paragraph is given the level the writer numbers at,
  without which a numbered sub-list carries on from the list above it and comes
  out as `2.` where the deck wrote `1.`. A bulleted item carries no number.
- **A table cell carries its inline markup.** Before: `BlockParser` handed the
  writer trimmed strings and `ShapeWriter` called `createTextRun()` on each one,
  so a cell could carry neither bold nor a link however its shape was written —
  `| **Name** | [lk](https://example.com) |` reached the slide as `**Name**` and
  `[lk](https://example.com)`. After: cells are parsed like any other text and
  drawn by the same `ShapeWriter::runs()` a paragraph goes through, so bold,
  italic, code, links, sizes and colours all apply. Measurement follows the runs
  too, through a new `ShapeWriter::pieces()` that keeps `TextMetric`'s
  `{text, size, mono, bold}` contract: a header row is measured bold because it
  is drawn bold, and a code span in a cell is measured at the code size and in
  the mono face because it is drawn at them. "Cells keep their inline runs" is
  now true of `Block\Table`, so it is back on the class and off the dead-claims
  list in `tests/Unit/dead-code.php`; the two tests that asserted the cells were
  plain strings assert the runs instead.

### Notes

- `accent1` is still written as `a:srgbClr` and not as an `a:schemeClr` naming the slot, so
  re-theming a deck in PowerPoint changes the swatches its colour picker offers and does not
  restyle the bullets, rules and text already on the slides. This is not fixable here:
  `AbstractDecoratorWriter::writeColor()` writes `a:srgbClr` for every `Color` it is given,
  `SchemeColor` included, and it is what writes a run's colour, a fill and a bullet's. The
  only place the library honours a `SchemeColor` is the master's `p:txStyles`, which applies
  to placeholder shapes, and none of these shapes are placeholders. The claim in the README
  that `accent1` means the theme's accent "everywhere in the file" was therefore false and
  has been corrected. What a dark theme needed was therefore not a `SchemeColor` but a
  resolution step of its own, which is what `Theme::page()`, `ink()`, `subtleInk()` and
  `panel()` are.
- A table's cell borders are the one colour on a slide that is not chosen from the theme:
  PHPPresentation's `Shape\Table\Cell` writes `<a:lnL>` and its siblings as literal black,
  and nothing here asks it for anything else. Black reads as a grid on a light page and as
  a 1.07:1 line on a dark one, so a dark theme's tables are gridded by a colour it cannot
  choose. Changing it for a dark theme only would be a second rule with no slot behind it,
  and changing it for every theme is a different feature from the one in this branch.
- All twelve colour slots are written whether a shape uses them or not, which was not a
  choice: DrawingML's `CT_ColorScheme` is twelve required elements, so a scheme with five of
  them is an invalid theme part. `accent2`–`accent6` and `folHlink` reach the file because
  the format has no way to leave them out.
- Six tests were rewritten because they could not fail. `usesColour('#89b4fa')`
  under `corporate-dark`, where `accent1` *is* `hlink`, is satisfied by a deck with one
  bullet and no link — and by a deck with nothing but a rule; `round($w / $h) === 4.0`
  passes on `w = -1536, h = -384` as happily as on positive values, which is how a negative
  image geometry went unnoticed. Every one of them now asserts the numbers, or the element
  that carries them, so a wrong value has nowhere to hide. `Block\Table`'s claim that its
  cells keep their inline runs was false and is gone; its phrase is in `dead-code.php` so it
  cannot come back.

- An unknown theme name, an unknown layout role, an unknown `styles.yml` slot, a
  frontmatter key nobody acts on, a `v-click` step that does not go forwards, a
  frontmatter block that is not a mapping, a `title` that is not a string, a slide
  opening with a fenced `yaml` block, an image that is not a file, and an image no
  `.pptx` can carry are all refused with a message naming what would have worked. A
  silent fallback produces a broken deck with no feedback anywhere, which is the
  failure this library exists to avoid.
- A comment after a slide's last line is still that slide's speaker note, which is
  what the dialect has always said it is. A directive written in that position —
  `<!-- v-if false -->` as the last thing on a slide — is therefore read as a note,
  because nothing in the grammar tells a note from a directive there. Refusing it
  would take the note feature with it; Slidev resolves the same ambiguity the same
  way.
- A `<!-- v-click -->` after the words of a list item is no longer drawn as text: it
  is not a step, so it is refused like any other comment. The README's line saying
  such a marker "is not honoured" now describes a refusal rather than literal text,
  and wants rewording.
- Every slide's frontmatter is checked before anything is built, so a deck whose
  fourth slide names something nobody reads fails before it has written a byte
  rather than leaving half a file where a whole one was expected.
- `styles.yml` values pass through as they are written: a reference to a theme token
  — `{accent1}`, `{fonts.mono}` — is not resolved yet. The renderer does not read
  `styles.yml` at all: its slots are the Word half's vocabulary and the shipped
  themes write `.docx` style ids, which a `.pptx` has no use for. A slot that does
  not exist still fails when the theme is loaded, so a typo is never rendered as
  silence.
- A theme's `template.pptx` is recorded and not opened. Taking a role's
  placeholders from one is the same job `layouts.yml` already does by naming them,
  and no shipped theme ships one to take them from.
- Code blocks are not coloured. Nothing here lexes a language, and a fence's info
  string is kept only so a reader unzipping the file can see which blocks named
  which language.
- `phpoffice/phppresentation` is pinned to a commit of `dev-master` rather than to a
  tag. A floating branch reference would let a deck's bytes change with no commit
  here, and the library's releases trail its `master`.