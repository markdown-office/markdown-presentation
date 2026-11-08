---
title: Words over a picture
theme: default
description: A picture, a translucent panel over it, and the words on the panel.
---

# Words over a picture

A picture under words is a photograph with words on it, and the words are read against the
photograph rather than against the page. A panel between the two is what gives them something of
their own to be read against: a shape painted with a colour and an alpha, so that the picture
shows through it and the words sit on the panel.

The alpha is one percentage for the whole shape. It is written into the colour of the fill and
of the stroke, so the shape is seen slightly through the page rather than a fill and an outline
that disagree about how solid they are.

---
layout: blank
---

# The words, on the picture

::image{src="assets/landscape.webp" left=0 top=0 width=100% height=100% fit=stretch layer=back}

:::content{left=8% top=38% width=84% height=24% font-size=20 color=dk1 align=center vertical-align=middle}
**The words sit on the picture.** There is nothing between the words and the photograph, so
they are read against whatever the photograph happens to be — which is a thing the author of a
deck cannot see while the deck is written.
:::

---
layout: blank
---

# The words, on a panel

::image{src="assets/landscape.webp" left=0 top=0 width=100% height=100% fit=stretch layer=back}

::rectangle{left=0 top=0 width=100% height=100% fill=lt1 alpha=75 layer=back}

:::content{left=8% top=38% width=84% height=24% font-size=20 color=dk1 align=center vertical-align=middle}
**The words sit on a panel.** The picture is the same one, and the panel is the page's own
colour at 75% — so the picture shows through it and the words have something of their own to be
read against.
:::

:::content{left=8% top=66% width=84% height=18% font-size=13 color=dk1 align=center}
The panel is drawn before the words and after the picture, so it is between them. The library
knows the colour of the panel and the colour of the words, and not what the photograph is made
of — so how readable the words are is a thing to look at rather than a thing the library can
promise.
:::
