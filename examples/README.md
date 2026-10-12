# Examples

Ten decks, each demonstrating one thing. `build.php` converts every one into a
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
| `05-steps.md` | `05-steps.pptx` | `::click` steps as click animations on one slide, a `:::click` region that is a step of its own, a build-up across two columns, a picture held back for its own click, and a `::note` |
| `06-tables.md` | `06-tables.pptx` | Table alignment, a bold filled header, widths from content |
| `07-images.md` | `07-images.pptx` | A picture fitted to the room left in its column, and the three layouts built around one: `image-text`, `image-title` and `image-slide` |
| `08-code.md` | `08-code.pptx` | Code blocks |
| `09-inline-markup.md` | `09-inline-markup.pptx` | A `title:` carrying `**bold**` and `*italic*` |
| `10-rejected.md` | **nothing** | Conditional content the way Slidev writes it |

## The one that is not built

`10-rejected.md` names a directive this library does not have. Converting it is
the failure it exists to demonstrate, so `build.php` leaves it alone and says
which file and why.

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
- **`07`, the `image-text` slide.** The picture is in the *wider* column and the
  text in the narrower one, because a reader looks at the image first. It
  divides at a `::column` exactly as `two-column` does.
- **`07`, the `image-slide` slide.** The picture is the slide: no headline, and
  no margin either — the box it is fitted to is the whole page. The logo is
  square and the page is not, so the picture keeps its own proportions inside
  that box and the air either side of it is the shape not filling it.
- **`03`, the same slide in each of the three themes.** The difference is not
  the palette. `default` is Office's colours at the library's own scale with a
  40px margin and a 2px rule; `minimal` is a serif deck with 64px margins, a
  1px rule and headings in their own face's reading weight rather than a
  synthesised bold; `corporate-dark` is 48px margins, an 18pt body, a 4px rule
  and headings in the accent. Look at slide five of each: it is the same
  sentence in all three and the pages around it are not the same page.
- **`03-themes-corporate-dark`.** The only one of the three with a dark page,
  and the only one whose text is painted in the theme's light token rather than
  its dark one.
- **`09`.** The title is `Ops **runbook**: what *changed*` and is drawn with the
  emphasis, once, and is not repeated in the body.
