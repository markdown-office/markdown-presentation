---
title: One deck, two type scales
theme: default
author: markdown-office
description: The same slides at the default type scale and at a small one.
---

# One deck, two type scales

`build.php` writes this source twice, changing nothing but the `theme:` line:
once as `default` and once as `dense`. The words are in the same order in both,
and the two files in `out/` differ in the size of the type and in the gaps around
it.

## Why the same words twice

A deck that says its spacing scales proves nothing by itself, because the type is
a different size too, and any difference in the two renders could be either
thing. What is left once the words are taken out of it is the pair of pages: the
same text at two sizes, with every gap around it set by a theme.

---

## The blocks

Every gap on this slide is one the theme sets, or a fraction of one: the air
under the title above, the gap after the paragraph, and the smaller gap a list
leaves between its own items.

- A list item, so the gap between two items can be compared with the gap between
  the paragraphs above it
- A second item with enough words in it to show where the measure ends
- A third item, short

A list item's text starts one `gutter` in from the edge of its column, and one
more for each level of nesting. A gutter is an indent rather than a gap, and it
does not change with the type scale the way the vertical air does.

---

## A heading, a table and a block of code

A heading's air follows the heading's own size rather than `block:`, and a table
or a code block takes more of the air than a paragraph does: a border is painted
to the edge of its box and a paragraph's ink is not.

| Theme | Body | Rule | Leading |
| :---- | ---: | ---: | ------: |
| `default` | 16pt | 2px | 1.2 |
| `dense` | 13pt | 1px | 1.1 |

```php
printf("%dpt body, %dpx after a paragraph\n", $style->body(), $style->blockGap());
```

Whether the gaps round to the size of the type is what these two files are for.
