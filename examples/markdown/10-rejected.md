---
title: A deck that is not built
description: Converting this is the failure it exists to demonstrate.
---

# Conditional content, the way Slidev writes it

<div v-if="$clicks > 1">

Slidev shows this paragraph once the presenter has clicked past the first step.
Nothing here can draw it, so the conversion stops here.

</div>

---

# The step this slide would have opened with

A `::click` marker on a line of its own is a step this library does draw, and it
is the only element in Slidev's vocabulary that it does. Everything else in a
slide's body — a `v-*` element, a `:::` container fence, a `::name` leaf this
library does not read, an HTML comment — is a failure naming the spelling that
would have worked.

This deck is not built. `build.php` leaves it alone, because converting it is the
failure it exists to demonstrate.
