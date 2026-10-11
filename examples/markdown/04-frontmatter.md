---
title: Every frontmatter key
description: One slide per key, and what each one reaches.
theme: minimal
author: markdown-office
---

# Every frontmatter key

Seven keys: `title`, `name`, `level`, `layout`, `theme`, `author` and
`description`. A block naming an eighth is a failure that names these seven, and
this slide carries the first four.

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
