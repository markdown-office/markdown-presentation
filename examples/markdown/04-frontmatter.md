---
title: Every frontmatter key
description: One slide per key, and what each one reaches.
theme: minimal
author: markdown-office
chrome: ./templates/chrome.md
---

# Every frontmatter key

Nine keys: `title`, `name`, `level`, `layout`, `theme`, `author`, `description`,
`template` and `chrome`. A block naming a tenth is a failure that names these
nine, and this slide carries the first four.

---
name: A title that is not a heading
layout: content
---

`name:` gives a slide a title without writing a heading for it. `title:` says the
same thing, and where a block carries both `title:` is the one that wins.

`layout:` is written here, and it is what every other slide in this deck is
getting by saying nothing: a slide that names no `layout:` is a `content` slide.

---
title: A title drawn at 20pt
level: 3
---

`level:` says how deep to read a title that need not be a heading at all. This
one is drawn at the size a `###` in the body would be; `level: 1` would have
drawn it at the layout's own 28pt, and `level: 2` at 22pt.

The title carries the markup it was written with, so a title of
`Ops **runbook**` is drawn with the word in bold rather than as asterisks.

---
layout: blank
name: A blank slide's title
level: 1
---

`blank` has no title box, so `level:` governs the body heading its title is drawn
as instead. `level: 2` would have made it the size a `##` is drawn at.

## And a body heading keeps its own depth

---
template: wordmark
---

:::content{left=4% top=3% width=40% height=6% fill=none padding=0 color=accent1 font-size=12 font-family=Calibri align=left vertical-align=middle}
**markdown**-office
:::

---
layout: blank
name: A slide drawn with a template
template: [wordmark, page]
---

This slide names two templates. `wordmark` is the one this deck defines below,
and `page` is the one `templates/chrome.md` — the file this deck's frontmatter
names — holds.

A template's frontmatter names the template and nothing else, which is what
tells a definition from a slide that draws one, and a definition is not a slide
of the deck: it is not drawn, it has no number, and it does not count towards
the number of slides.
