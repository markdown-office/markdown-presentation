---
title: Code
description: Fenced blocks, the language in the shape's description, and one that does not fit.
---

# Code blocks

A fenced block is drawn in the theme's `mono` face, filled with `lt2`, and the
fence's info string is the shape's description — open the shape's alt text in
PowerPoint to read which language a block named. Nothing here lexes the language,
so no block is coloured.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$deck = (new DeckParser())->parse($markdown);
(new PresentationRenderer())->render($deck, 'deck.pptx');
```

---

## Two fences, one slide

```yaml
name: House style
colors:
  accent1: "#4472C4"
```

```
Nothing here is coloured. A fence with no language is drawn exactly like one
with a language nobody reads.
```

---

# A block taller than its slide

This block is far too tall for the room below a title. It is drawn as small as
the room allows rather than over whatever is under it, and the renderer says so
afterwards rather than letting a squeezed block pass as a rendered one.

```php
$deck = (new DeckParser())->parse($markdown);
$renderer = new PresentationRenderer(__DIR__);
$path = $renderer->render($deck, 'deck.pptx');

foreach ($renderer->overflows() as $overflow) {
    printf(
        "slide %d step %d column %d: %s wants %dpx, %dpx left\n",
        $overflow['slide'],
        $overflow['step'],
        $overflow['column'] + 1,
        $overflow['block'],
        $overflow['wanted'],
        $overflow['available'],
    );
}
```

A caller that would rather have no file than a file with a slide missing
something asks for `new PresentationRenderer(strict: true)` and gets a
`ContentOverflow` naming the slide, the step, the column and the block.