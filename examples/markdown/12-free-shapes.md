---
title: Free shapes
theme: default
description: Shapes a deck places itself, beside the ones a layout places.
---

# Free shapes

Twelve directives in the body of a slide put a shape where the deck wants it rather than where
the layout would. They are written in the two-colon syntax the step markers use, measured in
fractions of the slide, and coloured in theme slots so they follow the theme.

The three slides after this one are all `layout: blank`, which is what most of them want: a
slide whose whole content is free shapes has no title to place them around.

- `left` `top` `width` `height` — where the box is
- `anchor` — which corner those two name
- `layer` — before the layout's own shapes or after them
- `fill` `stroke` `alpha` `stroke-width` — what it is painted with
- `:::content` — Markdown, in a box the deck sized

---
layout: blank
---

# A panel, a badge and a chevron

One of these shapes is behind the text and two are over it, and none of them is in a column.

::rectangle{left=4% top=30% width=92% height=64% fill=panel layer=back}

::roundrect{left=72% top=34% width=24% height=10% fill=accent1 alpha=88}

::chevron{left=4% top=34% width=5% height=10% fill=none stroke=accent1 stroke-width=2}

:::content{left=12% top=36% width=56% height=6% font-size=20 vertical-align=middle}
## Fill, stroke, alpha
:::

:::content{left=8% top=47% width=52% height=42% font-size=15}
The panel is `layer=back`, so it is written before the layout's own shapes and the text is drawn
over it. The badge and the chevron have no layer, so they are written after everything else.

- `left=4% top=30%` is the panel's corner; `anchor` says which one
- `alpha=88` is the badge, and it applies to the whole shape
- `fill=none stroke=accent1` is the chevron — an outline and no fill
:::

:::content{left=64% top=48% width=32% height=40% font-size=13 align=center vertical-align=middle}
The colours are theme slots rather than hex, so this slide is one `theme:` line away from
looking different.
:::

---
layout: blank
---

# Anchoring, and a picture in a box

`anchor=center` puts the box's middle where the two numbers say rather than its corner, which
is the only way a percentage is any use for centring.

::circle{left=30% top=42% width=14% height=14% fill=accent3 anchor=center}

::ellipse{left=30% top=42% width=20% height=14% fill=none stroke=accent1 stroke-width=2 anchor=center}

::image{src="assets/logo.png" left=58% top=40% width=30% height=20% fit=contain alt="The logo"}

:::content{left=6% top=72% width=88% height=20% font-size=14 align=center}
Both shapes are `anchor=center` on the same two numbers, so they share a centre. The ellipse is
20% of the slide wide and 14% of it tall; the circle is 14% of the height on both axes, which is
what a circle's percentages resolve against.
:::

---
layout: blank
---

# What the shapes draw

Ten ink shapes and a picture. Each is one shape, and a rule is a rectangle a pixel tall
rather than a connector — which is what a `---` has always been.

::rectangle{left=6% top=34% width=11% height=12% fill=accent1}

::roundrect{left=20% top=34% width=11% height=12% fill=accent1 border-radius=12}

::circle{left=34% top=34% width=12% height=12% fill=accent2}

::ellipse{left=49% top=35% width=14% height=10% fill=accent2}

::triangle{left=66% top=34% width=9% height=12% fill=accent3}

::diamond{left=79% top=34% width=9% height=12% fill=accent3}

::hexagon{left=6% top=52% width=13% height=11% fill=accent4}

::chevron{left=23% top=52% width=13% height=11% fill=accent4}

::arrow{left=40% top=52% width=16% height=11% fill=accent5}

::line{left=6% top=70% width=88% height=1% fill=accent1}

::image{src="assets/logo.png" left=66% top=51% width=24% height=13% fit=contain}

:::content{left=6% top=76% width=88% height=18% font-size=14}
A free shape sits on the step it was written on, so a `::click` above one reveals it like any
other shape — and a shape that does not fit its box is clamped to it and reported by
`overflows()` rather than quietly losing its last line.
:::