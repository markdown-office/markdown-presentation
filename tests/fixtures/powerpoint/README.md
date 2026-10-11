# Two decks PowerPoint wrote

Both `.pptx` files here were authored in Microsoft PowerPoint 16.113.4 on macOS, saved by it, and
are carried here unchanged apart from their authorship metadata. Nothing in them is written by
this library: a deck this library produced would be a round trip of a deck, which is the thing
these two are here to be different from.

They are **not** themes. `themes/default`, `themes/minimal` and `themes/corporate-dark` stay
library-owned, and neither of these files feeds them. These are read by tests and shown in the
README as examples of what a person's own deck looks like from the inside.

| File | Bytes | What it is |
| --- | --- | --- |
| `harbour-lights.pptx` | ~47 KB | The deck a person designs. |
| `workshop.pptx` | ~37 KB | The deck a person just works in. |

## `harbour-lights.pptx` — a design

Five slides, 16:9 (12192000 × 6858000 EMU), on PowerPoint's own **Facet** design with the colour
scheme and the two theme fonts changed afterwards: `accent1` terracotta, `accent2` deep teal,
`accent3` sand, `background` a warm off-white, `foreground` a near-black blue, Avenir Next Demi
Bold for headings and Avenir Next for everything else. Eighteen layouts, all named, of which the
five slides use `Title Slide`, `Section Header`, `Title and Content`, `Two Content` and
`Picture with Caption`.

This is the one the README documents, because it is what a person would bring: a palette, a type
hierarchy, layouts that have pictures in them.

## `workshop.pptx` — a deck that is used, not designed

Six slides on **A4 paper** (9906000 × 6858000 EMU) rather than a slide shape, on PowerPoint's
stock *Office Theme*, nothing renamed or recoloured. Twelve layouts. What makes it worth reading:

- a page that is neither 16:9 nor 4:3, which is what a deck projected off a printout has;
- a 2 × 2 table on a slide whose layout is *Title Only* and has no table in it;
- a picture dropped on top of a slide rather than into its picture placeholder;
- slide 5, whose left column has been dragged down and shrunk and which carries a floating text
  box belonging to nothing, so it no longer matches the layout it is nominally on;
- `Title and Content`, whose content placeholder PowerPoint writes as a bare `<p:ph idx="1"/>`
  with no position of its own, to be inherited from the master. So do `Content with Caption` and,
  on the other deck, `Two Content`'s two columns.

## What a reader is meant to take from them

`TemplateGeometry` reads layouts, not slides, so almost everything wrong with a deck is invisible
to it by construction. These two decks are carried anyway, because the layouts in a deck that is
used are not the layouts anyone writes down, and the reader is only ever as good as the layouts it
has seen. The bug they found is described in `tests/Unit/powerpoint-fixtures.php`.

## Rebuilding them

```
tools/build-powerpoint-fixtures.sh
```

Needs PowerPoint and nothing else beyond what the tests already need. It writes an empty stub for
PowerPoint to open, drives it through `build/*.applescript`, scrubs the result, and copies it
here. PowerPoint is required rather than optional: `make new slide` answers "class slide cannot be
created", so the slides have to exist before it opens the file, and everything after that is its
own work.

## The authorship metadata

PowerPoint writes `cp:lastModifiedBy` in `docProps/core.xml`, and on this machine that was the
account's own name. `build/scrub.php` empties it and every other field that names a person or an
organisation, then reads the file back and refuses it if anything still does.

Kept, deliberately: `dcterms:created` and `dcterms:modified`, and the `HeadingPairs` and
`TitlesOfParts` in `docProps/app.xml`, which are the deck's own fonts, theme name and slide
titles. None of them says who made it, and they are what a reader can check a deck against.

`dc:creator` was empty on both and `Company` and `Manager` were empty or absent, so there was
nothing else to remove — the scrubber empties them anyway rather than assuming a future deck is as
tidy as this one.
