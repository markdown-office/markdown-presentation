---
title: Bleeding off the edge
theme: default
description: Shapes that run off the edge of the slide, and the one word that lets them.
---

# Bleeding off the edge

A shape that runs off the edge of the slide is a thing a deck may want: the grey circles
behind a title are meant to be cut off by the page, and a shape that cannot be written that
way has to be a picture of one.

`bleed=true` is the permission. It is on the shape, because the refusal it replaces is on the
shape too — a deck-level switch would silence the check for every shape in the deck, including
the ones nobody thought about, and a `left=-20` that was a typo is exactly what the check is
for.

A shape that bleeds still has to be **on** the slide: a shape whose whole box is past an edge
is a shape nobody will ever see, and `bleed=true` does not make it visible.

---
layout: blank
---

# A circle cut off by the right edge

::ellipse{left=82% top=8% width=34% height=52% fill=accent1 alpha=35 bleed=true}

:::content{left=8% top=38% width=58% height=20% font-size=20}
The circle is at a `left` of 82% and is 34% of the slide wide, so a third of it is past the
edge — and the shape says so with `bleed=true`.
:::

---
layout: blank
---

# A panel running under the foot of the page

::rectangle{left=-6% top=58% width=88% height=60% fill=panel bleed=true}

:::content{left=8% top=64% width=60% height=18% font-size=16}
A negative `left` is the same permission from the other side: the panel starts before the
left edge of the slide and runs under the bottom of it.
:::

---
layout: blank
---

# What the permission buys, and what it costs

:::content{left=6% top=30% width=88% height=58% font-size=15}
The refusal names both ways out, so an author who meant it can say so and an author who did
not is still caught:

> Slide 1 gives "ellipse" a left of "-20%". A length is not negative: a free shape lies
> within the slide unless it is written to bleed, so write left=0 rather than a negative one
> — or write bleed=true if the shape is meant to run off the edge.

The Word half has the same rule for the same thing: a text box hangs off the edge of the page
on `bleed=true` as well, so a document and a deck are written the same way.
:::
