---
title: The five layout roles
description: One deck, five slides, one per role.
layout: title
---

# The five layout roles

A deck writes `layout: two-column`, never `layout: "Two Content"`. Each slide
below names the role it wants; the rest are whatever the theme's own template
calls them.

---
layout: content
---

# A content slide

The role a slide gets when it names none. One column under the title.

---
layout: two-column
---

# Two columns

Left column. Everything above the first `##` in the body is the left one, because
that is the heading a writer puts on a column of a slide and there is nothing else
to go on.

## The right column

Right column. A deck that never writes a `##` asks for two columns and fills one,
which is honest and which this library does not paper over.

---
layout: section
---

# A section break

Centred, lower down the page, and with a shorter body box underneath it.

The one-line sentence a section slide usually carries.

---
layout: blank
name: A blank slide
level: 1
---

`blank` has no title box — that is what makes it blank — so the title its
frontmatter named is drawn as a heading at the top of the body instead, and the
body gets the whole height of the page.

A heading in the body keeps its own depth whatever the frontmatter said about
the title. This one is a `##`, so it is drawn smaller than the title above it.

## And the body heading keeps its own depth