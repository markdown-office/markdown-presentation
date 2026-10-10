---
title: Three themes, one deck
theme: default
author: markdown-office
description: The same slides in the three palettes that ship with the library.
---

# Three themes, one deck

`build.php` writes this source three times, changing nothing but the `theme:`
line, so the three files in `out/` hold exactly the same slides in three
palettes and can be compared side by side.

---

## What a theme reaches

The rule under this heading is painted in `accent1`. The table's header row is
filled with `bg2` and the quote is set in `tx2`, and a dark theme maps those to
the other two slots — `dk2` for the fill, `lt2` for the quote.

***

> A theme decides the twelve colours of the scheme and the three fonts.

| Slot | Written to |
| :--- | :--------- |
| `bg1` | the page behind every slide |
| `tx1` | the title, a heading, a table cell |
| `accent1` | rules and bullets |

---

# The three fonts

`theme.yml` names them as `heading`, `body` and `mono`, and each is applied to
the runs that use it — a `.pptx`'s font scheme is not reachable from here.

```php
$deck = (new DeckParser())->parse("---\ntheme: corporate-dark\n---\n\n# One\n");
```

All twelve colour slots are written whether a shape uses them or not, because
DrawingML's colour scheme is twelve required elements and a scheme with five of
them is not a theme part.