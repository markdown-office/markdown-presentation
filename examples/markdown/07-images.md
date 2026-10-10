---
title: Images
description: Fitted, centred, embedded, and converted on the way in.
---

# A picture takes the room left in its column

An image keeps its own proportions and sits in the middle of the box it is given.
The alt text is the shape's description, which is what PowerPoint's alt-text pane
shows and what a screen reader reads.

![The office logo](assets/logo.png)

---

# A picture the package cannot carry

A deck that names no alt text gets the path it wrote in its place, because an
empty description says nothing at all.

That picture is a **WebP**. A `.pptx` carries GIF, JPEG, PNG and SVG and nothing
else, so this one is decoded and re-encoded as PNG on the way in — the part in
the package really is a PNG, which is why a deck full of converted photographs is
several times the size it was written from.

![](assets/landscape.webp)

---
layout: two-column
---

# A picture in one column of two

![A diagram](assets/diagram.svg)

## The right column

A vector is taken on its extension and never measured, and it is the only one of
these three that is not re-encoded.

```php
$renderer = new PresentationRenderer(__DIR__);
$renderer->render($deck, 'deck.pptx');
```

Columns divide at the first `##` in the body, so everything above this one —
the picture included — is the left column.