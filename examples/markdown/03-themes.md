---
title: Three themes, one deck
theme: default
author: markdown-office
description: The same slides in the three themes that ship with the library.
---

# Three themes, one deck

`build.php` writes this source three times, changing nothing but the `theme:`
line, so the three files in `out/` hold the same slides and can be compared.

Open them side by side. The difference is not the palette: it is the size of
the type, the width of the margins, the weight of the rule under a heading, and
whether a heading is set bold.

---

# What a theme reaches

The rule above is painted in `accent1`, and it is 2 pixels thick here, 1 in
`minimal` and 4 in `corporate-dark`. The margins are 40px, 64px and 48px.

> A theme decides the twelve colours and the three fonts.

---

# Where those colours go

The table's header row is filled with `bg2` and a quote is set in `tx2`.

| Slot | Written to |
| :--- | :--------- |
| `bg1` | the page behind every slide |
| `tx1` | the title, a heading, a cell |
| `accent1` | rules and bullets |

---

# The type scale

This paragraph is at the theme's `body:` size and this heading at its `title:`
size.

## This heading is one level down

Which is the second entry of the theme's `headings:` scale. In `default` that
is twenty points against a twenty-two above it.

### This one is two levels down

In `minimal` the same two headings are twenty-three and twenty-two points, so
they read as one block of type rather than as two.

---

# The shape of the scale is the theme's

The same two headings again, in `corporate-dark`: twenty-six points and
twenty-three, so they read as two steps rather than as one block.

Which is the point of the whole deck. A theme that changed only its palette
would give three files with the same page and a different ink; these three do
not have the same page.

---

# What each theme is

| Theme | Margins | Body | Rule | Heading |
| :--- | ------: | ---: | ---: | :------ |
| `default` | 40px | 16pt | 2px | Bold, body ink |
| `minimal` | 64px | 17pt | 1px | Reading weight |
| `corporate-dark` | 48px | 18pt | 4px | Bold, accent |

Read that table in the three renders rather than in this one. It is the same
table in all of them, and the page around it that differs.
