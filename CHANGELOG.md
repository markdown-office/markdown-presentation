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

### Notes

- An unknown theme name, an unknown layout role, an unknown `styles.yml` slot, a
  frontmatter key nobody acts on, a `v-click` step that does not go forwards, a
  frontmatter block that is not a mapping, a `title` that is not a string, a slide
  opening with a fenced `yaml` block, an image that is not a file, and an image no
  `.pptx` can carry are all refused with a message naming what would have worked. A
  silent fallback produces a broken deck with no feedback anywhere, which is the
  failure this library exists to avoid.
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