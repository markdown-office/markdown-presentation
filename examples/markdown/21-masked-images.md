---
title: A picture in a shape
theme: default
description: Pictures drawn in a shape rather than in a rectangle.
---

# A picture in a shape

A picture is drawn in a rectangle, and a picture can be drawn in another shape instead. The
shape is the picture's own, not a frame drawn around it: the file writes it as the geometry of
the picture, which is what PowerPoint writes for a picture cropped to a shape.

`mask` names the shape, and a name outside the list below is refused with the list rather than
passed on to the file as a shape nobody can draw.

- `circle` `ellipse` `roundrect` `triangle` `diamond` `hexagon` `chevron` `arrow`
- `star` `heart` `teardrop` `cloud` `moon` `donut` `frame` `plaque`

The mask is a shape and not a size: the picture keeps the box it was given, and the shape is
drawn in it. A `circle` and an `ellipse` are the same shape, and the picture's own proportions
decide which of the two it looks like.

---
layout: blank
---

# The same picture in six shapes

::image{src="assets/logo.png" mask=circle left=6% top=30% width=20% height=20% fit=contain alt="The logo in a circle"}

::image{src="assets/logo.png" mask=hexagon left=30% top=30% width=20% height=20% fit=contain alt="The logo in a hexagon"}

::image{src="assets/logo.png" mask=star left=54% top=30% width=20% height=20% fit=contain alt="The logo in a star"}

::image{src="assets/logo.png" mask=heart left=78% top=30% width=20% height=20% fit=contain alt="The logo in a heart"}

::image{src="assets/logo.png" mask=teardrop left=6% top=58% width=20% height=20% fit=contain alt="The logo in a teardrop"}

::image{src="assets/logo.png" mask=roundrect left=30% top=58% width=20% height=20% fit=contain alt="The logo in a rounded rectangle"}

:::content{left=54% top=58% width=42% height=28% font-size=15}
Six pictures, one file, six shapes. The mask is written into the picture and travels with it,
so a picture that was masked in a deck and opened in PowerPoint is a picture cropped to that
shape — and can be cropped to another one there.
:::
