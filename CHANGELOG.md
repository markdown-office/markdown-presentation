# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first slice of the PowerPoint half of `markdown-office`. It decides where a
slide begins and which theme a deck means, and renders the deck to a `.pptx`.

### Added

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

Four places where the library rendered silence rather than a failure over a
directive a deck could name, each now either acted on or refused with the
spelling that would have worked, and five where a block was drawn at a place
nothing had measured.

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
- **A code block is padded by its real insets.** `CODE_PADDING` added twelve
  pixels to the height of a block whose body insets are 91440 EMU across and
  45720 down — 9.6px and 4.8px, and cost to a text block's width as well as its
  height. Both are measured now, as `SlideGeometry::INSET_X` and `INSET_Y`.

### Notes

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