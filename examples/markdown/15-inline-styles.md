---
title: Inline styles
theme: corporate-dark
description: A word in a style of its own inside a box of its own, and the three things this half refuses.
---

# Inline styles

A box carries one colour, one size and one face, and a word takes them or not.
A **text style** is a name a theme gives to a set of those.
A word written in one is drawn in it.

Three forms, one grammar:

- `:accent[brand]` — the word in the style the theme names `accent`
- `:accent[brand]{color=FF0000}` — the same, with the attributes laid over the style's
- `:style[brand]{color=FF0000}` — the word in the attributes, with no style involved

The attributes are a closed set of eight, and this library draws seven of them.

---
layout: blank
---

# A word in a style, in a box of its own

:::content{left=6% top=30% width=88% height=13% color=accent3 font-size=26}
Sextant :brand[Instrument Works] — :muted[est. 1934]
:::

:::content{left=6% top=47% width=88% height=48% font-size=15}
Three words and three colours in one box.
`Sextant` says nothing about itself, so it takes the colour of the box it is in.
`Instrument Works` is in the theme's `brand` style, which is `accent1` and bold.
`est. 1934` is in `muted`, which is the quiet ink at fourteen points.

A style sets the properties it names and leaves the others alone.
`brand` names a colour and a weight, so the size of the word is the size of the box it is in.
:::

---
layout: blank
---

# The raw form, and a property laid over a style

:::content{left=6% top=30% width=88% height=11% color=lt2 font-size=20}
:style[Barge]{color=accent2 size=18} · :brand[No. 4]{color=accent2}
:::

:::content{left=6% top=43% width=88% height=54% font-size=15}
`:style[…]` needs no style of the theme's: it says the properties and nothing else.
So it works in a theme that names no styles at all.

The word after it is in the theme's `brand` style with one property laid over it.
`brand` is `accent1` and bold, and `color=accent2` replaces the colour for that word.
It is still bold, and it is still the size of the box.

A property that neither the style nor the attributes names is not set.
The word takes it from the block it is in.
:::

---
layout: blank
---

# The eight attributes

:::content{left=6% top=28% width=42% height=44% font-size=15}
- `color` — :style[accent1]{color=accent1}
- `size` — :style[26pt]{size=26}
- `font` — :style[monospaced]{font=mono}
- `bold` — :style[heavy]{bold=true}
:::

:::content{left=52% top=28% width=42% height=44% font-size=15}
- `italic` — :style[slanted]{italic=true}
- `underline` — :style[underlined]{underline=true}
- `strike` — :style[struck]{strike=true}
- `highlight` — the one this library does not draw
:::

---
layout: blank
---

# What this half refuses

A style the theme does not carry is refused when the deck is read.
The message names the styles the theme does have.
Written `:brands[Barge]`, it says:

```text
Slide 5 has the text style ":brands", which is not a style this theme has. The styles are: brand, muted.
```

A style's text is plain text, so `:brand[**Barge**]` is refused.
It is not drawn as the asterisks the author wrote:

```text
Slide 5 writes the text style ":brand" around "**Barge**", which is Markdown. A style's text is plain text: give the style the weight ("brand: { bold: true }") or write the emphasis in the paragraph around it.
```

---
layout: blank
---

# The eighth attribute

A highlight is a colour behind the word, and this library cannot write one.
The attribute is refused by name rather than accepted and quietly dropped.
Written `:brand[Barge]{highlight=accent4}`, it says:

```text
Slide 6 gives the text style ":brand" a highlight, which the slides half does not draw: a highlight is a colour behind the word, and this library has no way to write one.
```
