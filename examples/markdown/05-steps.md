---
title: Steps
description: What a v-click marker becomes when PowerPoint has no "press a key".
---

# A build-up, in three steps

PowerPoint has no "press a key to reveal this", so a step marker on a line of its
own becomes the slides a presenter would click through. This slide is written
three times: once with the first step on it, once with two, and once with three.

- First step.

<!-- v-click -->

- Second step.

<!-- v-click -->

- Third step, which arrived two clicks after the first.

---

# Naming a step outright

A marker can name the step it opens, which is how a build-up skips the ones before
it. The two steps in between carry whatever was written above the marker.

<!-- v-click=3 -->

- Step three, written after a marker that named it.

---

# Showing a marker rather than running one

A marker is only a marker on a line of its own. Written the way this block is
written it is shown instead, because a comment inside a fenced block is a line of
code:

```
<!-- v-click -->
```

A comment that is neither a marker on a line of its own nor the last thing on the
slide is a failure, naming the two spellings that would have worked.

---

# The last comment on a slide

A comment that runs to the end of a slide is its speaker note, so a directive
written in that position is a note rather than a refusal. Open the notes pane to
read what this one was taken as.

<!-- v-if="$clicks > 1" -->