---
title: Shadow types on different objects
theme: default
layout: blank
---


::rectangle{left=6% top=19% width=17% height=18% fill=lt2 shadow-distance=6 shadow-blur=4 shadow-alpha=45}

:::content{left=6% top=38% width=17% height=13% font-size=12 align=center}
**rectangle** — a short, soft shadow straight down
:::

::roundrect{left=29% top=19% width=17% height=18% fill=lt2 border-radius=10 shadow-distance=10 shadow-blur=0 shadow-alpha=60}

:::content{left=29% top=38% width=17% height=13% font-size=12 align=center}
**roundrect** — hard-edged, no blur at all
:::

::circle{left=55% top=19% height=18% fill=lt2 shadow-distance=0 shadow-blur=12 shadow-color=accent1 shadow-alpha=70}

:::content{left=50% top=38% width=26% height=13% font-size=12 align=center}
**circle** — no offset, only a glow
:::

::hexagon{left=78% top=19% width=16% height=18% fill=lt2 shadow-distance=12 shadow-blur=6 shadow-direction=45 shadow-alpha=50}

:::content{left=75% top=38% width=21% height=13% font-size=12 align=center}
**hexagon** — cast down and to the right
:::

:::content{left=6% top=52% width=30% height=18% fill=lt1 stroke=accent1 stroke-width=2 shadow-distance=8 shadow-blur=6 shadow-direction=90 shadow-color=accent1 shadow-alpha=55 padding=18 font-size=13 vertical-align=middle}
A **`:::content`** block takes a shadow too, and the shadow is around the block rather than
around the words in it.
:::

::image{src="assets/logo.png" left=46% top=52% width=20% height=18% fit=contain shadow-distance=8 shadow-blur=8 shadow-alpha=55}

:::content{left=42% top=71% width=28% height=9% font-size=12 align=center}
**::image** — a `p:pic` carries an effect list of its own
:::

::diamond{left=78% top=52% width=16% height=18% fill=accent2 shadow-distance=16 shadow-blur=10 shadow-direction=180 shadow-alpha=40}

:::content{left=74% top=71% width=22% height=9% font-size=12 align=center}
**diamond** — thrown upward, at 40%
:::

:::content{left=6% top=82% width=88% height=12% font-size=12 align=center}
Five attributes: `shadow-color`, `shadow-distance`, `shadow-blur`, `shadow-direction` and `shadow-alpha`.
Distance and blur are pixels; the direction is degrees.
:::
