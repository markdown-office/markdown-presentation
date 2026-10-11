---
title: Every construct on one deck
theme: default
---

# Every construct on one deck

A paragraph with **bold**, *italic*, ***both***, `code`, ~~struck~~ and a
[link](https://github.com/markdown-office/markdown-office) in it.

Emphasis that does not close is left as it was written: 2 * 3 and **unclosed are
both text, and so is snake_case_name.

---

## Lists

- A bullet with **bold** in it
- Another bullet
    - Four spaces of indent are one level, not two
    - And a sub-bullet under it
- A third bullet

1. First
2. Second
3. Third

7. An ordered list starts at the number the deck wrote
8. Eight, not seven

---

## Tables

| Left | Centre | Right | A rather long column heading |
| :--- | :----: | ----: | --------------------------- |
| **bold** | `code` | ~~struck~~ | plain |
| 2 * 3 | a | b | c |

---

## Quotes, code and rules

> A quote written over two lines is one shape,
> not two.

```php
$deck = (new DeckParser())->parse($markdown);
(new PresentationRenderer())->render($deck, 'deck.pptx');
```

***

***

::note
A note comes off the content and lands in the notes pane. Nothing above it
changed.
::

