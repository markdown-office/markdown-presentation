---
theme: default
---

---
layout: blank
---

# Free shapes

Every shape on this slide is placed by the deck rather than by the layout. They are written in
fractions of the slide, coloured in theme slots, and one of them is behind the text.

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

::ellipse{left=30% top=42% width=20% height=14% fill=none stroke=accent1 stroke-width=2}

::image{src="assets/logo.png" left=58% top=40% width=30% height=20% fit=contain alt="The logo"}

:::content{left=6% top=74% width=88% height=16% font-size=14 align=center}
The ellipse is 20% of the slide wide and 14% of it tall; the circle is 14% of the height on
both axes, which is what a circle's percentages resolve against.
:::

---
layout: blank
---

# What the shapes draw

Nine ink shapes and a picture. Each is one shape, and a rule is a rectangle a pixel tall
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