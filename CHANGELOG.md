# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.2] - 2026-10-26

### Changed

- **The generic directives extension is 0.3.0.** Two of its fixes reach this library: a
  container nested inside another one through a blockquote could not be closed, and the rest
  of the document was swallowed as its body; and an attribute whose name was all digits was
  renamed, because PHP casts a numeric-string array key to an integer and the merge
  renumbered those — `{12=twelve}` came back out as `data-0="twelve"`. The extension also
  writes an author's attributes under the names they gave them now, where it wrote them
  into the `data-` namespace before. This library does not see that: it draws directives
  from the tree rather than from the HTML.

## [0.2.1] - 2026-10-25

### Fixed

- **A `.pptx` can be read on Windows.** The reader asked the file system for the folder of a
  part of a package, and a file system answers with the separators of the file system: the
  folder of a part at the root of a package is named `\` there, and no part of a package is
  named by one. The reader therefore looked for `_rels/.rels` under a name no package has,
  found no presentation in the deck, and said that the file was not in the format it
  supports — "The file … is not in the format supported by class
  PhpOffice\PhpPresentation\Reader\PowerPoint2007" — which says the file is not a
  presentation rather than that the reader could not find the one inside it.

  The folder of a part is now found with the slashes of the package, which is what the names
  of the parts are made of. The fix is
  [PHPPresentation#1043](https://github.com/PHPOffice/PHPPresentation/pull/1043), and it is
  upstream's to merge; it is in the patch this library applies, so it is here.

### Changed

- **The patch carries the reader's fix as well.** The patch is the difference between the
  release a consumer can install and the branch this project is written against, and it now
  holds the three fixes this library needs from upstream rather than the two it held before.
  It is [the same file on the same branch][the-patch], at a new commit: the commit is what
  the URL names, and the checksum is of the file the commit holds.

## [0.2.0] - 2026-10-25

### Added

- **A `.pptx` can be read back into a deck.** `PowerPointToMarkdown` is the inverse of
  `Render\PresentationRenderer`: it takes a deck apart and writes it as the Markdown this
  library writes decks in, so a deck somebody else made can be edited as text and put back
  together in another design.

  What comes back is the text and the structure, and what does not is the design: the type
  sizes, the colours and the geometry live in the theme and the master, and neither
  travels to a new deck. A `.pptx` records what is on a slide, not how it was drawn. The
  README says in full what the round trip keeps and what it loses.

  The order is the order the slide lists its shapes in, which is the order they were put on
  it — a slide is a set of boxes rather than a flow, and the order the file records is the
  one claim about the reading order that the file itself makes. A deck that was made by
  dragging a column down keeps its columns in that order, where a reader that sorted by
  position would interleave the column that was moved with the one that was not.

### Changed

- **The PHPPresentation pin moved, and the library can be installed by the public.** The
  pin was a commit of upstream's `master` branch, which a consumer cannot install: a
  development version is refused unless the project that asks for it has set
  `minimum-stability` to `dev`, and a library cannot set that for them. It is now the
  `1.2.0` release, which is.

  The release is missing a few things this library needs — a table that says which row is
  its header, a shape that says how round its corners are, a run of text that says what it
  is, and a picture that says where it was read from — and they are applied to the
  installed package as a patch, by [`cweagans/composer-patches`][composer-patches]. The
  patch is [the difference between the release and upstream's branch][the-patch], which is
  upstream's own code: this library does not write the code it patches in, and the patch
  stops being needed when upstream releases the branch.

  **A project that installs this one has to allow the plugin**, which Composer only runs
  when the project says so. The README says what to add, and the library refuses to work
  rather than write a deck with the parts missing.

  **Nothing this library draws changes.** The patch carries upstream's branch whole, so
  what the library draws is what it drew before: the same tests pass, and the files the
  library writes are the same files. The suite is the evidence: it passes against the
  patched release, at 100% coverage and level 9 of static analysis.

  **The fix that has not been merged upstream yet is still not.** Two of upstream's
  defects are reported rather than worked around: a picture's path is never set, so asking
  a picture where it came from throws
  ([#1039](https://github.com/PHPOffice/PHPPresentation/issues/1039)), and a raster is
  re-encoded on the way out, so the bytes that come back are not the bytes that went in
  ([#1040](https://github.com/PHPOffice/PHPPresentation/issues/1040)). The patch carries
  the fix for both, and it is upstream's to merge:
  [#1041](https://github.com/PHPOffice/PHPPresentation/pull/1041) and
  [#1042](https://github.com/PHPOffice/PHPPresentation/pull/1042).

  The repository commits no lock file, so the lines in `composer.json` are the only
  statement of what a consumer is given.

### Fixed

- **The pictures are written beside the Markdown that names them.** The reference in
  the Markdown is relative to the Markdown, and the pictures went to the working
  directory of whatever process happened to be running: for any target outside it,
  which is every target a caller gives a path to, the link was broken and the
  picture was where nothing pointed at it. A relative media directory is now
  resolved against the directory of the Markdown it is written beside.

- **A cell keeps everything written into it.** Only the first block of a cell was
  read, so a cell with two paragraphs lost the second, and a cell whose paragraph
  carried a bullet came back empty — a bulleted paragraph is a list rather than a
  paragraph, and a list has no inline content of its own.

- **A list whose levels jump keeps every item.** The walk that reads the nested
  items ended when it came back to a level between the one it was reading and the
  one it had descended to, and everything from the jump onwards was dropped with
  nothing to say so.

- **The paragraphs of a title are read apart.** `getPlainText()` runs them
  together, so a title of two paragraphs came back as one line with the words of
  the two glued to each other, and a subtitle of two paragraphs came back as
  `A field guideto the North`. A title is one line in Markdown, so only its first
  paragraph is the heading and the rest are the blocks they are.

- **The alt text of a picture and the address of a link are escaped.** The alt
  text is a `descr` attribute of the picture, so it is whatever the author
  typed, and a bracket in it ended the reference early and a newline in it ended
  the line the reference was on, leaving the rest of it as prose.

- **A template without the part it needs is refused by name.** A template whose
  `ppt/presentation.xml` was missing raised `ValueError` from the XML parser rather than the
  named failure, because `loadXML()` refuses an empty source by raising rather than by returning
  false. It is now the same failure as a part that is not XML.

### Security

- **A picture over the ceiling is refused before it is opened.** The reader opens
  every picture in the deck as it reads it, and a picture is allocated by the
  canvas its header declares rather than by the bytes it is made of: a 1.2 MB PNG
  that says 40000 by 40000 is 1.6 billion pixels, and GD's truecolor is four
  bytes a pixel, so decoding it asks for 6.4 GB. PHP's `memory_limit` does not
  contain it, because the allocation is GD's and is outside the heap PHP tracks,
  so the process is killed rather than the deck refused. The pictures in a deck
  are now measured from their headers, without decoding them, and a deck holding
  one over the ceiling is refused. The ceiling is the one the forward direction
  writes pictures under.

- **A list may not nest without end.** Reading a list recurses once per level,
  and the level is whatever the deck said it was, so a shape whose paragraphs sit
  at ever deeper levels is a shape the walk never comes back from — and the walk
  does not return an error, it runs out of stack and the process dies on a signal.

- **A part of a theme's template is read under a ceiling.** A zip says how its contents are
  laid out and nothing about how much room they will take up: a part of a few kilobytes can
  inflate to hundreds of megabytes, and the document built from it costs several times that
  again in libxml's memory, which PHP's `memory_limit` does not see. The parts of a template
  are now read through a stream and abandoned at four megabytes, and the size is what the part
  really holds rather than what the archive claims, so a part that lies about itself is caught
  too. A template with a part over the ceiling is refused by name instead of ending the render
  in a fatal error that names neither the theme nor the part.

## [0.1.0] - 2026-10-19

### Added

- **A shadow on every free shape.** Five attributes — `shadow-color`, `shadow-distance`,
  `shadow-blur`, `shadow-direction` and `shadow-alpha` — on all twelve directives, written as
  `<a:effectLst>`. Distance and blur are **pixels**, not points: a shadow is a size on the slide
  and the slide is measured in pixels, which is what PHPPresentation takes and so nothing is
  converted. The direction is degrees and is wrapped the way `rotate` is, because there is no
  x/y pair to place a shadow with and an angle is the only way to put one off-axis.

  One attribute is enough, and a shape that names none of the five is written with no effect
  list at all rather than with one that says the shape has no shadow. A `label` takes the
  shadow of the shape it sits in, `:::content` casts one from its own border, and `::image`
  casts one because a `p:pic` carries an effect list of its own.

  `examples/markdown/14-shadows.md` is five shadow types on five kinds of object.

  Every claim the gallery makes about that deck and about `13-placed-deck.md` is now checked
  against a real conversion, which is what `tests/Readme/examples-decks.php` exists to do and
  what it was not doing for either of them.

- **A `pitch` theme, and an example deck built out of free shapes.**
  Near-black and a gold on white with one blue for anything a reader is meant to act on — the
  palette a proposal wants and a template rarely has. `examples/markdown/13-placed-deck.md` is a
  fictional bicycle frame maker positioned entirely by the deck: a dark cover and a dark sign-off
  over a full-bleed `layer=back` rectangle, an agenda of numbered circles, a card grid, a pricing
  table built row by row, a four-pillar comparison and a quarterly roadmap. It is the first
  example whose subject is free placement rather than the vocabulary, and the company is invented
  because the deck it was rebuilt from was nobody's to publish.

- **Twelve directives that place a shape themselves, so one slide can be styled freely.**
  `::rectangle`, `::roundrect`, `::ellipse`, `::circle`, `::triangle`, `::diamond`,
  `::chevron`, `::hexagon`, `::arrow`, `::line`, `::image` and `:::content` … `:::` are
  written in the body of a slide, in the same two-colon syntax the step markers use:

  ```markdown
  ::rectangle{left=0 top=30% width=100% height=64% fill=panel layer=back}

  :::content{left=8% top=47% width=52% height=42% font-size=15}
  ## Fill, stroke, alpha
  :::
  ```

  Everything above this is laid out for you. A free shape is placed by the deck, in pixels or
  in fractions of the slide, and coloured in the theme's own slots so it survives a theme
  swap. Three decisions are worth knowing:

  - **A box lies within the slide, and one that does not is refused with the numbers.**
    Clamping it would be a shape quietly drawn somewhere the author did not ask for, and
    nothing in the file would say so. `left=0 width=100%` is exactly the edge, so full bleed
    is still one spelling.
  - **An attribute a directive does not read is refused rather than ignored**, and the failure
    names the attributes that one does. An ignored attribute is the one mistake in a
    vocabulary like this that leaves no trace at all: a shape drawn without the fill that was
    asked for, on a deck that looks rendered.
  - **A placed text block is one shape.** It holds the blocks a text frame holds anywhere
    else — paragraphs, headings, lists, quotes — and a table, a code block, a picture or a
    rule inside one is refused by name. A shape's id in the file is its position in the
    slide's shape collection, and the renderer counts them rather than looking them up, so a
    body that could produce two shapes would put the click chain and the file out of step.

  `layer=back` writes a shape before the layout's own and `layer=front` after, so a panel
  behind a slide's text and a badge over it are the same attribute with two values. A free
  shape sits on the step it was written on, so a `::click` above one reveals it like any
  other. Text that does not fit is clamped and reported by `overflows()`, with no column
  behind the entry — there is no column, and putting `0` there would be a number in front of
  nothing.

  Two things are **not** offered and are refused by name rather than approximated: `fit=cover`,
  which is a crop rather than a fit and has nowhere to go in the shape PHPPresentation
  writes; and a dashed outline, because an autoshape's stroke is one colour and one width.
  A label in brackets is refused too — a free shape has nowhere to put one.

  `MarkerStartParser` now stands aside for a `:::` container whose name it draws, which is
  what lets the generic directives extension parse `:::content` as a container with a body
  rather than have the marker parser take every three-colon line. Everything a `:::click`
  region does is unchanged.

- **A fourth theme, `dense`, and a deck that exists to be looked at twice.**
  `themes/corporate-dark` is the one that was larger than the library's own scale; nothing
  shipped that was smaller, and a spacing model that is a fraction of a type size is only
  demonstrable at two of them. `dense` is the whole scale down at once — a 13pt body on a 28px
  margin — and `examples/markdown/11-type-scale.md` is built twice, once per theme, so the
  same words at two sizes can be put side by side. The air between its blocks follows: 8px
  after a paragraph rather than 12, and a heading's air above it follows whatever that
  theme's `headings` scale says.

- **A slide is read by league/commonmark.**
  `Render\CommonMark\BlockTranslator` reads a slide with
  [league/commonmark](https://commonmark.thephpleague.com/) plus
  [markdown-office/commonmark-generic-directives](https://github.com/markdown-office/commonmark-generic-directives)
  and is what `PresentationRenderer` parses with. `league/commonmark` does not include GFM tables
  and the shipped themes' column widths are measured from a table's alignment, so
  `TableExtension` is registered rather than optional; `StrikethroughExtension` is registered for
  the same reason (`~~struck~~` is a run of its own).

  It arrived in two steps. The first added it beside the parser it replaces, with
  `tests/Unit/commonmark-parity.php` holding the two together over every slide of every deck in
  the repository and every string literal the suite itself holds, and every place they disagreed
  recorded in `tests/fixtures/commonmark/divergences.json` with the reason the other answer was
  the right one — sixty-nine of them. Both directions were asserted: a difference nobody recorded
  is a behaviour change nobody decided on, and a record whose deck stopped differing is a
  strictness that went quietly. Sixty-three of the sixty-nine were on test-source strings rather
  than on a deck; the six that reached a slide are the table under **Changed**.

  The second step is the cutover above, and it took the differential test with it: there is one
  reader now, and "do the two agree" is not a question the repository can ask any more. What
  replaced it is coverage of the constructs themselves — an indented marker read as code, an HTML
  block read as its words, a lazy continuation, a padded table row, a scheme a reader would act
  on — each asserted where it is decided.

- **A gallery slide for a `::column` written inside a `:::click` region.** `examples/markdown/05-steps.md`
  had a region on one slide and column markers on two others, and never both on the same one — so
  the case a reader most needs to see is the one the gallery was silent about: text after a
  region's closing `:::` stays in the column the marker inside the region moved it to. The new
  slide is after the two-column build-up, which is where both halves have already been introduced.

  The build has six clamps before the slide and six after it, and `examples/README.md` names it
  beside the `02` two-column slide that says where a column divides.

- **`TimingPass`, which writes a click-triggered build onto a slide. Nothing calls it yet.**
  No deck a person writes changes, and a `v-click` step is still a slide of its own. It is here
  because the markup is intricate enough not to be written twice, and because the renderer
  change that uses it rewrites a loop another branch is already restructuring.

  A step used to be a slide, so a build of twenty steps was twenty copies of one slide in the
  package. PowerPoint builds a slide up without that, naming the shapes that appear at each
  click in a `<p:timing>` element on the one slide. The pass writes that, and reproduces
  PowerPoint's own bytes for a step of one shape and for a step of several.

  Its facts were read off `tests/fixtures/powerpoint/entrance-animation.pptx`, a deck
  PowerPoint 16.113.4 authored and saved, because markup written from a description of the
  format is not evidence that PowerPoint reads it back:

  | | What PowerPoint writes |
  | --- | --- |
  | a step | one `<p:par>` whose `<p:cond delay="indefinite"/>` waits for a click |
  | a shape in that step | one `<p:par>` inside it — `nodeType="clickEffect"` opens the click, `nodeType="withEffect"` joins the one already open |
  | a shape | its own id, in `<p:spTgt spid>` and in `<p:bldLst>` |
  | the effect | `presetID="1" presetClass="entr"`: `style.visibility` set to `visible`, nothing moving |

  The second row is the one the markup cannot show: `clickEffect` and `withEffect` are
  otherwise identical, so a build whose second bullet waits for a second click reads correctly
  and behaves wrongly.

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

### Changed

- **The package is now `markdown-office/markdown-presentation`.** The repository moved into the
  `markdown-office` organisation, and every other package in the suite is already named for it —
  `markdown-office/markdown-word`, `markdown-office/commonmark-generic-directives`. A Composer
  name is not derived from a repository, so it does not move on its own, and a name left behind
  under the old vendor is the one entry in the suite that does not say where it lives.

  ```sh
  composer require markdown-office/markdown-presentation
  ```

  Nothing has to be aliased and nothing has to keep working: the old name was never on Packagist,
  so there is no release under it and no consumer to migrate. The only line that changes is the
  one in your `composer.json`.

- **The free-shape reading moved out of `BlockTranslator`, and a shape is no longer read by
  the thing that reads blocks.** A free shape is not a block — it takes no column, is not
  measured against the layout's box, and there is nothing for a walk to walk over — so
  `Render\Free\ShapeReader` reads it onto the slide's state and the translator is a translator
  again, at sixteen methods where it was twenty-four.

  Two readings both callers needed are now one class each rather than a copy in each: the
  list walk is `Render\CommonMark\FrameReader`, because a column of type and the Markdown
  between a `:::content`'s fences want the same answer to what nesting depth a bullet is at;
  and the picture walk is `Render\CommonMark\PictureReader`, because `![…](x.png)` and
  `::image{src="x.png"}` must reach the filesystem through the *same* `PathScope` — one of
  them resolving a path on its own would be a deck able to point outside the directory it was
  given, written in a spelling nobody reads the tests for.

  `PresentationRenderer` is fifteen methods, where the count was twenty-one and had been
  nineteen before this branch. **All three write passes and the column arithmetic moved to
  `Render\SlideShapes`**, because they are one idea — the shapes a deck placed behind the
  layout's own, the layout's own, and the shapes it placed over them — and the order *is*
  `layer`: a later shape in `p:spTree` is drawn over an earlier one. Split across methods on
  the renderer, that reason was hidden; in one class it is the class.

  `Render\Overflows` is the report itself rather than an array on the renderer, because two
  places write to it and because strictness is a fact about the report rather than about the
  deck: the same measurement decides whether to clamp and say so, or to raise.

  `Render\Writes` — the slide, the writer and the slide's number, which are the same for every
  draw on a slide and were three parameters on every method here — and the title box, which is
  the one shape this class does not place itself and the only question the renderer still had to
  carry a `SlideGeometry` for.

- **`rotate` turns a free shape about the middle of its own box**, on all twelve — the box
  does not move and does not resize, so a rotated shape is still the box that was placed. A
  `:::content` box is a shape, so it turns too, which is how a label is turned *with* the arrow
  it sits on. Whole degrees only, because `a:xfrm rot` is in sixtieths of a degree and the
  setter is an `int` and a fraction would be a `TypeError` rather than a rounded angle. Negative
  and past a turn are wrapped to a single turn rather than refused: `rotate=-12` is a
  counter-clockwise tilt, and wrapping is what bounds the number, since an unbounded one
  overflows the multiplication into a `TypeError` on a deck nobody can act on. A full turn
  writes no `rot` at all rather than `rot="0"`.

  This is also what narrows one of the promises above: a free shape lies within the slide, but
  that is a claim about its **box**, and a square turned forty-five degrees reaches past the
  edge of the slide while its box stays inside it. What is checked is what the deck placed.

- **`label` puts words inside one of the ten ink shapes, as one shape rather than two.**
  `::arrow{label="Retention 94%"}` is a single `<p:sp>` carrying both the geometry and the
  text, which is the native thing: double-click the arrow in PowerPoint and the cursor is in
  the arrow, and dragging the arrow drags the words with it. It turns with the shape without a
  second `rotate` to keep in step.

  What it costs is everything a `:::content` box can do and this cannot. PHPPresentation writes
  `<a:r><a:t>…</a:t></a:r>` with **no `a:rPr`**, so the words are drawn in `tx1` — the theme's
  body text colour, which follows `dark:` — at whatever size that defaults to, centred on both
  axes because `algn="ctr"` and `anchor="ctr"` are written into the element rather than read
  from anything, and with no Markdown, so `**bold**` is six literal asterisks. The ink
  contrasts with the **page**, not with the shape's own fill: a label is legible on a filled
  shape in either theme, and what washes out is a fill close to the page's own tone.

  So there are two ways to put words on a shape, and they answer different questions. `label`
  is one object you can double-click and drag, with words you cannot style. `:::content` over
  the shape is styled Markdown, and two objects that PowerPoint edits as two. A `label` is
  refused on `::image`, which is a `p:pic` with no text body at all, and on `:::content`,
  whose body is already its text — putting the same words in the same place twice is the
  mistake both refusals are about.

- **A placed picture is fitted to its box by the same call a picture in a column uses.**
  `AbstractGraphic::setWidth()` re-derives the height from the picture's ratio and
  `setHeight()` then re-derives the width from *that*, so placing with two calls compounds
  the ratio: a 2:1 picture in a square box came back at twice the width. It is invisible for a
  picture whose ratio happens to match its box, which is why only some pictures came out wrong
  and a test on the rest would have passed. `fit=stretch` was never affected — it turns the
  ratio off first — and `contain`, the default, was.

- **`UnknownDirective::container()` and `::leaf()` now name the free shapes**, because
  `::content` and `::rectangle` are the two names an author who has met this library is most
  likely to reach for, and a refusal that does not mention them reads as a refusal of the
  whole idea rather than of the spelling. Both messages also say what a placed text block
  looks like, which is the one thing a `:::` line can be that is not a step region.

- **`BlockTranslator::parse()` takes the theme**, and `SlideState` carries it. A free shape may
  be written in the theme's colours, so `fill=panel` is a reference that needs the theme a deck
  named — and an attribute nobody reads has to be refused before anything is built, which is
  before there is a shape to hand a colour to.

- **`SlideBlocks` carries a fourth list, the shapes the deck placed itself**, beside the
  blocks and the columns. They are not blocks: a free shape is placed rather than laid out,
  takes no column, and is not measured against the layout's box.

- **`PresentationRenderer::overflows()` reports a `null` column** for a block that is in no
  column, which today means a free text block. Saying `column 0` about one would be a number
  in front of nothing.

- **Public methods removed from `Render\SlideGeometry`, `Render\TextFrame` and
  `Render\ShapeWriter`.** All of them went because the behaviour they served went:

  | Removed | Replaced by |
  | --- | --- |
  | `SlideGeometry::paragraphSpace(): float` | the `block:` theme value, which is where the gap is decided now |
  | `SlideGeometry::headingHeight(int $level, int $lines): int` | `headingSize()` plus the geometry that reserves the room |
  | `TextFrame::paragraphs(): array` | `blocks()`, `leading()` and `trailing()` |
  | `ShapeWriter::height()` and `ShapeWriter::write()` narrowing | the parameter union is now `Code\|TextFrame\|Image\|Rule\|Table` |

  The last is the one that can break a caller rather than warn one: passing a `Heading` or a
  `Quote` to either method is now a `TypeError` rather than a shape. Both are paragraphs of a
  `TextFrame` — which is where they were being written anyway — so a caller holding one should
  wrap it.

, and a heading and a quote
  are paragraphs of the frame around them rather than shapes of their own.**
  `block:` was the gap between two blocks everywhere, which is one number doing the work of
  a rhythm. It is still the gap after a paragraph, and everything else is a fraction of it or
  a function of its own type:

  | Between | What decides it | At the library's own numbers |
  | --- | --- | --- |
  | two paragraphs | `block:` | 12px, unchanged |
  | two lists | a quarter of `block:` a side | 6px |
  | a paragraph and a table, code block, rule or picture | 0.9 of `block:` a side | 17px |
  | a heading and the text above it | 0.7 em of the heading's own size, plus the 6px the paragraph leaves | 19px + 6px = 25px |
  | a heading and the text below it | 0.35 em of the heading's size, plus the 6px the paragraph leaves | 9px + 6px = 15px |

  The 6px is between two **lists**. The items of one list are lines of one frame and are set with
  no gap between them, and the heading rows are the heading's own air *plus* the paragraph's: at
  a 20pt `##` the heading asks for 19px above and 9px below, and the reader sees 25px and 15px.

  Two things in that table are the reason for it. A block with an edge of its own takes more
  of the air than a paragraph, because a paragraph's ink stops short of the bottom of its box
  — by the text inset, and by the slack in the last line's leading — while a table's border is
  drawn exactly on the edge, so one number read as generous between two sentences and cramped
  above a table. And a heading's air follows the heading's own size rather than a body's,
  because a 34-point heading under the same air as a 14-point one is either crowded or
  marooned; it is more above than below, for the same reason markdown-word writes a heading's
  `space before` at twice its `space after`.

  A heading is now a paragraph of the frame around it, carrying its own size, weight, face and
  ink, and a quote is one carrying an indent, italic and the quiet ink — which is what each
  already was in the file. A slide whose body is text and nothing else is now *one* box
  rather than a box per block, so the height is measured once and `normAutofit` stands behind
  all of it, and a deck that was a page of headings and quotes is no longer a page of boxes a
  reader cannot move one of at a time. A rule, a table, a picture and a code block are still
  shapes of their own: they are not type, or they are type with something painted behind them,
  and a fill over part of a text frame is not something the format can express.

  The gap between two paragraphs is written as a half at each end — `a:spcAft` under one and
  `a:spcBef` over the other — because PowerPoint adds a paragraph's spacing before and after
  rather than taking the larger of the two, and a gap carried entirely on one side reads as a
  bigger gap above a paragraph than below it. The two halves add back up to what a deck got
  before.

- **A title is a box that no longer grows into the one below it.**
  The title band asked for two lines of the role's own title size and the body started at a
  fixed band, and nothing compared the two: at the library's own numbers the title box ended
  ten pixels *below* the top of the body box, so a title that wrapped onto a second line put
  its second line on the first line of the body. It is clamped to the room between the two
  now, and `normAutofit` shrinks a long title to fit rather than the body moving down with it
  — a slide's body is read in the same place on every slide, which is why the title gives way.
  A one-line title is unchanged and looks identical.

- **A slide is read by league/commonmark, and this library's own block parser is gone.**
  The two agreed on 2 691 of the 2 760 slide contents the differential test compared, and this
  is the other side of that bargain: the differences below are now the behaviour rather than a
  recorded note. `Render\BlockParser` and `Render\InlineParser` are removed, and
  `Render\CommonMark\BlockTranslator` is what `PresentationRenderer` parses with.

  Nothing about how a deck is *written* changes. `::click`, `::click=3`, `::column`,
  `:::click` … `:::` and `::note` … `::` are all still read, because the markers are claimed in
  front of the directive grammar and `::note` is taken off the slide by `DeckParser` before the
  renderer sees it.

  The differences that reach a slide, each of which is the other parser being wrong:

  | Was | Now |
  | --- | --- |
  | a line of text after a list item ended the list | it is a lazy continuation of that item, which is what CommonMark says |
  | a change of marker carried on one list, with `ordered` per item | it starts a new list |
  | a sub-item two spaces under `1.` nested | a sub-item needs the parent marker's width, three spaces for `1. ` |
  | `**unclosed` paired with a later `**` and drew bold | the `**` stays the characters it is |
  | `[**label**](…)` drew a pair of asterisks | the emphasis and the link both survive |
  | `[](…)` drew an empty run carrying the target | an empty label is nothing to draw |
  | a link pattern stopped at the first `)` | `javascript:alert(1)` is refused whole |
  | a short table row was left short | it is padded with an empty cell, so rows line up |

- **A `\\host\share` link is refused again.** league percent-encodes a target on the way in, so
  the share arrived as `%5Chost%5Cshare` and a check written against the raw string saw a path.
  The check now reads the decoded form, which also catches an encoded scheme, and it refuses any
  target beginning with a path separator — one separator, because league reduces `\\host` to a
  single one on the way in. A picture's source is checked for a scheme but not for a share: it is
  resolved on disk inside the directories the caller allowed rather than followed by a reader.

- **`Render\CommonMark\BlockTranslator::MAX_STEP`** is where the ceiling on a step number lives,
  where `Render\BlockParser::MAX_STEP` was. The number is unchanged.

- **A leaf directive under a name this library does not draw is refused rather than drawn.**
  **Breaking**, and only for a deck that writes one. `::foo`, `::foo[label]{attrs}` and
  `::anything=2` were painted onto the slide as the words the deck wrote them as, which is the
  outcome the comment, the element and the container refusals were written to stop: the deck
  renders, and what it renders is the mistake.

  | Was | Now |
  | --- | --- |
  | `::bogus` became a paragraph reading `::bogus` | it is refused, naming `::click` and `::column` as the two a leaf may carry |

  It was missed rather than allowed, because those three refusals each look for one spelling and
  a leaf is identified by a name, so nothing was looking for `::name` at all. A `::note` carrying
  a label or attributes is refused the same way, since a note is `::note`, its text and a closing
  `::` rather than a leaf. A directive written inside a quote or a list item is still drawn as the
  text it is, exactly as `::click` already was in that position.

- **A `::column` written inside a `:::click` region moves the text after the closing `:::`.**
  **Breaking**, and only for a deck that writes both on one slide. A column is where a block is
  drawn and a region is when it appears, so a region governs what a click reveals and not where
  the text sits.

  | Was | Now |
  | --- | --- |
  | the column counter died with the region's own walk | the region's walk hands back the column it ended at |
  | the text after the closer went back to the column the region opened in | it is in the column the marker inside the region moved it to |

  The step does not travel with it, and that asymmetry is deliberate: a region's own steps are its
  own, so the text after the closing `:::` stays on the step the region opened at. A `::click` inside
  a region still moves the one step counter of the slide, exactly as before. The markers a slide
  wrote are still counted as the walk reaches them, so two `::column` markers inside one region on a
  two-column slide are refused with `UnsupportedColumn` as the third marker at the top level is.

- **Every two-colon marker is recognised in either case.** A `::Click`, `::Column`, `:::Click` or
  `::Note` written with a capital is the marker it looks like rather than a paragraph reading it on
  the slide. A deck written by a model capitalises a directive as readily as it writes it in lower
  case, and a marker drawn on the slide is one its author thought they had read. `::Note` is taken off
  the slide as a speaker note in either case, so it cannot be refused as a note nothing closes.

  The refusal for a `:::` that is neither a region nor its closer is spelled with a lookahead, and it
  folds the same way: `:::Click` opens a region rather than falling through to the container refusal,
  while `:::Column` is still refused as the container it is. A marker inside a fenced block is still a
  line of code.

- **`::column` moves the blocks after it into the next column.** **Breaking.** A two-column
  slide no longer divides at its first `##`, because that is no longer how a column is
  reached.

  | Was | Now |
  | --- | --- |
  | `## Anything` divided a `two-column` or `image-text` slide in half | `::column` on a line of its own does |
  | a `##` stayed in the first column when the slide had more than two | a `##` is an ordinary heading, in whichever column it is written |
  | one `##` per slide: a second one was just another heading | one marker per column: a second one moves to the next |
  | a role with one column ignored the `##` and drew everything in one column | refused, naming the role and how many columns it has |

  The marker takes no argument. It means "the next column", which is the one direction a
  block can move in, and it moves no step: a build-up written across two columns spends the
  same clicks it would on one column. `::click` and `::column` share two colons and neither
  is a prefix of the other, so the pair is checked in the suite in both directions rather
  than reasoned about. `::column=2` is a failure under `UnknownMarker` naming the marker that
  would have worked, rather than a paragraph reading `::column=2` drawn on the slide.

  A column nothing was written in is still not an error. The alternative is guessing which
  block was meant to be in it, which is the guess this library refuses everywhere else; a
  deck that asked for a layout with columns and wrote one of them has said what it wants.
  Whether there was a block after the marker does not enter into it: the marker is measured
  by what the deck wrote and by the role it was written for, so a deck's spelling cannot
  depend on its last line.

  **A `::column` on a role with fewer columns than the deck asks for is a new failure**,
  `Exception\UnsupportedColumn`, and it extends `InvalidInput`: the marker is well formed and
  the slide is what cannot offer the column, which is the same shape as `UnsupportedLink` and
  `UnsupportedImageFormat` rather than `UnknownMarker`. The message names the slide, the role
  and how many columns it has. One refusal covers every way of hitting it — a marker on a
  one-column role, a third marker on a two-column slide, and either of those written at the
  end of a slide where nothing follows.

  The column travels out of the parser as `Render\SlideBlocks`, the blocks and the column
  each was written for in two lists of one length, with the count of markers beside them,
  rather than a property on every block class. `Render\PresentationRenderer::columns()`
  measures the layout against that count rather than against the column a block landed in,
  and `Render\BlockParser::parse()` returns that object.

- **A step is an animation on one slide, not a slide of its own.** **Breaking.** A deck using
  `::click` now contains fewer slides: the markdown is unchanged and the file is a build-up
  rather than a run of copies of the same slide.

  | Was | Now |
  | --- | --- |
  | one `.pptx` part per step, each showing every step before it | one part holding every block, with a `<p:timing>` click chain |
  | `slide 2` is step 2 of source slide 1 | `slide 1` is source slide 1 |
  | `::click=3` with nothing on step 2 wrote three slides, two of them identical | one slide, one click |
  | `overflows()` reported every step a build-up was drawn at | the step of the block that did not fit |

  The entry effect is `appear` — a `<p:set>` of `style.visibility` and nothing else — so what
  a reader sees is a shape that was not there and then is, with nothing moving and nothing
  fading. The markup was written from what PowerPoint writes rather than from a description of
  the format: `tests/fixtures/powerpoint/entrance-animation.pptx` is a deck PowerPoint itself
  authored, and `Render\TimingPass` reproduces its `<p:timing>` byte for byte.

  **The layout is the finished slide**, which is what the last step of a build-up used to look
  like — the strictest of the drawings the library made of it. Overflow is therefore unchanged
  in strictness and halved in count, and a block that fitted any step still fits.

  A run of text either side of a marker is now **two text frames** rather than one. A frame is
  one shape and a shape is revealed whole, so a frame holding two steps' paragraphs would put
  the second click's text on the slide at the first. Nothing moves: the gap between two frames
  is the gap between two paragraphs of a frame, which is the same number either way, and the
  suite asserts that the second paragraph starts at the same height in both.

  The title is written once, is not animated, and is in no step of the chain: it is on the
  slide before the reader clicks anything and never changes. Speaker notes lose nothing — a
  note was written to every step's slide, and there is one slide now.

  The first step names no branch, and a step nothing was written on names none either, so
  `::click=3` with content only on step three costs one click rather than three. That was a
  behaviour change in its own right: a step nothing was written on used to be written as a
  slide drawn exactly like the one before it, which is a click the reader spends to see no
  change.

  Both ceilings keep their numbers and stop bounding what they were written to bound.
  `BlockParser::MAX_STEP` is now what a deck is allowed to *say* — a step number is a position
  in a chain somebody clicks through, and a position in the billions is a deck written by
  something that did not mean it — and costs the renderer nothing to be generous with, where it
  used to bound two billion `.pptx` parts asked for by sixteen bytes of Markdown.
  `PresentationRenderer::MAX_SLIDES` is now the deck's own slide count, refused before the
  first slide is built; the deck of forty slides with twenty steps each that used to ask for
  800 written slides asks for 40.

- **Steps are `::click`, regions are `:::click`, and notes are `::note`.** **Breaking.** A deck
  written with `<!-- v-click -->` no longer builds: an HTML comment is now refused with a
  message naming the two spellings this format does have. Nothing else about a deck changes.

  | Was | Now |
  | --- | --- |
  | `<!-- v-click -->` on a line of its own | `::click` on a line of its own |
  | `<!-- v-click=3 -->` | `::click=3` |
  | — | `:::click` … `:::` — everything between is one step of its own |
  | an HTML comment after a slide's last line | `::note` … `::` |

  A region is new, and is what `BlockParser` refused with "honouring that here would mean
  splitting one list block into several, which is a different feature". It is that feature:
  the opener names its step through the same `jump()` a bare marker uses and the contents are
  read by the same walk, so there is one step counter and the two forms cannot drift. Two
  colons and three cannot collide — `::click` is not `:::click` in either direction — which
  is asserted rather than reasoned about in the suite.

  A region nothing closes, a `:::` with nothing to close, and a `::note` nothing closes are
  all failures naming the spelling that would have worked, rather than a deck drawn one step
  short or three colons printed on a slide.

  `Document\DeckParser::MARKER_BODY` is **gone**. It was the shape of a comment that is a
  step, and there is no comment that is a step any more. `Document\DeckParser::dialect()`
  answers differently too, and is a doctested example on the README.

  Notes are `::note` … `::` rather than a trailing comment for one reason: leaving them as
  comments would make "no HTML in this format" unsatisfiable. A note now has explicit fences,
  so `DeckParser` tracks it across lines the way it tracked a comment — a `---` or a fence
  inside one cannot split the deck — and two notes on one slide are one note carrying both,
  because a `.pptx` slide has one notes pane.

  **This is the one decision in the change the library did not force**, and it is a flag
  rather than a certainty: `::note` was chosen because a pair of colons reads as a pair of
  colons and nothing else does. `:::` was already three of them and now means a region
  closer, so a note that wanted the same shape would have had to share it.

  Six decks in `tests/fixtures/slidev/` now diverge from the real Slidev parser that did not
  before, and four new entries are in `divergences.json` with the reason. The recording is
  untouched: it is what Slidev did, and this is where the two differ.

- **`BlockParser::flush()` takes its four buffers as one array.** Six parameters become three, and
  the walk above them declares one local rather than four. The refactor is in the arguments rather
  than the behaviour: every block a slide ends up with, the column each was written for and the
  `SlideBlocks` invariant that keeps the two lists the same length are what they were, and the 847
  tests passing are the evidence.

  The reason is `php:S107`. `blocks()` is at seven parameters with the walk's own arguments — the
  lines, the slide, the directory, the scope, the step, the column and the marker count — and
  `flush()` is called from six branches of it, so a fourth buffer is a fifth parameter there. What
  the array buys is room for an extraction out of `blocks()` to be handed one argument instead of
  four, which is the reason `column()` still flushes by its caller.

  That reason changed, and so did its comment. The buffers are flushed by the caller because
  `column()` is static and `flush()` is not — closing a quote reads its runs through the inline
  parser — rather than because four buffers beside four arguments is eight parameters. The old
  claim is in `tests/Unit/dead-code.php` so it cannot come back.

  `BlockParser` is back at twenty methods and `php:S1448` is closed on it. The rule is above twenty,
  and the class has been there once already: the `::click` change took it to twenty-two and it had
  to be folded back. `spelling()` — the one method the quoting fix had added — has gone, and the
  sanitising it did now sits in `UnknownMarker::quoted()`, where the sentence is built: a
  deck-written string that is about to be printed belongs to whoever prints it, and the `@param`
  lines promising a caller had already applied it are gone rather than left as a promise every
  future caller has to keep. `blocks()`'s cognitive complexity is unchanged too, at exactly 15
  against a limit of 15 — the control-flow structure of the walk is the same sequence of tokens it
  was, so nothing in the refactor could have nested anything.

- **`PresentationRenderer` did three jobs.** Twenty-seven methods, of which six were neither
  parsing a deck nor drawing a slide:

  - **`save()` is `Render\DeckWriter::save()`.** One caller, the only method that touched
    `IOFactory`, and the only one that invoked the two passes over the written package.
    `PlaceholderPass` and `NotesColourMapPass` are two `final` classes in the same directory
    that exist to be exactly that shape, and this is the third instance of a pattern the
    directory established twice.
  - **`slideMaster`, `master`, `layouts`, `properties` and `text` are `Render\MasterLayouts`.**
    The first three were the sole users of seven PHPPresentation imports between them, and
    `text()` was a `private static` with three callers inside one method.

  Nineteen, six and one. `php:S1448` is closed on the renderer.

  Two of the seven did not fit the same seam and were folded instead. `checkRole()` had one
  caller three lines away and is now the branch it was; `opensSecondColumn()` was a
  one-line predicate with one caller and is now the condition it guards. Six moved out and
  two folded is twenty-one, which is still over the ceiling — the point is that each of the
  eight was a thing a reader could not have named from the file.

  `MAX_SLIDES` stays, and `$overflows` stays: both are the renderer's own state, reset and
  appended by `render()` and `overflow()` and read by the public `overflows()`. The sink
  travels with the methods that write it or it has to be passed in and back out again, which
  is worse than keeping it.

  Every line of both extractions was already asserted through the renderer — the notes
  colour map in `tests/Unit/notes-colour-map.php`, the layout placeholders in
  `tests/Unit/placeholders.php`, the page size in `tests/Unit/rendering.php` and the refusal
  for a path whose directory is not there in the same file — so this is a refactor with no
  new test, and 764 passing is the evidence it changed no behaviour.

- **`Theme` was one class doing two jobs.** Six of its twenty-three methods read a `theme.yml`
  and nothing else: `mapping`, `readDark`, `readColours`, `readFonts`, `text` and
  `colourNames`. They are now `Theme\ThemeMapping`, on the same seam `Style` already draws from
  `StyleMapping` — the bounds belong to the file and the value object never sees one. Seventeen
  and eight methods, and `php:S1448` is closed on the class.

  `ALIASES` is consulted by two callers and lives with the file rather than being copied: the
  accessor `colour()` asks `ThemeMapping::slot()`, because a caller may hold either spelling of
  a slot and that map is a fact about what a file may say. `COLOURS` and `FONTS` did **not**
  travel, deliberately: they name what `Theme::colours()` and `fonts()` are keyed by, and the
  renderer writes the colour scheme straight off `Theme::COLOURS`, so moving them would have
  meant either breaking that or keeping a second copy. `Theme::COLOURS` is the one list and
  `ThemeMapping` holds a file to it.

  `Theme::__construct()` is now public, which is what `StyleMapping::read()` has always been
  able to do with `Style`: a theme built in code is held to the types alone, and a theme
  written in one is where the bounds apply.

- **How this library is developed moved to `CONTRIBUTING.md`.** Conformance, the rendering
  slice, rendering in the real PowerPoint and why Word is not an alternative were ~130 lines
  of the README that answer "how do I work on this" rather than "what does it do", and the
  page's contract is that its examples run — which is worth more on a shorter page. The README
  keeps four lines and a link; `tests/Unit/dead-code.php` reads the new file as well, so a
  claim that moves out cannot get wrong over there. `Templates` and `Using your own deck as the
  template` are one section now rather than two, and kept every example and every claim they
  had. No doctested case was lost: `tests/Readme/` was 56 cases before and is 57 now.

- **A run of paragraphs and lists is one shape, because editing one box is what a
  PowerPoint user does.** Every Markdown block used to be written as a shape of its own, so
  a slide whose body was three paragraphs and a list carried four or five text boxes side by
  side. Clicking the text selected a box rather than the sentence under the pointer, moving
  one box left the rest of the column where it was, and each box was placed from its own
  measured height, so two of them could be drawn over one another. A run of consecutive
  paragraphs and lists is now one shape holding one `<a:p>` each, with the inline markup of
  a paragraph still a run inside that paragraph.

  | | Before | After |
  | --- | --- | --- |
  | `# T` then three paragraphs | `title`, `paragraph`, `paragraph`, `paragraph` | `title`, `text` — one shape, four `<a:p>` in it |
  | a paragraph, a list and a paragraph | `paragraph`, `list`, `paragraph` | `text` — the list's items are paragraphs of it |
  | a paragraph, a table, a paragraph | `paragraph`, `table`, `paragraph` | unchanged: a table is a shape of its own |

  A heading, a quote, a rule, a table, a picture and a code block are still shapes of their
  own, and any of them ends the run — which is also what keeps a frame from straddling two
  columns of a two-column role, since the columns divide at a heading. A quote ends it
  because it is drawn a gutter in from the margin at another size in another colour, which
  one box's paragraphs cannot each be while sharing one box.

  The gap between two paragraphs is the theme's own `block:` spacing, written as their
  `a:spcAft` — the same number the layout spends between two shapes of a column, spent in the
  other of its two places, and counted once rather than twice.

- **A frame is measured the way PowerPoint lays its paragraphs out, which is not where the
  spacing is written.** PowerPoint spends a paragraph's spacing after every *break* the text
  contains — a newline inside a paragraph, or the mark between two paragraphs — and not after
  a line the text merely wrapped onto. Measured rather than assumed, by asking PowerPoint to
  lay a frame out and report the height it came back with, and by reading the line positions
  out of the PDF it exported: a paragraph written over four source lines and wrapped to seven
  by its face came back with a gap after each of its three newlines and none after any of the
  four times it wrapped.

  `ShapeWriter::height()` therefore counts a break per break rather than a line per line.
  PowerPoint does not shrink the type to fit the box it was given — the `normAutofit` this
  library writes does not shrink at load — so a frame measured a gap out is a frame whose
  text is drawn over whatever the layout put below it, which is the one failure this change
  exists to remove.

  An earlier draft of this entry claimed the gap followed every *line*, which is what a deck
  written in a face that wraps looks like and is not what PowerPoint does: Georgia wraps and
  Calibri does not, so per-line and per-break agree on every unwrapped line and diverge on
  every wrapped one. `tests/Unit/rendering.php` now asserts the arithmetic either way, and
  `tests/Unit/dead-code.php` holds the sentence so it cannot come back.

- **The refusal for an unreadable notes slide could only be tested on some machines.** The
  test that proves the pass refuses rather than writing a part back as zero bytes corrupted
  a real `.pptx` and relied on the build's inflater giving up on it. It does not, everywhere:
  the archive is byte-for-byte identical on the two machines and only the willingness to
  report differs — a reserved deflate block type is refused by one zlib and decoded into
  different bytes by another, and neither a wrong CRC nor a compressed size past the end of
  the file is refused on all of them. So the php 8.2 job was red on `main` over a property of
  a third-party library rather than of this one.

  The corruption is gone. The refusal is now driven by a `ZipArchive` subclass whose
  `getFromIndex()` answers `false` for one part, which is what the archive's own reader
  documents for an entry it cannot produce and which the pass cannot tell apart from one.
  A separate test keeps the property that does hold everywhere — after a run over a real
  package, no part of it is a zero-byte entry.

  `NotesColourMapPass::applyTo()` takes an optional `ZipArchive` as its second argument for
  this. It defaults to opening the path as before, so every existing call is unchanged.

### Fixed

Places where the library rendered silence rather than a failure over a directive a
deck could name, each now either acted on or refused with the spelling that
would have worked; places where a block was drawn at a place nothing had
measured; and places where what the deck said did not survive into the file.

- **A shadow drawn for the word "transparent" was an opaque black one.** `shadow-color` read
  through the same `Colour::read()` that answers `fill` and `stroke`, where `none` and
  `transparent` mean *do not draw this*. A shadow has nowhere to record an absent colour —
  DrawingML draws an outer shadow as a coloured copy of the shape — so the null was answered
  with a default and `shadow-color=transparent` came out as
  `<a:srgbClr val="000000"><a:alpha val="100000"/>`: the most opaque shadow the format allows,
  requested by spelling the opposite. Both are now refused by name, and the refusal says how to
  say what was meant: leave off every shadow attribute. The two spellings live in one place,
  `Colour::isNone()`, because a caller that has to recognise them and a caller that has to
  answer for them are two lists that drift.

- **An unbounded `shadow-distance` or `shadow-blur` was written as a fraction of an EMU
  coordinate.** PHPPresentation converts pixels to EMU with a **float** multiply, so a large
  value arrives at the writer as a float and PHP prints it as `9.525E+14` —
  `shadow-distance=99999999999999999999` came out as `dist="8.785261865104174e+22"`, which
  `ST_PositiveCoordinate` cannot hold. PowerPoint opens the file; a schema-validating consumer,
  Keynote, Google Slides or a server-side renderer is not obliged to. A distance and a blur are
  now refused past the slide's width plus its height, which is where the switch to scientific
  notation is four orders of magnitude away, and where nothing a deck author can see is left
  unreachable.

- **A placed text block was shrunk to its own text, so `vertical-align` had nothing to align
  against.** `height=48 vertical-align=middle` was written at the 23px that one line at 14pt
  takes, anchored centre — so the text centred in 23px sitting at the top of a 48px space and
  rode high by `(48 − 23) / 2`. Measured on the deck that found it, every centred box was off
  by between 8 and 13.5 pixels, always upwards, and the box in the file was not the box the
  deck wrote.

  This is also why the composable way to put words on a shape did not work: draw the shape,
  put a `:::content` box over it with the same alignment, and the box would not stay where it
  was put. A deck places a box and aligns text inside it, so the box is now the one it
  declared, and only an overflow takes the room away — which is reported when it happens, as
  before.

  Found by rebuilding an existing deck in Markdown, which is the first thing this library has
  been asked to reproduce from another tool and a deck it had never been given.

- **`padding` on a placed text block was written into the file but never measured.** The inset
  reaches the shape through `ShapeWriter::freeText()` and did not reach
  `ShapeWriter::textWidth()`, which subtracted the default inset whatever the deck had asked
  for — so the width the text was measured in and the width it is drawn in were two different
  numbers, in both directions and neither visible from the deck:

  - a `padding` below the default was measured **wider** than it is drawn, so blocks were
    over-estimated, clamped to a box they fit, and reported as not fitting when they did; and
  - a `padding` above it was measured **narrower**, so the box was not grown and the text ran
    over whatever was under it — **without appearing in `overflows()` at all**.

  The second is the one this library states it will not produce. A `:::content{padding=40}`
  box on a slide with a rule beneath it drew its last line straight through the rule and off
  the bottom of the slide while the report said the deck was fine.

  A third case fell out of the same line: a box narrower than twenty pixels had its available
  width clamped up to one pixel, and `TextMetric`'s word-breaking loop counts one line per
  pixel of text, so a single `W` set at 24 points measured as **23 lines**. The clamp is now
  at zero, which leaves a box with no room in it to be reported as not fitting — the truth for
  a box narrower than its own padding.

- **A small SVG could take gigabytes to refuse.** The ceiling on a picture is on the pixels,
  and for a vector those pixels were allocated before anything asked whether they were allowed:
  `vector()` drew the file and measured the result. A file of **152 bytes** declaring a
  16000-square drawing is refused now in **0.07s with a 4 MB peak**, where drawing it first
  wanted a 1.9 GB pixel canvas — and this machine's ImageMagick allows `AREA 192 Gpixel`, so
  the larger the host the more it takes. `SvgRasteriser::measures()` reads the header with
  `pingImage()` and the ceiling is applied there, before anything is drawn.

  The justification in the previous version — that *nothing short of rendering one knows the
  answer* — was wrong, and so was the obvious fallback. ImageMagick's own resource limits were
  measured against a 4000-square SVG and **did not refuse it at all**, at
  `-limit area 1000 -limit memory 4MiB -limit map 4MiB` on the command line: the SVG coder is
  not bounded by them. So there is no second line of defence, and a file whose header cannot be
  read is **refused rather than drawn** rather than drawn with no ceiling on it.

  A drawing is now also **drawn once per file rather than once per reference**: a deck naming
  the same SVG on every slide paid for a render and a header read on every one of them.

- **An SVG was embedded as a vector, which is a deck PowerPoint opens with the picture
  missing.** The format does carry `image/svg+xml` and PHPPresentation declared it, so
  nothing anywhere objected: the part was well-formed, the relationship resolved, and the
  file passed every check that reads markup. What PowerPoint writes is two relationships —
  a raster in `a:blip` with an `asvg:svgBlip` extension on the same element pointing at a
  second one — and PHPPresentation writes one and points both the raster and the vector at
  it. The shape was a picture no reader had a way to draw.

  An SVG is now **drawn**, and the part in the package is a PNG — the same `ConvertedImage`
  path a converted WebP takes, so it keeps its proportions and is fitted to its box the
  same way. `Render\SvgRasteriser` is the new class, and it owns the whole of it.

  **`ext-imagick` is optional and is now in `suggest`.** GD cannot read a vector in any
  PHP version, so there is no imagick-free path and one was not invented. Where the
  extension cannot draw, the deck is refused by name rather than rendered:

  ```
  Slide 3 shows "diagram.svg", which is SVG. A .pptx holds a vector only beside a raster of it, and this PHP cannot draw one: ext-imagick is not loaded, or the SVG delegate it was built with will not draw a letter. Install ext-imagick, or put a PNG of the drawing in the deck.
  ```

  What counts as "cannot draw" is not `extension_loaded()`. A build of Imagick with no SVG
  delegate registers `SVG` anyway, hands the file to ImageMagick's own renderer, and
  returns a picture with every `<text>` and `<path>` in it dropped — it does not fail, so
  the capability is probed by drawing a letter. A build compiled without fontconfig fails the
  same way and for a blunter reason: it reports zero fonts, so `RenderFreetype` raises on the
  first `<text>` it is given and the glyph never appears. That one was blamed on a missing SVG
  delegate for a while, which the delegate list in the same build contradicts. That is the same
  probe and the same argument the Word half uses, and here it matters more rather than less:
  Word keeps the vector beside the raster, so a weak delegate costs fidelity, whereas here the
  raster is the only copy.

  The loss is real and is written down: the deck carries a raster, so a diagram is a
  picture at the size it was drawn, and what it shows is what the machine that drew it
  shows. `MAX_PIXELS` reaches an SVG one rendering behind every other format for the same
  reason — a file that sizes itself by `viewBox` says how big its coordinate system is, not
  how big a picture it is — so the ceiling is measured on the raster and the number in the
  failure is the raster's.

  `examples/markdown/07-images.md` slide 3 holds an SVG and needs a build that can draw
  one; every other example deck is unaffected.

- **PowerPoint refused to open seven of the decks in this repository.** It offered to repair
  them — "could not read some of the content … and removed it" — and opened the repaired copy,
  so nothing looked wrong until a person opened the file. The per-block spacing above is written
  as `a:spcAft` and `a:spcBef` in points, and a heading's trailing gap is a fraction of the
  heading's own size: `0.35 × 22` is not `7.7` in binary floating point but `7.699999999999999`.
  PHPPresentation writes a point measure by multiplying by a hundred without rounding
  (`AbstractSlide::writeParagraphStyles`), which turned that into `a:spcPts val="769.9999999999999"`,
  and `ST_TextSpacingPoint` is an integer type. LibreOffice reads the value and does not care,
  so every render and every structural check on this machine passed.

  | Was | Now |
  | --- | --- |
  | a spacing measure printed as the double it was | rounded to the nearest whole number, which is all the type allows |

  Rounding at the writer is the only place that works: a float times a hundred is integral only
  for the multiples of a quarter point, so a theme stating its gaps in anything finer cannot be
  carried correctly *into* the writer, and choosing theme values that happen to be exact would
  have traded a repair prompt for a silent constraint on the spacing model. All thirteen decks
  now validate against the ECMA-376 schemas.

- **A speaker note was written as a notes slide that nothing held up.** `::note` produced
  `ppt/notesSlides/notesSlideN.xml` and no `ppt/notesSlides/_rels/` beside it, no
  `ppt/notesMasters/` at all, and no `notesMasterIdLst` in `presentation.xml`. PowerPoint opens
  a deck in that state and prints the note, so the file worked and was nevertheless not a
  `.pptx`: a consumer resolving a part through the relationship that points at it finds
  nothing, and the notes master a notes slide inherits its text formatting from did not exist.
  The slide names its notes slide, so the other two links are now written from that pairing —
  read out of the slide's own relationship rather than from the number in the file name. The
  notes master takes its colour map from the slide master, so a note resolves its colours the
  way the rest of the deck does. A deck with no note in it is not touched at all.

  **A notes master needs a theme of its own, and the first version of this fix made every
  deck carrying a note unopenable.** Pointing the notes master at the slide master's theme is
  legal OPC — a part can be the target of any number of relationships — and every part still
  validated against the ECMA-376 schemas. PowerPoint repaired those decks instead, silently,
  so nothing here objected until a deck was actually opened in PowerPoint. The bisection that
  found it put the fault on the `notesMasterIdLst`: declaring a valid master at all was enough,
  and the theme was the one link left to change. `ppt/theme/theme2.xml` is now a copy of the
  deck's own theme, so a note is still drawn in the deck's fonts; the copy is named from the
  slide master's own relationship rather than assumed, so a package holding two themes cannot
  have it overwrite one. An `Override` names one part, so the copy needs a content type of its
  own even though the theme type is already declared for `theme1.xml`.

- **A numbered list that begins above one counts on.** `7.` and `8.` rendered as `7.` and `7.`
  outside PowerPoint. PHPPresentation writes the start of a numbering on every paragraph of it
  and leaves the reader to count on, which PowerPoint does and LibreOffice does not — it restarts
  at each one. Every item of such a list now carries its own number, and the file says what to
  draw rather than leaving it to be inferred. A list that begins at one is unchanged: nothing is
  written for it at all, and every reader counts on from one.
  Found by rendering the gallery slide and looking at it.

- **A picture whose name has a space in it is found.** CommonMark has no bare space in a
  destination, so `a picture.png` is a paragraph and `a%20picture.png` is the picture —
  and the encoded spelling was what the file lookup was given, so a deck written
  correctly was refused with `ImageNotFound`. The target is decoded before the file is
  looked for.

- **A refusal named the marker the parser expects rather than the one the deck wrote.** Every
  two-colon marker is read in either case, and `MARKER`, `REGION` and `COLUMN` capture only what
  follows an equals — so three factories were rebuilding a marker out of its argument instead of
  being handed the line. A writer who typed `::Column=2` was told about `::column=2`, which is a
  line they did not write and have to go looking for.

  | Deck writes | Was told about | Now |
  | --- | --- | --- |
  | `::Column=2` | `::column=2` | `::Column=2` |
  | `::Click=` | `::click=` | `::Click=` |
  | `:::Click=3` with nothing after it | `:::click` | `:::Click=3` |

  The two halves of one of these messages are two different claims, and which half a factory is on
  decides which spelling it takes. **A quotation is a line in the author's file and echoes it** —
  `::Column=2` is reported as `::Column=2`, because only one of the two spellings is the line they
  wrote. **Advice is a claim about this library's grammar and stays canonical** — `Write "::click"`
  whatever the deck wrote, because advice that echoed the mistake would teach the mistake. Both
  halves sit in one sentence, so neither can be right on its own, and a test holds them together
  rather than one after the other.

  `UnsupportedColumn::beyond()` is on the advice side of that line, and stays there on purpose. It
  says `Slide 1 has a "::column"` for a deck that may have written `::Column`, and it is not a
  quotation: the refusal is raised from a *count* of markers measured against a layout's boxes, so
  the line the deck wrote is gone by the time the sentence is built. There is nothing here to echo,
  and threading one would mean carrying the spelling of every marker on the slide through the
  renderer in order to name a marker the author already has in their file.
  `UnknownMarker::strayCloser()` is on the advice side for a second reason: `CLOSES_REGION` admits
  three colons and nothing but whitespace, so every line that matches it trims to the one spelling
  the message already quotes.

  **A quoted spelling is trimmed and stripped of control characters.** A marker line is the one
  place in a deck where a writer gets to write bytes rather than words, and a refusal is read in a
  terminal or a CI log: a carriage return moves the cursor to the start of the line and an escape
  sequence can clear it, so the deck's own text would be what that terminal shows above the
  sentence explaining what to do about it. Whitespace goes for the reader rather than the writer:
  `::click=  ` is refused as `::click=`, and quoting the two trailing spaces would only move the
  disagreement somewhere less visible. Both happen in `UnknownMarker::quoted()` rather than in the
  parser, so the reason a message is safe to print lives with the message.

- **A template-backed column was laid out and left empty.** `PresentationRenderer::splits()`
  asked the geometry the constructor was handed rather than the merged one `render()` builds,
  so a theme whose `template.pptx` gives a role two body placeholders was asked how many
  columns it had and answered with the library's own count. A two-column role came back
  "does not divide": the second box was laid out and no block was ever put in it, and nothing
  anywhere said so. Reproduced against the code before this change with `content` mapped onto
  *Two Content* — every shape at the first column's `x`, the second column's `x` unused.

  The column count now comes from the merged geometry, which is what carries the template's
  boxes as well as the theme's type. `::column` would have made it worse rather than better:
  the first deck to write the marker on a template-backed role would have been refused, or
  ignored, because of the same wrong question.

  `splits()` is gone with it — there is no longer a rule to ask whether it divides — and so is
  the constructor's `SlideGeometry` argument, which nothing read but that one method. A caller
  passing it positionally gets a `TypeError`, because argument four is `$strict` now and a
  `SlideGeometry` is not a `bool`; a caller naming it gets `Error: Unknown named parameter
  $geometry`. Both are loud, which is the point: a geometry that was promised and never used
  is not something to accept quietly either.

- **Six claims in the README were false.** Each was checked by running the thing rather than by
  reading the page, and each is now held in `tests/Unit/dead-code.php` so it cannot come back.
  The phrases are named there rather than quoted here: this file is read by the same guard.

  | Was | Now |
  | --- | --- |
  | five layout roles, where the example thirty lines above printed all eight | all eight |
  | a paragraph's spacing following every wrapped line | following every *break* — a newline or a paragraph mark, never a wrap. Reversed by `f9d1642`; the README was never updated, and this is the one that matters, because it told a reader a frame is taller than the library draws it |
  | a step marker closing a list item being quietly ignored | refused, naming the mistake. The Notes below said this line wanted rewording; here it is |
  | a byte count two sizes too large, in the README and in `MAX_STEP`'s own docblock | the real one, and this entry's attribution corrected |
  | a twenty-digit step written in the present tense as hanging the process | refused at `jump()`. Past tense: hanging was the behaviour before the ceiling |
  | every colour reaching a shape through the master's colour map | not every: `heading-ink: accent` paints a heading `accent1`, and bullets and rules take `accent1` outright |

  `:::` was refused by `UnknownDirective::container()` and documented nowhere on the page. It is
  now, beside the frontmatter's closed directive list, with an example that runs.

- **The claims guard was case-sensitive, so the README walked past it.** The ban on a marker
  closing a list item sat directly beneath a README sentence that began the same phrase with a
  capital, and `str_contains()` matched only the lowercase one. Every sentence a claim lives in
  begins with a capital often enough that this is not a rare accident; it is the shape of prose.
  Matching folds the case now.

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

- **27 bytes of deck asked for two billion slides.** `<!-- v-click=500 -->` rendered 500
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

- **Exception messages repeat absolute server paths, and that is a choice.** Not a finding
  fixed here: for a person running this from a terminal the path is the useful half of the
  message, because the difference between `logo.png` and `/Users/someone/deck/logo.png` is
  the whole of what is wrong, and a failure that would not say where it looked would send
  the author to `find`. The cost — a deck whose failure ends up in a log, an issue tracker
  or a CI transcript carries the layout of the machine that produced it — is now written down
  in the README under *What a failure repeats*, along with what bounds it: a deck cannot
  choose the paths in a message, because the two things a deck writes paths for (an image and
  a theme) are contained to directories the calling program allowed.

- **Two decks Microsoft PowerPoint authored are carried as fixtures for the template reader.**
  Every template test was
  checked against `tests/fixtures/themes/templated/template.pptx`, which this repository writes
  from a description — a good template, and a poor PowerPoint file: one master, layouts written
  by the same hand as the reader, and placeholders that spell out every attribute the format
  lets them spell out. `tests/fixtures/powerpoint/` holds two decks saved by PowerPoint 16.113.4
  instead: one designed (Facet, a changed palette, two changed theme fonts, eighteen named
  layouts) and one merely used (A4 rather than 16:9, a table on a layout with no table in it, a
  slide that has drifted away from the layout it is on). That is what found the bug above. They
  are validation and documentation, not themes — the three built-in themes stay library-owned.

  `tools/build-powerpoint-fixtures.sh` rebuilds them, and any deck named on the command line
  rebuilds only that one — a deck PowerPoint writes is not byte-identical to the one it wrote
  last time. PowerPoint is required rather than
  optional: `make new slide` answers "class slide cannot be created", so the slides have to
  exist before it opens the file and everything after that is its own work. The authorship
  metadata PowerPoint writes is stripped afterwards, and a test checks that it was.

The first slice of the PowerPoint half of `markdown-office`. It decides where a
slide begins and which theme a deck means, and renders the deck to a `.pptx`.

- **`ShapeWriter` is not split and `php:S1448` stays open on it.** Thirty-seven methods is the
  one finding this release leaves red, and it is left red on purpose: `columns()` and
  `rowHeights()` are each read by the half of the class that measures a table and the half that
  draws it, `HEADER_ROW` by both, and `bodySize()` by the frame's height, a paragraph's line
  count and the runs both draw. Those are four places a split has to cut, and each is a place
  where the seam runs through a fact two methods must agree on — the failure is a table whose
  drawn cells do not match the room they were measured for, which is the same silent gap as
  the one measure-and-draw kept in one object to prevent.

  The reason is now in the class docblock, where somebody deciding whether to split it will
  read it, and `tests/Unit/dead-code.php` checks it is still there: a finding that stays red
  with no argument next to it reads as an oversight, and the next person to see it will either
  fix it or silence it.

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

[0.1.0]: https://github.com/markdown-office/markdown-presentation/releases/tag/v0.1.0
[0.2.0]: https://github.com/markdown-office/markdown-presentation/releases/tag/v0.2.0
[0.2.1]: https://github.com/markdown-office/markdown-presentation/releases/tag/v0.2.1
[0.2.2]: https://github.com/markdown-office/markdown-presentation/releases/tag/v0.2.2
[composer-patches]: https://github.com/cweagans/composer-patches
[the-patch]: https://github.com/markdown-office/PHPPresentation/blob/patches/1.2.0-to-master/patches/phpoffice-phppresentation-1.2.0-to-master.patch
