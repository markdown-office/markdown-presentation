# Examples

Eleven decks, each demonstrating one thing. `build.php` converts every one into a
real `.pptx` under `out/` and renders **one PNG per slide** beside it, so the
result can be checked without PowerPoint. One file per slide rather than one per
deck: a deck's whole question is usually whether slide three overflows, which is
not answerable from the first slide.

```
php examples/build.php
```

Previews need LibreOffice and `python3` with `pymupdf` (or ImageMagick). Without
them the decks still build and the script says so rather than producing nothing
quietly. The profile and the scratch script go to the temporary directory, so
`out/` holds only slides.

| Source | Builds | Shows |
| --- | --- | --- |
| `01-kitchen-sink.md` | `01-kitchen-sink.pptx` | Every construct at once: emphasis, `code`, strikethrough, links, nested lists, an ordered list starting at 7, an aligned table with markup in its cells, a two-line quote, code, a rule, and a `::note` becoming a speaker note |
| `02-layout-roles.md` | `02-layout-roles.pptx` | Every layout role, one per slide |
| `03-themes.md` | three files | The same deck in `default`, `corporate-dark` and `minimal`, changed by nothing but the `theme:` line — so the three can be put side by side and compared. They differ in type scale, margins, rule weight and heading treatment, not only in palette |
| `04-frontmatter.md` | `04-frontmatter.pptx` | Every frontmatter key and what each one does |
| `05-steps.md` | `05-steps.pptx` | `::click` steps as click animations on one slide, a `:::click` region that is a step of its own, a build-up across two columns, a `::column` written inside a region, a picture held back for its own click, and a `::note` |
| `06-tables.md` | `06-tables.pptx` | Table alignment, a bold filled header, widths from content |
| `07-images.md` | `07-images.pptx` | A picture fitted to the room left in its column, a WebP converted to PNG, an SVG drawn to PNG, and the three layouts built around one: `image-text`, `image-title` and `image-slide` |
| `08-code.md` | `08-code.pptx` | Code blocks |
| `09-inline-markup.md` | `09-inline-markup.pptx` | A `title:` carrying `**bold**` and `*italic*` |
| `10-rejected.md` | **nothing** | Conditional content the way Slidev writes it |
| `11-type-scale.md` | two files | The same slides in `default` and in `dense`, the one theme whose whole scale is smaller at once — body, title, margins, gutter, block gap and rule. The table and the code block are twelve points in both, because those two sizes are the library's, which is what leaves them to read the two pages against |
| `12-free-shapes.md` | `12-free-shapes.pptx` | Shapes placed by the deck rather than by the layout: a panel behind the text, a badge and a chevron, tilted arrows whose labels turn with them, text blocks in boxes of their own, a circle and an ellipse sharing a centre, and all ten ink shapes with a picture on one page |
| `13-placed-deck.md` | `13-placed-deck.pptx` | A whole business pitch built out of free shapes and nothing else — a dark cover and a dark sign-off, an agenda of numbered circles, a card grid, a hand-built pricing table with zebra rows and a right-aligned column, a four-pillar comparison, a quarterly roadmap with arrows between phases, and translucent decoration over both dark slides. The company is invented |

## The deck that is not anybody's business

`13-placed-deck.md` exists because a real pitch deck was rebuilt from another tool to find out
what this library could not do, and that deck is nobody's to publish. It is a fictional bicycle
frame maker rather than a redacted version of the original: the structure is what carries the
lesson, and a deck full of placeholders would teach the layout nothing that a deck with a real
argument does not.

Rebuilding it is what turned up both defects in [PR #11](https://github.com/markdown-office/markdown-presentation/pull/11)
— `padding` written but never measured, and a placed text block shrunk to its own text so
`vertical-align` had nothing to align against.

## The one that is not built

`10-rejected.md` names a directive this library does not have. Converting it is
the failure it exists to demonstrate, so `build.php` leaves it alone and says
which file and why.

## The one that needs a rasteriser

`07-images.md` slide 3 holds an SVG, and a `.pptx` cannot hold a vector on its
own — PowerPoint keeps one beside a raster of it, which is what has to be drawn
first. That is `ext-imagick`, and it is optional, so this deck needs a build
whose Imagick can draw one: on a build with only ImageMagick's own SVG renderer
the diagram loses its text and its chevron, and the conversion refuses rather
than producing that. A build without `ext-imagick` at all refuses it too, naming
the file. Every other deck here builds either way.

## Things worth looking at in the output

A few of these are here because the render is the only honest evidence:

- **`01`, first slide.** Emphasis that does not close is left as written — `2 *
  3`, `**unclosed`, `snake_case_name` — rather than refused. A deck that is
  rejected because of a stray asterisk is a deck nobody can show.
- **`01`, second slide.** Four spaces of indent nest one level, not two, and
  `7. An ordered list starts at the number the deck wrote` renders as 7, 8.
- **`02`, the `two-column` slide.** Everything above the `::column` marker goes left
  and everything below it goes right. The marker is the only way there is, and the
  `##` under it is an ordinary heading that lands in the column it was written.
- **`02`, the `blank` slide.** `blank` has no title box — that is what makes it
  blank — so a frontmatter `title:` there is drawn as a heading in the body
  instead. A `##` in that body keeps its own depth and comes out smaller.
- **`05`, the `two-column` slide with a region in it.** Its `::column` is written
  inside a `:::click` region, and the sentence after the closing `:::` is drawn in
  the right column with it. A region says what a click shows; a column says where a
  block is drawn, and the closer ends the first and not the second.
- **`07`, the `image-text` slide.** The picture is in the *wider* column and the
  text in the narrower one, because a reader looks at the image first. It
  divides at a `::column` exactly as `two-column` does.
- **`07`, the `two-column` slide.** The picture is an SVG, and the part in the
  `.pptx` is a PNG of it drawn at the file's own 320 × 200 — a vector is drawn
  rather than carried, because a relationship pointing at an `image/svg+xml` part
  is a deck PowerPoint opens with the diagram missing. It takes the same path as
  the WebP on slide 2, so it keeps its proportions the same way.
- **`07`, the `image-slide` slide.** The picture is the slide: no headline, and
  no margin either — the box it is fitted to is the whole page. The logo is
  square and the page is not, so the picture keeps its own proportions inside
  that box and the air either side of it is the shape not filling it.
- **`12`, the second slide.** The panel is `layer=back`, so it is written
  before the layout's own shapes and the text is drawn on top of it; the badge
  and the chevron have no layer and are written after, so they sit over
  everything. Open the shape list in PowerPoint and the order is the write
  order.
- **`12`, the third slide.** The circle and the ellipse share a centre, because both are
  `anchor=center` on the same two numbers — and the circle is 14% of the slide's height on
  both axes while the ellipse is 20% of its width, so they are the same height and not the
  same width. That is what resolving a circle's percentages against the height buys: the
  spelling that reads as "a circle inside an ellipse" draws one.
- **`12`, the fourth slide.** Three tilted arrows and the two ways of putting
  words on one. The first has a `:::content` box over it given the *same*
  `rotate` — styled Markdown, and a second shape to keep in step. The other two
  carry a `label` inside the arrow itself: one `<p:sp>`, so it is what you
  double-click and drag, with words you cannot style because PHPPresentation
  writes them with no `a:rPr` at all. Look at the third one — `**Churn**` in a
  label is six literal asterisks, where the first arrow's is real bold.
- **`12`, the fifth slide.** Ten ink shapes on one slide, each its own
  `<p:sp>`. The rule is a rectangle a pixel tall rather than a connector,
  which is what a `---` has always been.
- **`03`, the same slide in each of the three themed decks.** The difference is not
  the palette. `default` is Office's colours at the library's own scale with a
  40px margin and a 2px rule; `minimal` is a serif deck with 64px margins, a
  1px rule and headings in their own face's reading weight rather than a
  synthesised bold; `corporate-dark` is 48px margins, an 18pt body, a 4px rule
  and headings in the accent. Look at slide five of each: it is the same
  sentence in all three and the pages around it are not the same page.
- **`03-themes-corporate-dark`.** The only one of the three with a dark page,
  and the only one whose text is painted in the theme's light token rather than
  its dark one.
- **`11`, the same slide in each of the two files.** `dense` is the one theme
  whose numbers are all smaller than the default's at once: a 13pt body against
  a 16pt one, a 28px margin against 40, and an 8px gap after a paragraph against
  12 — the gap the rest of the rhythm is a fraction of, so a table above a
  paragraph takes 12px here against 17px there. A table and a fenced block stay
  at twelve points in both files, because those two sizes belong to the library
  rather than to a theme, so they are the one thing on the slide that does not
  move and the page around them is what the pair is for.
- **`09`.** The title is `Ops **runbook**: what *changed*` and is drawn with the
  emphasis, once, and is not repeated in the body.
