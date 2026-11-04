---
title: The deck's own settings
description: The settings of the deck, on every slide of it, and the slides that name one of their own.
theme: default
layout: blank
deck-settings:
  background: "#F4F1E9"
  theme-variables:
    colors:
      dk1: "#3A2E22"
---

:::content{left=8% top=26% width=84% height=22% font-size=22 align=center vertical-align=middle}
Every slide of this deck is drawn with the deck's own settings.
:::

:::content{left=8% top=52% width=84% height=26% font-size=14 align=center vertical-align=middle}
`deck-settings:` is written in the front matter above — which is slide 1's, and so the deck's own — and it holds the same keys a slide's own settings are written in. The page behind this slide and the ink it is written in are the deck's.
---
layout: blank
title: And so is every other slide
---

:::content{left=8% top=26% width=84% height=22% font-size=22 align=center vertical-align=middle}
This slide names nothing at all.
:::

:::content{left=8% top=52% width=84% height=26% font-size=14 align=center vertical-align=middle}
It is drawn with the deck's page and the deck's ink, because the deck's settings are the ones every slide of the deck starts from.
---
layout: blank
background: "#CFE0F0"
title: And a slide's own wins
---

:::content{left=8% top=26% width=84% height=22% font-size=22 align=center vertical-align=middle}
This slide names a background of its own.
:::

:::content{left=8% top=52% width=84% height=26% font-size=14 align=center vertical-align=middle}
It is painted on it, and not on the deck's. The precedence is slide → template → deck → theme: a slide's own settings are laid over the template's, the template's over the deck's, and the deck's over the theme's.
---
layout: blank
theme-variables:
  colors:
    dk1: "#1E3A5F"
title: One value over another leaves the rest alone
---

:::content{left=8% top=26% width=84% height=22% font-size=22 align=center vertical-align=middle}
This slide names an ink of its own, and no background.
:::

:::content{left=8% top=52% width=84% height=26% font-size=14 align=center vertical-align=middle}
So it is painted on the deck's page and written in its own ink: the deck's background is still the one behind it.
