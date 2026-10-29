# A corporate template

A company template, in the files a company actually has: a theme, a brand mark, and the
furniture of a deck written once.

**Halden Instruments** is fictional. The design is not: a deep blue primary, a muted gold
accent, a serif for headings and a sans for the body is the shape a great many corporate
decks have, and every part of it is a file you edit.

| | |
| --- | --- |
| `theme.yml` | the design — the twelve colour slots, the faces, the type scale and the rhythm |
| `layouts.yml` | which layout each of the eight roles gets |
| `text-styles.yml` | the named text styles a deck writes words in |
| `templates.md` | the furniture of a deck: the wordmark, the rule, the footer |
| `logo.png`, `logo-light.png` | the mark, for a light page and for a dark one |
| `make-logo.php` | draws the two marks from the theme's colours |

The deck that uses all of it is `../17-corporate-template.md`.

## Changing it to your company

Three things, and each is one file.

**The name.** `templates.md` holds the wordmark, and the name is in it twice — once in the
light template and once in the dark one. The dark one is the same named style with the
colour laid over it:

```markdown
:wordmark[Halden Instruments]{color=lt1}
```

So the name is two edits, and the style behind it is one: `text-styles.yml`.

**The mark.** Drop your own `logo.png` and `logo-light.png` in beside the theme — same
names, same 512 × 512 — and nothing else changes. To draw the mark that is here instead,
`php make-logo.php` reads the theme's colours and writes both files, so the logo and the
theme cannot drift apart.

**The colours.** `theme.yml`, and nothing else. The names are the format's: `lt1` is the
page, `dk1` the ink, `lt2` the quiet ink a caption is written in, `dk2` the panel a dark
slide is painted with, and `accent1` to `accent6` are yours. `accent1` is the primary — the
wordmark's accent, the numbers, the bar under a title. `accent4` is the alert colour, and
the table uses it for the one row that went down.

## The furniture

The templates file holds four templates, and a slide names the ones it wants:

```markdown
---
layout: blank
template: [wordmark, rule, footer]
---
```

| Template | What it draws |
| --- | --- |
| `wordmark` | the mark and the company's name, at the top left |
| `wordmark-light` | the same, for a slide with a dark panel behind it |
| `rule` | the hairline under the header |
| `footer` | the confidential mark and the slide number |

**A dark slide and a light one are the same deck, and the furniture on them cannot be the
same furniture** — the name is ink on a light page and white on a dark one. That is what
the second wordmark is for, and it is the one thing a single templates file cannot decide for
you.

The cover and the two dark slides name `wordmark-light` and nothing else; the light slides
name all three.

## The mark

An aperture: a disc with a crosshair and a pupil knocked out of it. The knockout is
*transparent* rather than white, so the page shows through it on a light slide and the
panel shows through it on a dark one. A mark that carried its own white would be a white
crosshair on a dark panel, which is nothing at all.

## The numbers are invented

Every figure in the deck is made up. Halden Instruments does not exist, and the shipments,
margins and segments are not measurements of anything.
