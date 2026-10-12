---
title: Steps
description: What a ::click marker becomes when a step is a click on one slide.
---

# A build-up, in three steps

A step marker on a line of its own opens a click, so a build-up is one slide
holding every step: run the slideshow and click. The first step is on the slide
before you click anything, and each click after it reveals the next.

- First step.

::click

- Second step.

::click

- Third step, which arrived two clicks after the first.

---

# Naming a step outright

A marker can name the step it opens, which is how a build-up skips the ones before
it. A step nothing was written on costs no click rather than a click that reveals
nothing.

::click=3

- Step three, written after a marker that named it.

---

# A region that is a step of its own

Three colons open one and three close it, so a build-up can be written as the
parts it is made of rather than as a marker between each of them.

:::click

- The second part, on its own click.

- And the line under it, on the same one.

:::

Both of the lists above arrive together: one click opens the step and the second
shape joins it, rather than each costing a click of its own.

---
layout: two-column
---

# A build-up across two columns

A two-column slide is written left column first, so the steps it asks for arrive
in an order that need not be the order they were read in. The clicks still run
in the order they were written.

Left, on the first step.

::click=3

Left, on the third step.

## The right column

::click

Right, on the second step — written after the third, and clicked before it.

---

# A picture arriving on its own click

A picture is a shape like any other, so it waits for its step the way everything
else does. It is measured into the room it will occupy on every step, whether or
not it has appeared yet — a build-up is laid out once, as its last click will
look, not redrawn click by click.

A picture takes the rest of the column it is in, so it ends one. A slide that
wants text beside a picture names an image role instead — see `image-text` in
Layouts.

The text beside it, on the first step.

::click

![The office logo](assets/logo.png)

---
layout: two-column
---

# A picture that finishes a two-column build

The two cases a build-up runs into on its own, together. A two-column slide is
measured column by column, and a picture takes the whole of the room it is given
whether or not it has appeared — so the column it lands in is laid out for it
before the click that shows it.

Left, on the first step.

::click

Left, on the second.

## The right column

::click

![A wide diagram under a headline](assets/landscape.webp)

---

# Showing a marker rather than running one

A marker is only a marker on a line of its own. Written the way this block is
written it is shown instead, because a marker inside a fenced block is a line of
code:

```
::click
```

A comment that is neither of this format's two spellings, nor the last thing on the
slide, is a failure naming the two spellings that would have worked.

---

# The speaker note for this slide

`::note` and a closing `::` carry what the presenter reads rather than what the
audience sees. Open the notes pane to read what this one was taken as.

::note

This line never reaches the slide. The deck's content is everything before the
opener, and the closing `::` ends the note.

::

---

# What a comment is now

A deck written for another tool can carry an HTML comment, and converting it
stops rather than drawing the comment on the slide.
