# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

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

The first slice of the PowerPoint half of `markdown-office`. It decides where a
slide begins and which theme a deck means, and renders the deck to a `.pptx`.

### Added

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