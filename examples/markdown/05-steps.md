---
title: Steps
description: What a ::click marker becomes when PowerPoint has no "press a key".
---

# A build-up, in three steps

PowerPoint has no "press a key to reveal this", so a step marker on a line of its
own becomes the slides a presenter would click through. This slide is written
three times: once with the first step on it, once with two, and once with three.

- First step.

::click

- Second step.

::click

- Third step, which arrived two clicks after the first.

---

# Naming a step outright

A marker can name the step it opens, which is how a build-up skips the ones before
it. The two steps in between carry whatever was written above the marker.

::click=3

- Step three, written after a marker that named it.

---

# A region that is a step of its own

Three colons open one and three close it, so a build-up can be written as the
parts it is made of rather than as a marker between each of them.

:::click

- The second part, on its own slide.

- And the line under it, on the same one.

:::

The two lists above are one step and one shape each; open the second slide of this
deck to see the difference.

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
