---
title: "Ops **runbook**: what *changed*"
description: The eight things a paragraph turns into runs.
---

## A paragraph is not a string

**Bold**, *italic*, ***both***, `code`, ~~struck~~ and
[a link](https://github.com/markdown-office/markdown-office) are each their own
run, drawn with their own weight, face and colour.

***

## Emphasis is deliberately not CommonMark's

A delimiter that does not close is left as the characters it was written as, so
**unclosed is text. An underscore inside a word is not a delimiter, so
snake_case_name is a name rather than two words with an underscore between them.
The asterisks and backticks inside a code span are characters, so `**not bold**`
is a code span.

---

# A *release* **v2** runbook

A title carries the same inline markup any other text does, and it is drawn once:
the heading above is this slide's title, and no shape on the slide says it a
second time. The deck's own `dc:title` carries the words in plain text — this
deck's frontmatter title is `Ops **runbook**: what *changed*` — because nothing
on the other end of a metadata field reads a pair of asterisks.

## A heading in the body carries it too

`# Release **v2** notes` is a slide titled *Release v2 notes* rather than a slide
with the asterisks on it.

A link is drawn in the theme's `hlink` colour and underlined whatever it is
written inside: this [link is in a **heading**](https://example.com), and so is
[a link in a quote](https://example.org).