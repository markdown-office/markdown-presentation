---
title: Tables
description: Alignment, markup in cells, and what a cell taller than its row does.
---

# Tables

A pipe row under an alignment row is a table. The header row is bold and filled
with `lt2`, the alignment comes from the row of colons, and the columns are as
wide as what they hold.

## Alignment

| Left | Centre | Right | Notes |
| :--- | :----: | ----: | :---: |
| left | centre | right | centre |
| 1 | 2 | 3 | 4 |

---

# Markup in cells

A cell is a paragraph's worth of text and takes the same inline markup one does.

| Key | Written | Comes out |
| :--- | :------ | :------- |
| bold | `**bold**` | **bold** |
| italic | `*italic*` | *italic* |
| both | `***both***` | ***both*** |
| code | `` `code` `` | `code` |
| struck | `~~struck~~` | ~~struck~~ |
| link | `[text](https://example.com)` | [text](https://example.com) |

---

# A cell taller than its row

A table is measured at the height it is written at rather than at one row per
line, so a cell that wraps to three lines makes the whole row three lines tall.

| Column | What it holds |
| :----- | :------------ |
| A narrow column | A sentence long enough that the measurement has to wrap it, which is what makes this row taller than the ones above it. |
| B | Short. |
| C | Also short. |

---

# A ragged row

The header row decides how many columns there are. A row with fewer cells keeps
the cells it has, and a row with more is drawn as far as the header goes.

| One | Two | Three |
| :-- | :- | :---: |
| a | b |
| a | b | c | d |
