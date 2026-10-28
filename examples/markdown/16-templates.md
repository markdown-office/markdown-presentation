---
title: Slide templates
theme: minimal
chrome: ./templates/chrome.md
description: The furniture of a deck, written once, and the slides that draw it.
template: wordmark
---

# Slide templates

The wordmark, the band and the page number are furniture.
They are the same on every slide that carries them.
Writing them out on every slide means changing them on every slide.
A **template** is that furniture written once, in the deck's own Markdown.
Every slide that names it is drawn with it.

A deck gets its templates three ways:

- a **definition** in the deck, a block whose frontmatter names a template
- a **slide** that names a template, or several of them
- a **chrome file** beside the deck, named in the deck's frontmatter

---
template: wordmark
---

:::content{left=6% top=91% width=40% height=6% fill=none padding=0 color=accent1 font-size=12 font-family=body align=left vertical-align=middle}
**Quire** · binders since 1908
:::

---
template: page
---

::slide-number{left=86% top=91% width=8% height=6% color=accent1 font-size=12 font-family=body align=right vertical-align=middle of=true}

---
layout: blank
name: A slide drawn with two templates
template: [wordmark, page]
---

# A slide drawn with two templates

This slide names two templates, and both of them are defined in this file.
The two blocks between the cover and this slide are the definitions.
A block whose frontmatter names a template and nothing else is a definition.
A block that names a template and something else is a slide that draws it, which is why
this one carries a `layout:` and a `name:` as well.

The cover names one template, this slide names two, and the next names three.
The wordmark is at the foot on the left and the number is at the foot on the right, and
neither of them is written on this slide.

---
layout: blank
name: A template out of a file
template: [wordmark, band, page]
---

# A template out of a file

The deck's frontmatter names `templates/chrome.md`.
That file holds a template called `band`.
The band across the head of this slide came out of that file, and this deck says nothing
about where it is or how thick it is.

A slide may name as many templates as it likes, and the chrome of each of them is
drawn on it: the wordmark and the number from this deck, and the band from the file.

---
layout: blank
name: The deck's own template wins
template: [wordmark, page]
---

# The deck's own template wins

:::content{left=6.7% top=20% width=86.7% height=70% font-size=17}
`templates/chrome.md` holds a template called `page` too, and it puts a bare number at
the foot of the slide.
This deck defines its own `page` above, and the deck's own one is the one that is drawn:
the number is in the theme's `accent1` rather than its `subtle`, at twelve points rather
than ten, and it carries the number of the deck after it.

That is the rule the theme resolver follows by search order, and it is what lets a deck
change one word of a house style without changing the house style.
`04-frontmatter.md` draws the file's `page` — the same name, the other version of it.
:::

---
layout: blank
name: A definition is not a slide
template: [wordmark, page]
---

# A definition is not a slide

A definition is not drawn, it has no number, and it does not count towards the number of
slides.
This file has five slides and two definitions, and the number at the foot of this page
reads five of five rather than five of seven.

`of=true` is what writes the number of the deck after the number of the slide.
