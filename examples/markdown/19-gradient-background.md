---
title: Gradients and backgrounds
description: A gradient on a shape, a background painted with one, and a slide with values of its own.
theme: default
layout: blank
---

::rectangle{left=5% top=18% width=19% height=20% gradient="accent1,lt2" gradient-angle=45}

:::content{left=4% top=39% width=21% height=11% font-size=12 align=center}
**rectangle** — two stops, running down to the right
:::

::roundrect{left=29% top=18% width=19% height=20% gradient="accent1,dk1@50,lt2" gradient-angle=90 border-radius=10}

:::content{left=28% top=39% width=21% height=11% font-size=12 align=center}
**roundrect** — three stops, the middle one at half
:::

::circle{left=55% top=18% height=20% gradient="accent2,accent1" gradient-radial}

:::content{left=51% top=39% width=26% height=11% font-size=12 align=center}
**circle** — radial, from the middle outwards
:::

::ellipse{left=78% top=18% width=17% height=20% gradient="dk1,lt1" gradient-angle=0}

:::content{left=75% top=39% width=23% height=11% font-size=12 align=center}
**ellipse** — at 0°, which is to the top
:::

:::content{left=5% top=54% width=90% height=12% font-size=12 align=center}
A gradient is written as stops, and a stop may carry the position it is at as a percentage of the gradient.
:::

:::content{left=5% top=64% width=90% height=12% font-size=12 align=center}
The angle is CSS's: 0° is to the top, 90° to the right, 180° to the bottom.
:::

:::content{left=5% top=76% width=90% height=12% font-size=12 align=center}
A shape is filled with a colour **or** with a gradient, and the two together are refused.
---
layout: blank
background: accent1
theme-variables:
  colors:
    dk1: "#FFFFFF"
title: A background of a colour
---

:::content{left=8% top=12% width=84% height=26% font-size=20 color=lt1 align=center vertical-align=middle}
The background of a slide is the slide's own.
:::

:::content{left=8% top=42% width=84% height=20% font-size=13 color=lt1 align=center vertical-align=middle}
The master keeps the theme's page colour for the slides that name no background.
---
layout: blank
background-gradient: "accent1,dk2"
background-angle: 45
theme-variables:
  colors:
    dk1: "#FFFFFF"
title: A background painted with a gradient
---

:::content{left=8% top=12% width=84% height=26% font-size=20 color=lt1 align=center vertical-align=middle}
A background may be a gradient too.
:::

:::content{left=8% top=42% width=84% height=20% font-size=13 color=lt1 align=center vertical-align=middle}
The stops are written the way a shape's are, and the colour a stop names is the colour it has on that slide.
---
layout: blank
background: "#1E1E2E"
theme-variables:
  dark: true
  colors:
    lt1: "#F5F5F7"
    accent1: "#7AA2F7"
title: A slide with values of its own
---

:::content{left=8% top=12% width=84% height=26% font-size=20 align=center vertical-align=middle}
A slide may name the theme's values for itself.
:::

:::content{left=8% top=42% width=84% height=20% font-size=13 align=center vertical-align=middle}
`theme-variables:` is a `theme.yml`: the same keys, the same names, read by the reader that reads the theme's own file.
---
layout: blank
background-gradient: "#1E1E2E,accent1"
background-radial: true
theme-variables:
  colors:
    dk1: "#FFFFFF"
title: A radial background
---

:::content{left=8% top=12% width=84% height=26% font-size=20 color=lt1 align=center vertical-align=middle}
A gradient that runs from the middle outwards.
:::

:::content{left=8% top=42% width=84% height=20% font-size=13 color=lt1 align=center vertical-align=middle}
A radial gradient has no angle, and naming one is refused.
---
template: brand
template-settings:
  background: "#1E1E2E"
  theme-variables:
    dark: true
    colors:
      lt1: "#F5F5F7"
---

::rectangle{left=0 top=0 width=100% height=0.7% fill=accent1}

---
layout: blank
template: brand
title: A template with settings of its own
---

:::content{left=8% top=12% width=84% height=26% font-size=20 align=center vertical-align=middle}
A template may carry settings of its own.
:::

:::content{left=8% top=42% width=84% height=20% font-size=13 align=center vertical-align=middle}
They are named as the template's, under `template-settings:`, and every slide that draws it gets them.
:::
