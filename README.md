# markdown-presentation

The PowerPoint half of [markdown-office](https://github.com/markdown-office/markdown-office):
a **deck splitter**, a **theme resolver** and a **renderer** for Markdown slide decks, in
PHP with no binaries and no Office.

The separator grammar is derived from Slidev's, pinned to a commit and checked
against it on every build — see [Conformance](#conformance) for what matches, what
does not, and why.

```php
use MarkdownPresentation\Document\DeckParser;

$deck = (new DeckParser())->parse("# One\n\n---\n\n# Two\n");

foreach ($deck->slides() as $slide) {
    echo $slide->index(), ': ', $slide->title(), "\n";
}
```

```text
0: One
1: Two
```

## Installation

```sh
composer require markdown-office/markdown-presentation
```

PHP 8.2 or newer, `symfony/yaml` and `phpoffice/phppresentation` as hard dependencies: the
frontmatter of a deck and every file of a theme is YAML, and a partial parser is worse for
the machines that write them than for the people that do.

**The project that installs this one has to allow a plugin.** This library writes a few
things a released PHPPresentation cannot — a table that says which row is its header, a
shape that says how round its corners are, a picture that says where it was read from — and
gets them from a patch applied to the installed package. The patch is applied by
[`cweagans/composer-patches`][composer-patches], which Composer only runs when the project
allows it:

```json
{
    "config": {
        "allow-plugins": {
            "cweagans/composer-patches": true
        }
    }
}
```

Without that line the install stops with Composer's own message naming the plugin. The
library refuses to work rather than write a deck with the parts missing, and says which
plugin is the reason.

`ext-imagick` is optional and enables [SVG](#what-an-svg-costs-you). PowerPoint holds a
vector only beside a raster of it, so something has to draw the raster; without the
extension an SVG raises rather than going in as a picture no reader can show.

## Command line

There is none in this repository. The command line is [`mdoffice`][mdoffice], one program
for Markdown to Word and to PowerPoint, which requires this package and calls it rather
than reimplementing any of it.

Every command, every option and every default is in the
[documentation][command-line] — a chapter of its own, with the options of each half on a
page of their own — and that is where they are kept, so there is one place to look and
one place to correct.

## Splitting a deck

A deck is Markdown. Slides are separated by a line of three dashes, and each slide
may carry its own frontmatter. The deck's frontmatter is the first slide's.

```markdown
---
title: Deck
---

# One

---

# Two
```

```html
<ol class="deck">
<li class="slide" data-index="0">
<dl class="frontmatter">
<dt>title</dt><dd>Deck</dd>
</dl>
<pre># One</pre>
</li>
<li class="slide" data-index="1">
<pre># Two</pre>
</li>
</ol>
```

What the splitter produced, read through the API:

```php
use MarkdownPresentation\Document\DeckParser;

$deck = (new DeckParser())->parse("---\ntitle: Deck\n---\n\n# One\n\n---\nlayout: two-column\n---\n\n## Left\n\n::column\n\nRight.\n");

echo $deck->frontmatterValue('title'), "\n";
echo $deck->count(), " slides\n";

foreach ($deck->slides() as $slide) {
    echo $slide->index(), ' ', $slide->title(), ' level ', var_export($slide->level(), true), "\n";
}
```

```text
Deck
2 slides
0 Deck level NULL
1 Left level 2
```

The first slide's title is the deck's `title:` when the block carries one, because
that is where Slidev reads the deck's configuration from. A deck whose first slide
needs a title of its own puts a `name:` in that block instead — and `title:` wins
where a block has both.

### The dialect

This is a port of [`parseSync`](https://github.com/slidevjs/slidev/blob/main/packages/parser/src/core.ts)
in Slidev's parser, and matching it matters more than being tidy: the dialect that
models already write for Slidev is the one worth reading. The parser describes
itself, so this page does not have to:

```php
echo MarkdownPresentation\Document\DeckParser::dialect();
```

```text
A `---` line closes the slide being read.

`---` on its own, with something after it on the next line, opens a
frontmatter block that the next `---` closes. A top-level thematic break
cannot be written as a bare `---` inside a slide: `***` is one, and
`----` is not.

A fenced code block is skipped whole, so a `---` inside one cannot split
a slide. Backticks or tildes, three or more. The closing fence is the
same character, at least as long, indented no more than the opener, and
carrying nothing else. A fence that is never closed runs to the end of
the deck, so a `---` inside it does not split either.

Those are CommonMark's rules. Slidev matches the closing fence with
`startsWith` on the raw line, which is stricter in one direction and
looser in the other; `tests/fixtures/slidev/divergences.json` records
each case and why it differs.

An HTML comment is tracked across lines, so a `---` inside one cannot
split a slide either.

The first block is the deck's frontmatter. It is the first slide's, not a
block of its own: a deck that opens with a `---` block and then a slide
is one slide carrying both, and `Deck::frontmatter()` returns its keys.

`::click` and `:::click` are left in the content; they are the renderer's
step markers, not the splitter's.

A speaker note is `::note` on a line of its own, its text, and a
closing `::`, in either case. A `::note` nothing closes is part of
the slide's text, and the renderer refuses it rather than drawing it.

Two shapes are refused rather than read:

- Frontmatter in a fenced `yaml` block. Slidev reads it as frontmatter;
  here it would be a code block, so a slide opening with one is an error.
- A `title`, `name` or `level` that is not the type it has to be.
```

Three of those lines are worth reading twice.

**A `---` inside a fence is inert.** So is one inside an HTML comment:

````markdown
# One

```
---
not a slide
---
```

---

# Two
````

````html
<ol class="deck">
<li class="slide" data-index="0">
<pre># One

```
---
not a slide
---
```</pre>
</li>
<li class="slide" data-index="1">
<pre># Two</pre>
</li>
</ol>
````

**A thematic break is `***`, not `---`.** Because `---` is a delimiter, a top-level
thematic break inside a slide cannot be written as one. Slidev behaves the same
way, and matching it keeps the dialect predictable for the models trained on it.

**An unterminated fence is not skipped.** The splitter looks for the closing fence
first and only steps over the block once it has found one, so a deck with a fence it
never closes has every line after that fence read as ordinary content — a `---` in it
splits the slide after all.

### Speaker notes

A `::note` block is a slide's speaker note, and comes off the content. `::note`
and the closing `::` each have to be on a line of their own:

```php
use MarkdownPresentation\Document\DeckParser;

$deck = (new DeckParser())->parse("# One\n\n::note\nRead this out loud.\n::\n");

echo $deck->slides()[0]->note(), "\n";
echo $deck->slides()[0]->content(), "\n";
```

```text
Read this out loud.
# One
```

## Rendering

`PresentationRenderer` takes a split deck and writes a `.pptx`. It resolves the theme
itself, from the deck's `theme:`, so there is nothing to resolve first.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$deck = (new DeckParser())->parse("---\ntheme: corporate-dark\ntitle: A deck\n---\n\n# One\n\nTwo.\n");
$path = sys_get_temp_dir() . '/deck.pptx';

(new PresentationRenderer())->render($deck, $path);

$zip = new ZipArchive();
$zip->open($path);

$parts = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $parts[] = $zip->getNameIndex($i);
}

echo count(preg_grep('#^ppt/slides/slide\d+\.xml$#', $parts)), " slides\n";
echo str_contains($zip->getFromName('ppt/slides/slide1.xml'), 'Two.') ? "text kept\n" : "text lost\n";
preg_match('#<p:cSld name="([^"]*)"#', $zip->getFromName('ppt/slideLayouts/slideLayout1.xml'), $m);
echo 'layout: ', $m[1], "\n";
```

```text
1 slides
text kept
layout: Title and Content
```

A deck that names no theme gets the built-in `default`. That is the absence of a directive,
not a fallback from a directive that failed: a theme that does not resolve is still a failure,
and it names the themes that do.

### What a slide becomes

Every shape of a slide is named after the kind of thing it was drawn from, so the mapping can
be read off `ppt/slides/slideN.xml` without the library:

| Markdown | Shape | Notes |
| --- | --- | --- |
| `# Heading` | `title`, then `text` | The first heading becomes the title and is not drawn twice, whatever markup it carries. A heading in the body is a paragraph of the frame around it, at the size of its level |
| A paragraph | `text` | `**bold**`, `*italic*`, `***both***`, `__bold__`, `_italic_`, `~~struck~~`, `` `code` `` and `[text](url)` are runs, not characters. A link target is checked — see [Links](#links) |
| `- a` / `1. a` | `text` | Ordered lists are numbered, not bulleted, from the number the deck writes; nesting is the indent |
| ```` ```lang ```` | `code` | Mono font, filled with `bg2`, the language in the shape's description. No colouring |
| `\| a \| b \|` under an alignment row | `table` | Alignment comes from the row; a ragged row keeps the cells it has; a cell carries the same inline markup a paragraph does. The header row is bold, filled with `bg2`, and the columns are as wide as what they hold |
| `> a` | `text` | Italic, in `tx2`, indented by a paragraph margin. Written over several lines it is one paragraph |
| `***` | `rule` | A bar in `accent1`. `---` is a slide delimiter and never reaches here |
| A line that is only `![alt](path)` | `image` | Fitted to its box, aspect kept, centred. The alt text is the shape's description, and the written path stands in where the deck named none. A path that is not a file is a failure |

A title is text like any other: a frontmatter `title: Ops **runbook**` is drawn with the
word in bold rather than as asterisks, and the deck's own `dc:title` carries the same title as
plain text, because nothing on the other end of a metadata field reads a pair of asterisks.

### One box for a run of text

A run of paragraphs, lists, headings and quotes is drawn as **one** shape holding one
paragraph each — the way a person builds a slide, by clicking into a placeholder and typing.
Three paragraphs with a list between them are one box, so clicking any of them selects all
of them, moving one moves the lot, and PowerPoint lays them out inside the one box they were
measured for rather than six boxes that might not agree about where they are.

A heading and a quote are in that box because in the file they *are* paragraphs: a heading is
one whose runs carry the heading's size, weight, face and ink, and a quote is one indented,
italic and in the quiet ink. markdown-word has always said it the same way, as `Heading1` and
`IntenseQuote` — paragraph styles positioned by the reader rather than by us.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$deck = (new DeckParser())->parse("# One\n\nFirst.\n\nSecond **bold** one.\n\n- An item.\n\nLast.\n");
$path = sys_get_temp_dir() . '/deck.pptx';
(new PresentationRenderer())->render($deck, $path);

$zip = new ZipArchive();
$zip->open($path);

foreach (preg_split('#(?=<p:sp>)#', $zip->getFromName('ppt/slides/slide1.xml')) as $shape) {
    if (preg_match('#<p:cNvPr id="\d+" name="([^"]+)"#', $shape, $name) !== 1) {
        continue;
    }

    printf("%s: %d paragraphs\n", $name[1], substr_count($shape, '<a:p>'));
}
```

```text
title: 1 paragraphs
text: 4 paragraphs
```

A rule, a table, a picture and a code block are shapes of their own, and any of them ends the
run: they are not type, or they are type with something painted behind them. A fill or a
border over part of a text frame is not something the format can express, so a code block
keeps its panel by being a box.

### The air between blocks

The gap is not one number. `block:` sets the gap **after a paragraph**, and everything else
is a fraction of it or a function of its own type, because a block reads differently
depending on what it is next to:

| Between | What decides it | At the library's own numbers |
| --- | --- | --- |
| two paragraphs | `block:` | 12px |
| two lists | a quarter of `block:` a side | 6px |
| a paragraph and a table, a code block, a rule or a picture | 0.9 of `block:` a side | 17px |
| a heading and the text above it | 0.7 em of the **heading's own size**, plus the 6px the paragraph leaves | 19px + 6px = 25px |
| a heading and the text below it | 0.35 em of the heading's size, plus the 6px the paragraph leaves | 9px + 6px = 15px |

Two of those rows are about the heading and one is about the list, and both are worth reading
twice. A heading asks for air in proportion to its own size rather than to `block:`, so at a
20pt `##` it asks for 19px above and 9px below — but the gap a reader sees is that **plus** what
the paragraph beside it leaves, which is why the row says 25px and not 19px. And the 6px is the
air between two *lists*: the items of one list are lines of one frame and are set with no gap
between them at all, which is what makes a list read as a list rather than as a run of
paragraphs.

Two things in that table are worth reading twice. A block with an edge of its own takes more
of the air than a paragraph, because the ink of a paragraph stops short of the bottom of its
box — by the text inset, and by the slack in the last line's leading — while a table's border
is drawn exactly on the edge; the same number separates a paragraph from a paragraph further
than it separates a paragraph from a table. And a heading's air is a function of how big the
heading is, so a theme with a larger `headings` scale gets more room around its headings
rather than the same room around much bigger type. It is more above than below, because a
heading belongs to the text that follows it — which is why markdown-word writes a heading's
`space before` at twice its `space after`, and puts `keepNext` on top of that.

One gap, spent once, in either of its two places: the air between two paragraphs is written
into the shape as `a:spcAft` and `a:spcBef`, and the same number is added to the cursor
between two shapes of a column. A frame measured with the gap *and* written with it is a gap
taller than the text it separates. Each paragraph carries half a gap at each end rather than
a whole one, because PowerPoint adds a paragraph's spacing before and after rather than
taking the larger of the two — a gap carried entirely on one side reads as a bigger gap above
a paragraph than below it.

The gap between two shapes is the air below the first added to the air above the second. A
block that starts a frame carries its own: a frame that opens on a heading has to clear the
heading's air, and one that opens on a paragraph has nothing to clear but the block above it.

One thing about that gap is worth knowing, because it is measured rather than assumed:
**PowerPoint spends a paragraph's spacing after every break the text contains — a newline
inside a paragraph, or the mark between two paragraphs — and not after a line the text merely
wrapped onto.** Three paragraphs written on one line each and wrapped to two apiece are
therefore six lines and two gaps, not six lines and five. The frame is measured that way, so
what it holds is exactly what it was given room for and nothing can be drawn over it. A
paragraph the writer broke across four source lines and its face wrapped to seven carries a
gap after each of its three newlines and none after any of the four wraps, which is also what
makes a per-line measurement wrong by however many lines a deck happens to wrap onto.

A frame of text that does not fit its slide is clamped to the room left and reported as one
shape, because one is what it is.

Shapes stack down the body box, each one starting where the one above it ended with the gap
the pair of them asked for. Nothing is added after the last one: room below the last shape of
a column is the column's, and spending it there is what pushes a slide off its own bottom.

A title is a box of its own above the body, and it does not grow into it: the band a title may
occupy is the room between the top it is drawn at and the top of the body box, less the air
underneath it, so a title that wraps onto a second line has its type shrunk to fit rather than
putting its second line on the first line of the body. The body is read in the same place on
every slide, which is why the title gives way and not the body.

Every block is measured before it is drawn: `Render\TextMetric` counts the lines it will
wrap to, from the advance widths of the face the theme draws the text in. There is a table
for every face the shipped themes name — Calibri, Cambria, Arial, Arial Narrow, Georgia,
Inter and Roboto — and a bold run is measured with the widths of the bold face rather than
with the upright ones made a little wider. The monospaced faces are measured by their
advance rather than by a table, because every character in one advances by the same amount:
Consolas advances by 550 units to the em, Menlo by 602 and JetBrains Mono by 600, so a code
block is measured in the face that will draw it. It is an estimate and says so: kerning is
not modelled, and a theme naming a face with no table of its own is measured with the
reference face's widths — the ones every face was measured with before there were tables of
their own — and the face is named by `unknownFonts()`. Which is why every text shape is
written `a:normAutofit`. The box keeps the height the layout gave it and the reader scales
the type down to fit, so an estimate that came out a line short costs a little whitespace
instead of drawing one block over the next.

### Links

A link's target becomes a live hyperlink in the file, which means the target is not a
question about how a slide looks but about what happens when a reader clicks it. So it is
checked where the deck is read rather than when the file is opened:

| Target | Carried? | Why |
| --- | --- | --- |
| `https://…`, `http://…`, `mailto:…` | yes | a URL a reader opens in a browser or a mail client |
| `docs/notes.md`, `logo.png` | yes | a path relative to the deck; nothing on the reader's machine acts on it |
| `javascript:…`, `data:…`, `vbscript:…` | **no** | a script in every reader that renders the deck as a document, and in every pipeline that converts one to HTML |
| `\\host\share\payload.exe`, `//host/share` | **no** | an SMB connection made with no prompt. The reader's credentials go to whoever answers: an NTLM relay, and the only hyperlink here that costs a reader something to click |
| `phar://…`, `file://…` | **no** | a PHP stream wrapper and a path on the reader's own disk |

A relative target is carried rather than refused because `![alt](logo.png)` inside a
sentence is a picture this renderer draws as text, not a link: refusing every relative
target would refuse every deck that writes one.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\PresentationRenderer;

try {
    (new PresentationRenderer())->render(
        (new DeckParser())->parse("# One\n\n[Click me](javascript:alert(1))\n"),
        sys_get_temp_dir() . '/deck.pptx',
    );
} catch (Exception $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
The link "javascript:alert(1)" is not a URL a deck may carry. A .pptx hyperlink is followed by the person who opens it: a Windows share is a credential relay, and a javascript: target is a script in any reader that renders the deck. Write it as "https://…", "http://…" or "mailto:…".
```

The missing closing parenthesis is the link pattern's own: `[text](target)` stops at the
first `)`, which is Markdown's rule and not this library's. The message quotes what the
deck's target really was, because that is what an author greps for.

**Breaking**: a deck carrying a link target other than those refused above is one this
render now stops on. Nothing that named a URL is affected.

Emphasis is deliberately not CommonMark's. A delimiter that does not close is left as the
characters it was written as — `2 * 3` and `**unclosed` are drawn as they are, because a deck
that renders is worth more here than one that refuses a paragraph over an apostrophe. An
underscore inside a word is not a delimiter, so `snake_case_name` is a name. The asterisks
and backticks inside a code span are characters, so `` `**not bold**` `` is a code span.

Code blocks are not coloured. Nothing here lexes a language, and the fence's info string
is kept only so a reader unzipping the file can see which blocks named which language.

### When a slide is too full

A block taller than the room left for it is clamped — drawn as small as the room allows —
rather than drawn over whatever is under it. That is the only safe thing to do, but it means
the file says nothing about what was lost, so the renderer says it:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$renderer = new PresentationRenderer();
$renderer->render(
    (new DeckParser())->parse("```\n" . str_repeat("line;\n", 60) . "```\n"),
    sys_get_temp_dir() . '/deck.pptx',
);

foreach ($renderer->overflows() as $overflow) {
    printf(
        "slide %d step %d %s: %s wants %dpx, %dpx left\n",
        $overflow['slide'],
        $overflow['step'],
        // A shape the deck placed itself is in no column, and `null + 1` would say column 1.
        $overflow['column'] === null
            ? sprintf('placed "%s"', $overflow['block'])
            : sprintf('column %d', $overflow['column'] + 1),
        $overflow['block'],
        $overflow['wanted'],
        $overflow['available'],
    );
}
```

```text
slide 1 step 1 column 1: code wants 1150px, 396px left
```

One entry per shape that had to be clamped, so a slide whose contents are taller than its
box reports every shape below the point it ran out. A run of paragraphs and lists is one
shape, so it is one entry where there used to be one per block. A [free shape](#free-shapes)
is in no column at all, so its entry carries `null` rather than a number in front of nothing.
The list is emptied by each `render()`: a layout belongs to one deck, so a caller converting
several in a loop reads the last one's.

A caller that would rather have no file than a file with a slide on it that is missing
something asks for the strict renderer, and gets `ContentOverflow` naming the slide, the
step, the column and the block:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ContentOverflow;
use MarkdownPresentation\Render\PresentationRenderer;

try {
    (new PresentationRenderer(strict: true))->render(
        (new DeckParser())->parse("```\n" . str_repeat("line;\n", 60) . "```\n"),
        sys_get_temp_dir() . '/deck.pptx',
    );
} catch (ContentOverflow $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
Slide 1, step 1, column 1: its code needs 1150px of height and 396px are left. Shorten it, put it on a slide of its own, or render without the strict check to have it clamped.
```

### Layouts

A deck writes `layout: two-column`, never `layout: "Two Content"`. The theme maps the
portable role onto whatever its own template calls that layout, and the renderer builds one
slide layout part per role the deck used, carrying the theme's name for it:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$deck = (new DeckParser())->parse("---\nlayout: two-column\n---\n\n# Both\n\nLeft.\n\n::column\n\n## Right\n\nRight side.\n");
$path = sys_get_temp_dir() . '/deck.pptx';
(new PresentationRenderer())->render($deck, $path);

$zip = new ZipArchive();
$zip->open($path);
preg_match('#<p:cSld name="([^"]*)"#', $zip->getFromName('ppt/slideLayouts/slideLayout1.xml'), $m);
echo $m[1], "\n";

$slide = $zip->getFromName('ppt/slides/slide1.xml');
preg_match_all('#name="([a-z]+)"#', $slide, $names);
echo implode(' ', array_slice($names[1], 1)), "\n";
```

```text
Two Content
text text
```

`::column` on a line of its own moves everything below it into the next column. It is
the only way there is: a `##` is an ordinary heading and stays in whichever column it is
written, so the heading in the example above is drawn in the right one because the marker is
above it and not because of anything about the heading. There is no argument and no way
backwards — the marker means "the next column", which is the one direction a block can move
in. `::column=2` is a failure under `UnknownMarker` naming the marker that would have
worked, rather than a paragraph reading `::column=2` drawn on the slide. A marker written
inside a `:::click` region moves what follows the closing `:::` as well: a column is where a
block is drawn and a region is when it appears, so a region governs what a click reveals and
not where the text sits.

A deck that never writes one fills the first column and leaves the rest empty, which is
honest: it asked for a layout with columns and wrote one of them.

The two `text` shapes are the paragraphs either side of the marker, one shape each — see
[What a slide becomes](#what-a-slide-becomes).

A marker the layout cannot offer — one on a `content` slide, a third on a two-column one, or
either of those at the end of a slide with nothing written after it — is a failure naming the
role and how many columns it has, because the slide is what is missing them rather than the
marker. It is the markers that are counted rather than the blocks they left behind, so what a
deck's last line happens to be does not decide whether it renders:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\PresentationRenderer;

try {
    (new PresentationRenderer())->render(
        (new DeckParser())->parse("# One\n\nLeft.\n\n::column\n\nRight.\n"),
        sys_get_temp_dir() . '/deck.pptx',
    );
} catch (Exception $failure) {
    echo get_class($failure), ": ", $failure->getMessage(), "\n";
}
```

```text
MarkdownPresentation\Exception\UnsupportedColumn: Slide 1 has a "::column", and the "content" layout has 1 column. "::column" moves the blocks after it into the next column, so a slide that uses one needs a layout with more columns than this.
```

The eight roles are `title`, `content`, `two-column`, `section`, `blank`, `image-text`,
`image-title` and `image-slide`. Only the ones a deck uses are built, so a theme that maps two
of them does not fail on the six nobody asked for.

`blank` has no title box — that is what makes it blank — so a slide using it carries its title
as a heading: the one in its body if it wrote one, and the one its frontmatter named if it did
not. `level:` governs whichever of the two it is, at the same depth it would govern a body
heading. The alternative, refusing a `title:` on a blank slide, would refuse a perfectly good
deck over the one thing the library does draw perfectly well there.

The three picture layouts are roles in exactly that sense — a theme maps each to a layout name,
a template can supply the boxes for each, and *Reset* restores them:

| Role | What it is for | Columns | Title box |
| --- | --- | --- | --- |
| `image-text` | A picture beside a block of text | Two, the picture in the wider one | Yes |
| `image-title` | A large picture under a headline | One | Yes |
| `image-slide` | The picture alone, full bleed | One, the whole page | No |

`image-text` divides at a `::column` exactly as `two-column` does, so the picture — everything
above the marker — is the left column. Its two columns are separated by the same gutter a
bullet is from its text, not by a gap of its own, and the text column is wider than a picture's
share of the page would suggest: `ShapeWriter` estimates wrapping from the advance widths of
the face the theme draws the text in, and a column narrow enough to need that estimate to be
exactly right is a column where two paragraphs are drawn on top of one another.

`image-title` gives its headline a band one line tall rather than the two a content slide's
title box is given room for, because every pixel of that band is a pixel of picture.

`image-slide` has no title box and no margin either: its body box is the whole page, so the
picture is fitted to the page rather than to a box inside it. A title named on one is drawn as
a heading in the body, which is `blank`'s answer for the same reason.

```php
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Theme\LayoutRole;

$geometry = new SlideGeometry();
[$picture, $text] = $geometry->body(LayoutRole::ImageText);

printf(
    "%dpx picture, %dpx text, %dpx apart\n",
    $picture->width,
    $text->width,
    $text->x - $picture->x - $picture->width,
);
```

```text
477px picture, 383px text, 20px apart
```

### Placeholders

A layout part is not only a name. Each one carries a placeholder for its title and one for
each of its columns, and the title shape on every slide refers to the layout's:

```xml
<p:sp><p:nvSpPr>
  <p:cNvPr id="2" name="Title Placeholder 2"/>
  <p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>
  <p:nvPr><p:ph type="title"/></p:nvPr>
</p:nvSpPr>…</p:sp>
```

That is what makes *Reset Slide* restore anything, what puts the deck's titles in the outline
view, and what makes a layout other than a blank rectangle in the layout picker. The boxes a
layout's placeholders are written at are the boxes the slides are drawn in, so *Reset*
restores the geometry the slide already had and moves nothing.

`two-column` and `image-text` get two body placeholders, `idx="1"` and `idx="2"`; every other
role gets one. `blank` and `image-slide` get no title placeholder, because neither has a title
box — that is what makes `blank` blank and what makes `image-slide` the picture alone.

A slide's body is a stack of differently shaped blocks — a table, a picture, a code block, and
the frames of text among them — and none of them is a placeholder: promoting one would make
*Reset* move that shape and leave the rest of the column floating. A frame of text is the one
that would be expected to be a placeholder, holding the whole of a slide's body, and it is the
one that must not be: a slide *Reset* tidies is a slide whose body has moved. The outline view
shows a deck's titles and still shows no bullets.

### Templates

A theme may ship a `template.pptx` beside its `layouts.yml`, and a deck written against it is
laid out in *that template's* geometry rather than in this library's. The template is read for
the placeholder boxes its own layouts carry, and both the slides and the layout parts this
renderer writes are placed at them:

```php
use MarkdownPresentation\Render\TemplateGeometry;

// The one theme in this repository that ships a template; see its layouts.yml.
$boxes = (new TemplateGeometry((string) getenv('MARKDOWN_PRESENTATION_ROOT') . '/tests/fixtures/themes/templated/template.pptx'))->boxes();

printf(
    "%s: %d×%d at %d,%d\n",
    'Title and Content',
    $boxes['Title and Content']['title']->width,
    $boxes['Title and Content']['title']->height,
    $boxes['Title and Content']['title']->x,
    $boxes['Title and Content']['title']->y,
);
```

```text
Title and Content: 828×79 at 66,54
```

Those numbers are pixels of the 960 × 540 slide this library writes, and they come from a
template whose own page is 12192000 × 6858000 EMU — a stock 16:9 PowerPoint slide, 1280 × 720
of the pixels a box counts in. Each axis is scaled by the ratio of the template's page to this
one, so a placeholder keeps the position its designer chose rather than being divided by a
constant that only fits this library's own page. A deck on A4 rather than 16:9 works for the
same reason.

Three rules decide what a template is allowed to change:

- **A template fills in the roles it covers and says nothing about the rest.** A layout it
  does not have, and a layout it has with no placeholders in it — Office's own *Blank* — leave
  that role where `SlideGeometry` puts it. This is the same rule `layouts.yml` follows, where a
  theme mapping some of the roles is a theme that works.
- **A template places; it does not typeset.** Sizes and alignments stay with `SlideGeometry`,
  because a template states them in a *master*'s `p:txStyles` — a whole typographic system,
  not a layout — and adopting one would resize the type of every deck that shipped a template.
- **A template supplies geometry, not decoration.** Its master's drawings — a logo, a rule, a
  decorated background — are not copied onto slides, because a deck's page is painted from
  `theme.yml` and the same artwork on two themes that disagree about it is worse than none.

A placeholder a layout states no position for takes its master's, which is what PowerPoint
writes for a layout that does not move the one it inherited. A footer, a date and a slide
number are placeholders too and are left out rather than laid out as body text.

A template that cannot be read is a named failure — `Exception\MalformedTemplate` — rather
than a theme that quietly renders as though it had not said where its geometry comes from. A
part of it that inflates past four megabytes is refused the same way, and the size is what the
part really holds rather than what the archive says it is.

#### Using your own deck as the template

Any deck will do — it does not have to be a `.potx` or come from PowerPoint's gallery. Copy it
into the theme directory as `template.pptx`, next to the `layouts.yml` that names which of its
layouts each role gets. The deck this library was tested against is carried in the repository,
authored and saved by PowerPoint itself, so this is a real file rather than a description of
one:

```php
use MarkdownPresentation\Render\TemplateGeometry;

// A real deck: PowerPoint's own Facet design, with the palette and fonts changed.
$deck = (string) getenv('MARKDOWN_PRESENTATION_ROOT') . '/tests/fixtures/powerpoint/harbour-lights.pptx';
$boxes = (new TemplateGeometry($deck))->boxes();

foreach (['Title Slide', 'Title and Content', 'Two Content', 'Picture with Caption'] as $name) {
    $title = $boxes[$name]['title'];

    printf(
        "%-20s title %3d,%3d %3dx%3d   body %3d,%3d %3dx%3d\n",
        $name,
        $title->x, $title->y, $title->width, $title->height,
        $boxes[$name]['body'][0]->x, $boxes[$name]['body'][0]->y,
        $boxes[$name]['body'][0]->width, $boxes[$name]['body'][0]->height,
    );
}
```

```text
Title Slide          title 119,189 612x130   body 119,319 612x 86
Title and Content    title  53, 48 677x104   body  53,170 677x306
Two Content          title  53, 48 677x104   body  53,170 329x306
Picture with Caption title  53,378 677x 45   body  53, 48 677x303
```

That is the whole mechanism: each layout's placeholders, scaled onto the page this library
writes. *Two Content* has two columns, so a `two-column` slide gets two; *Picture with Caption*
numbers its picture placeholder `idx="1"` and its caption `idx="2"`, so the picture goes in the
first column and the caption under it.

The number of placeholders is also the number of columns a role has, whatever the role is
called: map `content` onto *Two Content* and a `::column` on a content slide splits it, because
that is how many boxes the layout has.

So the theme directory is the template plus the two files that name it:

```
themes/harbour/
├── theme.yml          name: Harbour
├── layouts.yml        which of your deck's layouts each role gets
└── template.pptx      your deck, copied in
```

```yaml
# The layout names are your deck's, verbatim — check them in PowerPoint's slide master view.
# A role left out keeps this library's own geometry rather than failing.
layouts:
  title: Title Slide
  content: Title and Content
  two-column: Two Content
  section: Section Header
  image-title: Picture with Caption
```

Copy those names from the slide master view rather than the ribbon: they are usually the same
and are not guaranteed to be, and a deck saved on a German installation writes
`Titel und Inhalt` for the layout this one calls `Title and Content`.

Two things about reading a template go wrong often enough to be worth saying, and both were hit
while building the fixtures above. **The page is scaled, not divided**, which is why A4 works at
all. And **a placeholder written `<p:ph idx="1"/>` is a body**: PowerPoint leaves the `type` out
where the schema's default applies, and `idx` alone is what identifies it, so a reader that
matches `type` as written inherits nothing from the master and *Title and Content* silently
loses its body box.

### Free shapes

Everything so far is laid out for you. A **free shape** is one you place yourself, in pixels or
in fractions of the slide, and it is written in the body of a slide with the same two-colon
directives the step markers use:

```markdown
---
layout: blank
---

::rectangle{fill=panel layer=back}

::circle{left=8% top=10% width=6% height=6% fill=accent1}

:::content{left=20% top=40% width=60% height=40% fill=none font-size=20}
## Q3 review
- 4,182 signups
:::
```

Twelve names are drawn, and nothing else — a name outside them is a failure that lists all
twelve, so the vocabulary is in the error rather than in a place you have to find:

| Directive | Draws |
| --- | --- |
| `::rectangle` | a rectangle |
| `::roundrect` | a rounded one, with `border-radius` |
| `::ellipse` | an ellipse |
| `::circle` | an ellipse that is as wide as it is tall |
| `::triangle` | an isosceles triangle |
| `::diamond` | a diamond |
| `::chevron` | a chevron |
| `::hexagon` | a hexagon |
| `::arrow` | a right-pointing arrow |
| `::line` | a hairline rule as thick as its `stroke-width` |
| `::image` | a picture |
| `:::content` … `:::` | Markdown, in a box you sized |

`layout: blank` is what most of these want: a slide whose whole content is free shapes has no
title to place them around. They work on any role, and a shape is written over or under the
role's own shapes according to its `layer`.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$deck = (new DeckParser())->parse(
    "---\nlayout: blank\n---\n\n::rectangle{left=0 top=0 width=100% height=100% fill=panel layer=back}\n"
    . "::circle{left=50% top=50% width=10% height=10% fill=accent1 anchor=center}\n"
    . ":::content{left=8% top=40% width=40% height=20% font-size=18}\nWords.\n:::\n",
);
$path = sys_get_temp_dir() . '/deck.pptx';
(new PresentationRenderer())->render($deck, $path);

$zip = new ZipArchive();
$zip->open($path);
$slide = $zip->getFromName('ppt/slides/slide1.xml');
// The shape tree itself is the first name and carries none, so it is left out.
preg_match_all('#<p:cNvPr id="\d+" name="([^"]*)"#', $slide, $names);
echo implode(' ', array_filter($names[1])), "\n";

// 9525 EMU is the pixel PHPPresentation converts at.
preg_match('#name="circle".*?<a:off x="(\d+)" y="(\d+)"#s', $slide, $circle);
printf("%d,%d\n", intdiv($circle[1], 9525), intdiv($circle[2], 9525));
```

```text
rectangle circle content
453,243
```

`rectangle` is written first because it is `layer=back`; `content` and `circle` are written
after the layout's own shapes, so they are drawn over them. A later shape in `p:spTree` is the
one on top, which is the whole of what `layer` means and the reason a panel behind a slide's
text has to be written before the text.

#### Placement

Six attributes, on every name:

| Attribute | Default | |
| --- | --- | --- |
| `left`, `top` | `0` | where the box starts |
| `width`, `height` | `100%` | its size, so a shape with no attributes is full bleed |
| `anchor` | `nw` | which corner `left` and `top` name: `nw` `n` `ne` `e` `se` `s` `sw` `w` `center` |
| `layer` | `front` | `back` writes the shape before the layout's own, `front` after |
| `rotate` | `0` | degrees clockwise, about the middle of the box: `45`, `-30`, `45deg` |

`anchor` is what makes a percentage usable for centring, which is the thing a model reaches for
most often. `left=50% top=50% anchor=center` puts the box's **middle** at the middle of the
slide rather than its corner:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Theme\Theme;

$blocks = (new BlockTranslator())->parse(
    "::rectangle{left=50% top=50% width=20% height=20% anchor=center}\n",
    1,
    '.',
    new PathScope(['.']),
    Theme::defaults(),
);

foreach ($blocks->shapes() as $shape) {
    $box = $shape->placement->box;
    printf("%d,%d %dx%d\n", $box->x, $box->y, $box->width, $box->height);
}
```

```text
384,216 192x108
```

A box lies **within** the slide. One that does not is refused with the numbers rather than
clamped, because a shape quietly drawn somewhere other than where it was asked for is a deck
that renders and shows something the author did not write — and nothing in the file says so.
Full bleed is still reachable, because `left=0 width=100%` is exactly the edge.

That is a claim about the **box**, and `rotate` is what makes it worth saying so: a square
turned forty-five degrees keeps its hundred-pixel box and reaches past the edge of it. What
is checked is what the deck placed; the tilt is the author's.

### Turning a shape

`rotate` turns a shape about the **middle of its own box**. The box does not move and does not
resize, so a rotated shape is still the box you placed — which is also how a label is turned
*with* the arrow it sits on:

```markdown
::arrow{left=5% top=34% width=32% height=15% fill=accent1 rotate=-12}

:::content{left=5% top=38% width=32% height=7% rotate=-12 align=center vertical-align=middle font-size=16}
**Retention** 94%
:::
```

A `:::content` box is a shape, so it carries `rotate` like the other eleven — which is how a
label is turned with the arrow it sits on. The other way to put words on a shape, the shape's
own `label`, turns with it for free; both are under
[Text inside a shape](#text-inside-a-shape).

Three rules, each for a reason:

| | |
| --- | --- |
| **Whole degrees only** | `a:xfrm rot` is in sixtieths of a degree and the setter is an `int`, so `22.5` is a `TypeError` rather than a rounded angle. It is refused with the spelling that works. |
| **Negative and past a turn are fine** | `rotate=-12` is a counter-clockwise tilt and `rotate=400` is a turn and a bit. Both are wrapped to a single turn rather than refused, which is what PowerPoint does when it reads one. |
| **A full turn is no turn** | `rotate=360` and `rotate=0` write no `rot` at all, rather than `rot="0"` or `rot="21600000"`. |

Rotation is invisible on `::circle` and `::ellipse` — an ellipse turned ninety degrees looks
exactly like an ellipse. It shows where a shape has a corner to be different at.

#### Measurements

Two readings of a bare number, and the difference is what each is written in everywhere else
here: **a position or a size on the slide is pixels**, and **a size of type or of line is
points**. Both spellings are accepted anywhere, and a percentage only where there is an axis to
resolve it against.

| Written | Means |
| --- | --- |
| `320`, `320px` | 320 pixels |
| `40%` | 40% of 960 across, or of 540 down |
| `2pt` | two points — 2⅔ pixels |
| `left=320` | 320px, the first reading |
| `font-size=24` | 24pt, the second |

A circle's **two percentages resolve against the height**, which is the one decision here that
is not a rule. `width=10% height=10%` is the square the author wrote, and resolved against their
own axes the two would be 96 by 54 — an ellipse that reads as a mistake rather than as a
circle. One axis makes the spelling mean what it says, and it makes the defaults work: a circle
with no size at all is the biggest one the slide's height allows rather than one 420px too wide.

A circle with one size takes it for both. With two that differ it is refused, because squaring
it silently would draw something other than the box the deck wrote:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\PresentationRenderer;

foreach (["::circle{width=120}\n", "::circle{width=120 height=80}\n"] as $slide) {
    try {
        (new PresentationRenderer())->render((new DeckParser())->parse($slide), sys_get_temp_dir() . '/deck.pptx');
    } catch (Exception $failure) {
        echo $failure->getMessage(), "\n\n";
    }
}
```

```text
Slide 1 gives a "::circle" a width of 120 and a height of 80. A circle is as wide as it is tall; give one size or the other.

```

#### Colour

A colour is `none`, one of the four names a shape *plays*, one of the twelve theme *slots*, or
a hex value:

| Written | Means |
| --- | --- |
| `none` | nothing — written `a:noFill`, not inherited |
| `page` `ink` `subtle` `panel` | what the page behind the slide, the body text, a quote and a code block's panel are each drawn in |
| `accent1`–`accent6`, `dk1` `lt1` `dk2` `lt2`, `hlink` `folHlink` | the theme's own slots |
| `#4C1D95` | that hex, with or without the `#` |

The four role names come first because they are what the rest of the library paints with: a
free shape and a laid-out one are the same colour on the same theme. A deck written in them
survives a theme swap and one written in hex does not.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Theme\ThemeResolver;

$blocks = (new BlockTranslator())->parse(
    "::rectangle{fill=panel stroke=accent1}\n",
    1,
    '.',
    new PathScope(['.']),
    (new ThemeResolver())->resolve('default')->theme(),
);

foreach ($blocks->shapes() as $shape) {
    printf("%s on %s\n", $shape->paint->fill?->getRGB(), $shape->paint->stroke?->getRGB());
}
```

```text
E7E6E6 on 4472C4
```

An unrecognised name is refused, never quietly black.

#### The rest of the attributes

| Attribute | On | |
| --- | --- | --- |
| `fill`, `stroke` | the shapes, `::content` | |
| `alpha` | the shapes, `::content` | 0–100 for the whole shape; one percentage, written on every colour it has |
| `stroke-width` | the shapes, `::content` | in points; `0` is a hairline |
| `border-radius` | `::roundrect` only | in pixels |
| `label` | the ten ink shapes | the shape's own text — see [Text inside a shape](#text-inside-a-shape) |
| `src`, `alt`, `fit` | `::image` | |
| `color`, `font-size`, `font-family`, `align`, `vertical-align`, `padding` | `::content` only | |
| `shadow-color`, `shadow-distance`, `shadow-blur`, `shadow-direction`, `shadow-alpha` | all twelve | see [A shadow](#a-shadow) |

`fit` is `contain` (keep the ratio, fit inside, centred) or `stretch` (take the box exactly).
`fit=cover` is refused by name: it is a crop rather than a fit, PHPPresentation writes a picture
either proportional or stretched, and there is nowhere to put the crop rectangle — so it would
be drawn as `contain` and left to be noticed.

#### A placed text block

`:::content` is Markdown between its fences, drawn as **one** box:

```markdown
:::content{left=8% top=60% width=40% height=30% fill=none color=ink font-size=18 align=left}
## Q3 review
- Shipped the **new** pricing page
- 4,182 signups
:::
```

It holds paragraphs, headings, lists and quotes — the blocks a text frame holds anywhere else.
A table, a code block, a picture and a rule are each a shape of their own, and each is refused
by name rather than drawn, because a shape's id in the file is its position in the slide's
shape collection and the renderer counts them rather than looking them up. A `::click` or a
`::column` inside one is refused too: it is one box on one step.

Text that does not fit is clamped to the box and reported by
`overflows()` like any other block, with no column behind the entry — there is no column, and
putting `0` there would be a number in front of nothing.

**There is no `text` attribute on a shape, and `:::content` is why.** A shape with text in it
is expressible today, and it is the composable version: draw the shape, then put a
`:::content` box over it and give both the same `rotate`. What a `text` attribute would add is
a *second* way to put words on a slide, and it would be the worse one — see
[Text inside a shape](#text-inside-a-shape).

#### Text inside a shape

There are **two** ways, and the choice is one object against styled text.

`label` is the shape's own text, in the shape's own body:

```markdown
::arrow{left=6% top=20% width=34% height=13% fill=accent1 label="Retention 94%" rotate=-6}
```

That is **one shape in the file**, and it is the native thing: double-click the arrow in
PowerPoint and the cursor is in the arrow, and dragging the arrow drags the words with it. It
also turns with the shape without a second `rotate` to keep in step.

What it costs, and it is a real cost:

| | |
| --- | --- |
| **No Markdown** | one plain run, so `**bold**` is six literal asterisks |
| **No type control** | PHPPresentation writes `<a:r><a:t>…</a:t></a:r>` with **no `a:rPr`** — no size, no face, no colour, and no attribute here that reaches one |
| **Centred, both ways** | `algn="ctr"` and `anchor="ctr"` are written into the element rather than read from anything |
| **One ink** | `tx1`, the theme's body text colour. It follows `dark:`, so a label is legible on a filled shape in either theme — what it has no contrast against is the shape's *own fill* where that is close to the page's, and a pale fill on a dark theme is the case that washes out |
| **Centred in the box** | not in the shape's visual mass, so on an asymmetric one — `::arrow` above all — the words read as pushed towards the tail |

`:::content` over the shape is the other way, and it is what the label is for when any of that
matters — Markdown, `**bold**`, a [link](#links), `color`, `font-size`, `font-family`, `align`,
`padding`, and a `rotate` to match:

```markdown
::arrow{left=6% top=20% width=34% height=13% fill=accent1 rotate=-6}

:::content{left=6% top=22% width=34% height=9% rotate=-6 align=center vertical-align=middle font-size=16}
**Retention** 94%
:::
```

**Two shapes in the file, not one.** That is the whole of the trade: `label` is one object you
can double-click and drag, with words you cannot style; `:::content` is words you can style, on
an object that does not move when the shape moves. PowerPoint edits them as two things, because
they are two things.

A `label` is refused on `::image`, which is a `p:pic` with no text body at all, and on
`:::content`, whose body is already its text — putting the same words in the same place twice is
the mistake both refusals are about.

#### A shadow

Five attributes, on all twelve directives:

| Attribute | | |
| --- | --- | --- |
| `shadow-color` | one of the four role names, a theme slot, or hex | `ink` where the deck names none |
| `shadow-distance` | whole pixels, `0` to `1500` | `0` — an offset with no blur is a hard edge |
| `shadow-blur` | whole pixels, `0` to `1500` | `0` |
| `shadow-direction` | whole degrees, clockwise from the right | `90` — straight down |
| `shadow-alpha` | `0`–`100` | `100` |

```markdown
::hexagon{fill=lt2 shadow-distance=12 shadow-blur=6 shadow-direction=45 shadow-alpha=50}
```

**Distance and blur are pixels, not points.** A shadow is a size on the slide and the slide is
measured in pixels, which is the rule the rest of this page states for a position and a size.
PowerPoint's own UI offers shadow size in points, so this is a deliberate departure from it
rather than an oversight.

**Direction is degrees** and is wrapped the way `rotate` is: `shadow-direction=360` and
`shadow-direction=0` are the same, and `-90` is `270`. There is no x/y pair to place a shadow
with, so an angle is the only way to put one off-axis.

**One attribute is enough.** `shadow-blur=4` on its own is a shadow, and a shape that names none
of the five is written with no `<a:effectLst>` at all rather than with one that says the shape
has no shadow.

**`shadow-color=none` and `shadow-color=transparent` are refused**, even though `fill=none` and
`stroke=none` mean *do not draw this* and are honoured. A shadow has nowhere to record a colour
of its own — DrawingML draws an outer shadow as a coloured copy of the shape — so "no colour"
has no representation there, and answering it with a default would draw a fully opaque black
shadow for the word *transparent*. For no shadow at all, leave off every shadow attribute.

**A distance or a blur may reach 1500 pixels** — the slide's width plus its height. Beyond that
the value is refused by name rather than written: PHPPresentation converts pixels to EMU with a
**float** multiply, and PHP prints a large float as `9.525E+14`, which is not a number
`ST_PositiveCoordinate` can hold. PowerPoint reads one; a validator, Keynote or a server-side
renderer need not.

A shadow is on the shape, not on the words inside it: a `label` takes the shadow of the shape it
sits in, and a `:::content` block casts one from its own border. `::image` casts one too — a
`p:pic` carries an effect list of its own.

#### What is refused, and how it reads

An attribute a directive does not read is refused rather than ignored, and that is the whole of
the design: an ignored attribute is the one mistake here that leaves no trace at all.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\PresentationRenderer;

$decks = [
    "::rect{a=1}\n",
    "::rectangle{left=900 width=120}\n",
    "::circle{width=120 height=80}\n",
    "::rectangle{fill=chartreuse}\n",
    "::rectangle{rotate=22.5}\n",
    "::image{src=\"chart.png\" fill=accent1}\n",
];

foreach ($decks as $slide) {
    try {
        (new PresentationRenderer())->render(
            (new DeckParser())->parse($slide),
            sys_get_temp_dir() . '/deck.pptx',
        );
    } catch (Exception $failure) {
        echo $failure->getMessage(), "\n\n";
    }
}
```

```text
Slide 1 opens the directive "::rect{a=1}", which is not something a slide draws. The free shapes are: arrow, chevron, circle, content, diamond, ellipse, hexagon, image, line, rectangle, roundrect, triangle. Two colons mark a step as "::click" or a column as "::column" and three open a step region as ":::click"; a placed text block is ":::content" with its Markdown between the fences.

Slide 1 places "rectangle" at left 900px with a width of 120px, which reaches 1020px. A free shape lies within the 960px wide slide: write left=0 width=100% for one that reaches the edge.

Slide 1 gives a "::circle" a width of 120 and a height of 80. A circle is as wide as it is tall; give one size or the other.

Slide 1 gives "rectangle" a fill of "chartreuse". A colour is none, page, ink, subtle, panel, a theme slot (dk1, lt1, dk2, lt2, accent1, accent2, accent3, accent4, accent5, accent6, hlink, folHlink), or a hex value such as #4C1D95.

Slide 1 gives "rectangle" a rotate of "22.5". A rotation is a whole number of degrees, and turns the shape about the middle of its box: rotate=45, rotate=-30, rotate=45deg.

Slide 1 gives "image" an attribute "fill", which it does not read. The attributes are: alt, anchor, fit, height, layer, left, rotate, shadow-alpha, shadow-blur, shadow-color, shadow-direction, shadow-distance, src, top, width.
```

Two things are **not** offered and are refused rather than approximated: a dashed outline,
because PHPPresentation writes an autoshape's stroke as one colour and one width; and a label
in brackets, because a free shape has nowhere to put one.

### Steps

Two colons for a boundary, three for a region:

| Written | Means |
| --- | --- |
| `::click` | everything after it is the next step |
| `::click=3` | everything after it is step three |
| `:::click` … `:::` | everything between them is one step of its own |
| `::column` | everything after it is in the next column |
| `::note` … `::` | a speaker note, taken off the slide |

`::click` and `::column` share two colons and neither is a prefix of the other, so a step
marker is a step marker and a column marker is a column marker; the suite asks both directions
rather than reasoning about them. `::column` moves no step, which is why a build-up written
across two columns spends the same clicks it would on one column. Every marker in the table is
recognised in either case, so `::Click`, `::Column`, `:::Click` and `::Note` are the markers they
look like: a deck written by a model capitalises a directive as readily as it writes it in lower
case, and a marker drawn on the slide is one its author thought they had read.

A step is a click in a build-up on **one** slide. Every block of the slide is written onto it
at once, and the steps a reader clicks through are written as a PowerPoint animation that
reveals the shapes belonging to each one:

```markdown
Always visible from the first step.

::click

The second thing, on the next click.

::click

The third thing, on the click after that.
```

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$deck = (new DeckParser())->parse(
    "# One\n\nFirst.\n\n::click\n\nSecond.\n\n::click\n\nThird.\n",
);
$path = sys_get_temp_dir() . '/deck.pptx';
(new PresentationRenderer())->render($deck, $path);

$zip = new ZipArchive();
$zip->open($path);

$parts = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $parts[] = $zip->getNameIndex($i);
}

echo count(preg_grep('#^ppt/slides/slide\d+\.xml$#', $parts)), " slides\n";

$slide = $zip->getFromName('ppt/slides/slide1.xml');

preg_match_all('#name="([a-z]+)"#', $slide, $names);
echo 'shapes: ', implode(' ', $names[1]), "\n";

// Every effect written with `nodeType="clickEffect"` opens a click; the `withEffect`s after it
// join that one. Both name the shape they reveal in `<p:spTgt spid="…">`.
preg_match_all(
    '#nodeType="(clickEffect|withEffect)">(?:(?!nodeType=).)*?spid="(\d+)"#s',
    $slide,
    $effects,
    PREG_SET_ORDER,
);

foreach ($effects as $effect) {
    echo $effect[1] === 'clickEffect' ? "click reveals {$effect[2]}\n" : "  and {$effect[2]}\n";
}
```

```text
1 slides
shapes: title text text text
click reveals 4
click reveals 5
```

The first step is not a click: it is on the slide before the reader clicks anything, and so is
the title, which is written once and never changes. A `::click=3` with nothing written on step
two costs no click either, because a click that reveals nothing is a click the reader spends
to see no change.

A step with more than one shape opens one click and joins the rest to it with `withEffect`,
which is the whole of the difference between a step and one click per shape:

`::click=3` names step three outright, and a region opener takes the same argument:

```markdown
::click

The second thing.

::click

:::click

The third thing, written as a region.

:::
```

A region is the boundary and its fences in one: everything between them is read at the step its
opener names, through the same counter a bare marker moves, so a build-up can be written as the
parts it is made of. A `:::` with nothing to close, and a `::note` with nothing to close it, are
failures naming the spelling that would have worked. A region keeps its own steps: the text after
the closing `:::` goes back to the step the region opened at rather than to the last step inside
it. Of the two markers, only `::column` carries past the closer.

A step number that does not go forwards, a `::click=` with nothing after the equals, and a step
that is not a number are all failures, and so is a `::column` carrying an argument: the marker
takes none. So is a marker at the end of a list item: honouring one would mean splitting a
single list block into several, which is a different feature from the one claimed here, so the
refusal names the mistake instead of drawing the marker on the slide.

### How many steps a deck may have

Two ceilings, and they no longer bound one thing between them:

| Ceiling | Value | Bounds |
| --- | --- | --- |
| `Render\CommonMark\BlockTranslator::MAX_STEP` | 500 | the steps one slide may build up in |
| `Render\PresentationRenderer::MAX_SLIDES` | 1000 | the slides one deck may write |

Both are refused by name, with the number that would have worked in the message. `MAX_STEP` is
what a deck is allowed to *say*: a step number is a position in a chain somebody clicks through,
and a position in the billions is a deck written by something that did not mean it — which is
also why a twenty-digit number, which saturates the cast to `PHP_INT_MAX` on the way, is refused
rather than trusted. `MAX_SLIDES` is the deck's own slide count, refused before the first slide
is built.

Neither costs what it used to. A step is no longer a `.pptx` part of its own with its shapes,
its rels and its entry in `[Content_Types].xml`, so a deck of forty slides with twenty steps
each writes forty slides rather than eight hundred, and the 500 steps that one slide may name
cost the renderer nothing at all. The numbers are the same because they were chosen for how long
a build-up a person writes, not for how long one takes — a build-up of 500 clicks is not a slide
anybody gives, and a talk of 1000 slides does not exist.

### Images

A relative image path resolves against the directory the renderer was given, which is the
first constructor argument. A path that is not a file is a failure naming where it looked,
because PowerPoint embeds what is on disk and a missing image is a shape with no picture in
it and no complaint from anywhere.

**A path has to land inside that directory.** The path is resolved with `realpath()` and the
result has to be under a directory the caller allowed, and the renderer's own directory is
the first of them. The other two ways out — `../` and a symlink — are exactly the two a
prefix test on the written path cannot see, which is why it is the resolved path that is
tested:

| Deck writes | Before | After |
| --- | --- | --- |
| `![](/etc/hosts)` | `ppt/media/hosts1` holding the file | a failure naming the file it found and the directories it may read |
| `![](../secrets/creds.svg)` | the same, one directory up | the same failure |
| `![](assets/link.svg)` where `link.svg` is a symlink to `/home/me/.ssh/id_rsa` | the target, verbatim | the same failure |

An `.svg` is the sharp case, because it is a document rather than a picture: reading
somebody's `~/.aws/credentials` is one `![a](…)` away whether what reaches the deck is its
bytes or only what was drawn out of them (see [Image formats](#image-formats)). The deck did
not have to be trying — a deck written by a model, from a file it was given, is enough.

A library of images a deck draws from by name rather than shipping is a real case, so the
roots are a constructor argument. It is the program's decision, made before a deck is read:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

mkdir(__DIR__ . '/brand');
$image = imagecreatetruecolor(40, 20);
imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 30));
imagepng($image, __DIR__ . '/brand/dot.png');

$deck = (new DeckParser())->parse("# One\n\n![a dot](brand/dot.png)\n");

(new PresentationRenderer(__DIR__, imageRoots: [__DIR__ . '/brand']))->render(
    $deck,
    sys_get_temp_dir() . '/deck.pptx',
);

$zip = new ZipArchive();
$zip->open(sys_get_temp_dir() . '/deck.pptx');

echo str_contains(implode(',', array_map(
    static fn (int $i): string => (string) $zip->getNameIndex($i),
    range(0, $zip->numFiles - 1),
)), 'ppt/media/') ? "embedded from the allowed root\n" : "not embedded\n";
```

```text
embedded from the allowed root
```

What a deck writes is never a way to widen that list. A path naming a stream wrapper —
`phar://`, `http://`, `file://` — is refused as well: PHP hands such a name to the wrapper it
says, and a deck has no way to mean one by it.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$image = imagecreatetruecolor(40, 20);
imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 30));
imagepng($image, __DIR__ . '/dot.png');

$deck = (new DeckParser())->parse("![a dot](dot.png)\n");
$path = sys_get_temp_dir() . '/deck.pptx';

(new PresentationRenderer(__DIR__))->render($deck, $path);

$zip = new ZipArchive();
$zip->open($path);

$media = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $media[] = $zip->getNameIndex($i);
}

$embedded = array_values(preg_grep('#^ppt/media/#', $media));

echo count($embedded), " image under ppt/media/\n";
echo str_starts_with($embedded[0], 'ppt/media/') ? "embedded, not linked\n" : "linked\n";
```

```text
1 image under ppt/media/
embedded, not linked
```

The image is embedded in the file rather than linked to it, so a deck that travels keeps
its pictures. The part's name is PHPPresentation's own numbering and is deliberately not
asserted here.

### Image formats

A `.pptx` carries GIF, JPEG, PNG and SVG, and a package may not declare a media type
outside that set. PHPPresentation does not enforce it for a file it is handed a path to: it
writes whatever media type it finds, so a WebP went into the package as `image/webp` and
the deck rendered without complaint and opened with the picture missing. The format is
checked before anything is built instead.

This library carries **GIF, JPEG and PNG** and draws an **SVG** — see
[What an SVG costs you](#what-an-svg-costs-you) for why the fourth one is not carried and
what carrying it would have produced.

A format GD can decode — a WebP or a BMP, the two a deck is most likely to name — is
converted to PNG and the picture is in the deck. The part *is* a PNG, so the conversion is
visible in the size of the file rather than done quietly. The format is read from the
header rather than the extension, so a WebP called `logo.png` is converted rather than
declared as a PNG it is not.

A file that is not an image at all is refused:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\PresentationRenderer;

file_put_contents(__DIR__ . '/diagram.png', 'not an image, whatever it is called');

try {
    (new PresentationRenderer(__DIR__))->render(
        (new DeckParser())->parse("![a diagram](diagram.png)\n"),
        sys_get_temp_dir() . '/deck.pptx',
    );
} catch (Exception $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
Slide 1 shows "diagram.png", which this PHP cannot read as an image at all — a truncated download, or something that is not one. Convert it to GIF, JPEG or PNG first.
```

So are a format nothing here can decode — an ICO, say — and a file whose payload was cut
short, which measures perfectly and is still not an image anybody can draw.

An SVG is taken on its extension and never measured by `getimagesize()`: it cannot read one
that sizes itself by `viewBox`, and before PHP 8.5 it could not read one at all. Every other
carried format is a raster, so it is measured, and a picture that claims more than
**4096 × 4096 pixels** is refused before anything opens it — which is where the ceiling does
reach an SVG too, one rendering later:

```
Slide 1 shows "bomb.png", which measures 50000 x 50000 pixels. Decoding it is about 9.3 GB of memory, and this render opens no picture over 16777216 pixels: scale it down before putting it in a deck.
```

That is a decompression bomb — a hundred bytes on disk, gigabytes of memory the moment it
is decoded — and the size is out of the values `getimagesize()` had already returned rather
than out of a second look at the file.

### What an SVG costs you

**An SVG is drawn, and the drawing is what goes in.** The format does have a part for a
vector and `ContentTypes` declares it, and writing that part is what produced a deck
PowerPoint cannot show. Its own anatomy is a raster in `a:blip` with an `asvg:svgBlip`
extension on the same element pointing at a second relationship; PHPPresentation writes one
relationship and points both the raster and the vector at it. No schema check sees that —
the part and the relationship are well-formed, and only opening the deck shows the gap — so
the file is drawn here instead and the part in the package is a PNG.

That is a loss, and it is the price:

- the deck carries a raster, so a diagram is a picture at the size it was drawn and nothing
  more. Zoom in far enough and it is pixels.
- what the raster shows is what *this machine's* rasteriser shows. `ext-imagick` is the
  rasteriser, and a build of it with no SVG delegate — ImageMagick's own renderer rather
  than librsvg — does not draw `<text>` or `<path>` at all. Measured on this repository's
  own `diagram.svg`: the background, the circle and the panel came through, and the
  chevron and the words were not in the raster.

So the extension is asked whether it can draw a letter rather than whether the `SVG` format
is registered, because a build that answers yes to the second question and no to the first
returns a picture with its words missing and says nothing. A deck naming an SVG on such a
build is refused rather than rendered:

```
Slide 3 shows "diagram.svg", which is SVG. A .pptx holds a vector only beside a raster of it, and this PHP cannot draw one: ext-imagick is not loaded, or the SVG delegate it was built with will not draw a letter. Install ext-imagick, or put a PNG of the drawing in the deck.
```

A file this build's delegate will not read at all is refused the same way, with a message
that says the file rather than the extension — an SVG that is not one, or markup the
delegate cannot draw.

**`ext-imagick` is optional**, and the `suggest` in `composer.json` says what it buys. GD
has no way to read a vector in any PHP version, so there is nothing else to fall back to and
nothing to fall back *to* silently.

The `MAX_PIXELS` bound above reaches an SVG one rendering behind every other format. A file
that sizes itself by `viewBox` says how big its coordinate system is, not how big a picture
it is, so the ceiling is measured on the raster and the number in the failure is the
raster's.

The containment rule further up still applies: an SVG is read from a directory the caller
allowed, like any other picture, and what is inside it is parsed by a rasteriser rather than
by PowerPoint.

### What the theme reaches

The twelve colours of `theme.yml` are written into the presentation's colour scheme, which
is what PowerPoint's colour picker reads, so a theme edited in the application offers the
theme's own swatches. The page behind every slide, the type on it and the panels it fills
follow `dark:` — see [Dark themes](#dark-themes). The `heading`, `body` and `mono` fonts are
applied to the runs that use them, because a `.pptx`'s font scheme is not reachable from
here.

The faces a theme names are also the faces its text is measured with, and there is a table
of advance widths for every face the shipped themes name: Calibri, Cambria, Arial, Arial
Narrow, Georgia, Inter and Roboto. The monospaced faces have their advance rather than a
table, because every character in one advances by the same amount: Consolas is measured at
550 units to the em, Menlo at 602 and JetBrains Mono at 600. A theme naming a face with no
table of its own is measured with the reference face's widths — the ones every face was
measured with before there were tables of their own — and the face is named by
`PresentationRenderer::unknownFonts()`, one entry for every slot it was named in. A caller
that would rather have no file than a file whose text was measured with another face's
widths asks for the strict renderer, and gets `UnknownFont` naming the faces there are
tables for.

Two limits worth knowing. All twelve slots are written whether a shape uses them or not:
DrawingML's `CT_ColorScheme` is twelve required elements, so a scheme missing one is an
invalid theme part, and `accent2`–`accent6` and `folHlink` are written because the format
leaves no way to leave them out. And a colour reaching a *shape* is written as `a:srgbClr`,
not as an `a:schemeClr` naming the slot — PHPPresentation's writer emits `a:srgbClr` for
every `Color` it is handed, `SchemeColor` included. So re-theming in PowerPoint changes the
swatches the picker offers; it does not restyle the bullets, rules and text already on the
slides. The colour map the master writes says which slot each of `bg1`, `tx1`, `bg2` and
`tx2` is, and it is written from the same four the shapes are painted with rather than
independently of them — but it is the theme that decides, not the map: a shape's colour is
resolved here and written out as hex, and the map is never consulted for it again.

`styles.yml` is **not** read. Its slots are the Word half's vocabulary and the shipped themes
write `.docx` style ids, which a `.pptx` has no use for; mapping them onto shapes would be
inventing a vocabulary nobody wrote down. A slot that does not exist still fails when the
theme is loaded, so a typo in a `styles.yml` is never rendered as silence.

### Frontmatter

The set of directives is closed, and anything outside it is a failure that names the set:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\PresentationRenderer;

try {
    (new PresentationRenderer())->render(
        (new DeckParser())->parse("---\ntransition: fade\n---\n\n# One\n"),
        sys_get_temp_dir() . '/deck.pptx',
    );
} catch (Exception $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
The frontmatter of slide 1 names "transition", which is not a directive. The directives are: title, name, level, layout, theme, author, description.
```

Slidev accepts dozens of directives this library does not read, so a deck written for it will
often not render. That is the trade, and the message is what makes it workable: it names
every directive that does something, so the fix is to edit the front matter rather than to go
looking for what is missing.

`theme`, `author` and `description` describe the deck rather than a slide, so they belong in
slide 1's front matter. On any other slide they are a failure that says so, because two
slides that both name a theme is a deck that renders one of them and says nothing about the
other.

Every slide is checked before anything is built, so a deck whose fourth slide names a
directive nobody reads fails before it has written a byte rather than leaving a half-finished
file where a whole one was expected.

### In the body of a slide

This format is Markdown and pairs of colons. Slidev nests content in a step comment, a
`v-*` element and a `:::` container fence, and neither of the other two lands where a person
would read prose — so both are failures naming the spelling that would have worked, rather
than shapes carrying the directive's own text. A leaf directive under a name this library
does not draw is a third, and refused the same way: `::foo`, `::foo[label]{attrs}` painted
onto the slide would reach it as prose reading `::foo`, which is a deck that renders while
showing the mistake. The two names a leaf may carry are `click` and `column`, and `note` is
not one of them because a note is `::note`, its text, and a closing `::`. An HTML comment is
refused too, because a step is `::click` here and a comment is a deck written for a tool
whose step marker this one does not read. A plain HTML tag is none of those and is still
text: `<b>` is not a directive, and this library has no opinion about what a deck says about
HTML.

### What Slidev writes and this does not read

Slidev's in-slide spelling for two of those is different, and a deck carrying it is refused
rather than drawn. Each refusal names what would have worked:

| Slidev writes | Here |
| --- | --- |
| `<!-- v-click -->` | `::click` on a line of its own |
| `<!-- v-click="3" -->` | `::click=3` on a line of its own |
| `::left::` and `::right::` | `::column` once per column |

The first two arrive as an HTML comment and are refused as one, quoting the comment; the
last arrives as a leaf directive under a name this library does not draw, and is refused as
one. Nothing is inferred from either: a step marker is `::click` here, and a column is a
marker rather than a named half.

Slidev also puts two columns in the frontmatter, under `layout: two-cols`. The role here is
`two-column`, and `two-cols` is refused as a name this library does not have. A `::column`
needs a layout with more than one column to move into — under `layout: content` it is
refused as a column that layout has no room for, which is the same refusal in the other
direction.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\PresentationRenderer;

foreach ([":::note\nHello.\n:::\n", "<v-click>Hello.</v-click>\n"] as $slide) {
    try {
        (new PresentationRenderer())->render(
            (new DeckParser())->parse($slide),
            sys_get_temp_dir() . '/deck.pptx',
        );
    } catch (Exception $failure) {
        echo $failure->getMessage(), "\n";
    }
}
```

```text
Slide 1 opens the container ":::note", which is not something a slide draws. Three colons open a step region as ":::click" and close it as ":::"; a placed text block is ":::content" with its Markdown between the fences; divide a deck with a "---" line, write a section as a heading, and write a note as a quote: "> ".
Slide 1 has the element "<v-click>", which is not something a slide draws. A step is "::click" on a line of its own, and there is no other element.
```

## Reading a deck back

The other direction: a `.pptx` becomes the Markdown this library writes decks in, so a deck
somebody else made can be taken apart, edited as text, and put back together in another
design.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\PowerPointToMarkdown;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Reverse\Options;

$path = sys_get_temp_dir() . '/deck.pptx';

(new PresentationRenderer())->render(
    (new DeckParser())->parse("# One\n\nText.\n"),
    $path,
);

echo (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->convert();
```

```text
---
title: "Untitled Presentation"
author: "markdown-office"
---

# One

Text.
```

The frontmatter carries what the deck said about itself — its title, its author and its
description — because a deck's own properties are the only thing about it that is not on a
slide.

What comes back is the text and the structure. What does not is the design: the type sizes,
the colours and the geometry live in the theme and the master, and neither travels to a new
deck. A `.pptx` records what is on a slide, not how it was drawn.

| In the deck | In the Markdown |
| --- | --- |
| the title placeholder | the heading of the slide |
| the subtitle placeholder | a paragraph under it |
| a body placeholder | a list |
| a text box | a paragraph |
| a picture | an image, written out beside the deck |
| a table | a Markdown table |
| the speaker's note | a `::note` block |
| the date, the footer and the slide number | nothing |

The order is the order the slide lists its shapes in, which is the order they were put on
it. A slide is a set of boxes rather than a flow, and the order the file records is the one
claim about the reading order that the file itself makes.

### What the round trip does not keep

These are the distinctions a `.pptx` does not record, and what comes back in their place:

- **the design.** A type size, a colour and a position are in the theme and the master, and
  the new deck draws its own.
- **the layout.** A slide with two columns becomes a slide with two lists. The text of each
  column keeps its order; the columns do not.
- **the underline.** PowerPoint has it and Markdown has no way to say it, so a deck whose
  emphasis is underlined comes back with the emphasis gone. Bold, italic and strike-through
  come back as themselves.
- **a link inside a run.** A shape whose text is a link comes back as a link; a word inside
  a paragraph that is a link does not.
- **a chart.** A chart is a drawing, not prose. It is left out rather than half-read.

## Themes

A theme is a **directory**, and only `theme.yml` is required — a path may name that file
instead of the directory holding it. Nothing is compiled and nothing is a PHP class:

```
themes/corporate-dark/
├── theme.yml        required   colours, fonts, type scale, metadata
├── styles.yml       optional   Word style-slot overrides
├── layouts.yml      optional   semantic role → template layout name
├── template.pptx    optional   PowerPoint template: where the layouts put their boxes
├── README.md        optional   attribution
└── LICENSE          optional
```

The tools that come with the library are in `tools/`:

| Tool | What it is |
| --- | --- |
| `tools/phpstan.sh` | Fetches the analyser by checksum and runs it at the level `phpstan.neon.dist` configures |
| `tools/render-with-powerpoint.applescript` | Opens each built deck in Microsoft PowerPoint and exports a PDF. A developer tool: nothing in the test suite needs it, because CI has no PowerPoint |
| `tools/build-powerpoint-fixtures.sh` | Rebuilds the two decks in `tests/fixtures/powerpoint/`, which were authored in PowerPoint rather than written by hand |

Five themes ship with the library, and they are five different decks rather than one deck in
five swatches — see [Type scale and rhythm](#type-scale-and-rhythm). Here is one of them,
which is the file on disk:

```yaml path=themes/corporate-dark/theme.yml
# A dark briefing deck read on a projector in a room with the lights down. Everything about it
# is bigger than the default and nothing about it is lighter: wide margins, a heavy rule, the
# largest body type of the three, and a heading in the accent rather than in the ink the body
# text is — which is the one treatment that reads at the back of a room.
name: Corporate Dark
description: Dark corporate deck for technical briefings.
author: markdown-office
dark: true
colors:
  dk1: "#1E1E2E"
  lt1: "#CDD6F4"
  dk2: "#181825"
  lt2: "#BAC2DE"
  accent1: "#89B4FA"
  accent2: "#A6E3A1"
  accent3: "#F9E2AF"
  accent4: "#F38BA8"
  accent5: "#CBA6F7"
  accent6: "#94E2D5"
  hlink: "#89B4FA"
  folHlink: "#CBA6F7"
fonts:
  heading: Inter
  body: Roboto
  mono: JetBrains Mono
style:
  margin: 48
  gutter: 24
  block: 16
  rule: 4
  body: 18
  title: 32
  cover: 46
  section: 50
  headings: [26, 23, 21, 19, 17]
  leading: 1.32
  heading-weight: bold
  heading-ink: accent
```

The colours are DrawingML's twelve. The longer spellings an earlier draft of the
concept used — `dark1`, `light1`, `dark2`, `light2`, `hyperlink`,
`followedHyperlink` — are accepted on input and normalised to DrawingML's own names,
because a theme written with them is otherwise a colour slot PowerPoint does not
have. A theme that names one accent and says nothing about the rest keeps Office's
for the others, so the colour scheme it produces is always complete:

```php
use Symfony\Component\Yaml\Yaml;
use MarkdownPresentation\Theme\Theme;

$theme = Theme::fromArray(Yaml::parse("name: Sparse\ncolors:\n  dark1: \"#101010\"\n"));

echo $theme->colour('dark1'), ' ', $theme->colour('dk1'), "\n";
echo $theme->colour('accent1'), "\n";
```

```text
#101010 #101010
#4472C4
```

### Dark themes

`dark: true` says the page is dark. It renames no colour — `dk1` is still the dark
one and `lt1` the light one — it says which of the two plays which part, which is
what PowerPoint's own dark themes do with the colour map rather than with the
palette:

```php
use Symfony\Component\Yaml\Yaml;
use MarkdownPresentation\Theme\Theme;

$dark = Theme::fromArray(Yaml::parse("name: Night\ndark: true\n"));

echo $dark->isDark() ? 'dark' : 'light', "\n";
echo $dark->page(), " paints the page, ", $dark->ink(), " paints the type\n";
echo $dark->panel(), " fills a panel, ", $dark->subtleInk(), " paints the quiet type\n";
```

```text
dark
dk1 paints the page, lt1 paints the type
dk2 fills a panel, lt2 paints the quiet type
```

Those four are the master's colour map — `bg1`, `tx1`, `bg2`, `tx2` — asked of the
theme rather than read off the master, so a shape and the master that paints it
cannot disagree about which slot is which. Most of what reaches a shape reaches it
through them: body text, titles and table cells in `tx1`; quotes and a code block's
own text in `tx2`; a code block and a table's header row filled with `bg2`; the page
behind every slide in `bg1`.

Two things do not, and a theme sees both. A heading is painted `accent1` rather than
`tx1` where `style:` sets `heading-ink: accent`, and a bullet and a thematic break's
bar are painted `accent1` outright. That is what the colour scheme is for: PHPPresentation
writes every colour as a literal `a:srgbClr`, so re-theming the deck in PowerPoint
restyles the picker's swatches and not the shapes already on the slides.

A flag that is not a boolean is a failure, because a theme written `dark: yes` would
otherwise render light with nothing saying so:

```php
use Symfony\Component\Yaml\Yaml;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Theme\Theme;

try {
    Theme::fromArray(Yaml::parse("name: Night\ndark: yes\n"));
} catch (Exception $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
In "theme.yml": "dark" is not true or false.
```

### Resolution order

A deck names a theme, and the name is looked for in this order:

1. an explicit path — anything with a `/` in it, written from the deck's own directory —
   or the working directory, where the deck has none — unless it names an absolute one;
2. `./themes/<name>` beside the deck;
3. `./themes/<name>` beside the working directory;
4. a theme in a root the calling program allowed;
5. a theme that ships with the library;
6. nothing, which is a failure listing every name that *did* resolve.

The deck's own directory comes before the working directory's because a deck and its
theme are one thing: the theme sits in `themes/` under the directory the deck is in, and
the program rendering the deck knows where that is. A deck rendered by its path finds its
theme whichever directory the render runs in.

```php
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Theme\ThemeResolver;

$root = (string) getenv('MARKDOWN_PRESENTATION_ROOT');
$resolver = new ThemeResolver($root);

echo implode(', ', $resolver->available()), "\n";

try {
    $resolver->resolve('corprate-dark');
} catch (Exception $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
corporate-dark, default, dense, minimal, pitch
There is no theme named "corprate-dark". Available: corporate-dark, default, dense, minimal, pitch.
```

Falling back to the default theme instead would produce a deck in the wrong colours
with nothing in the output to say so, which is the failure mode this whole library is
built to avoid.

A theme named by a path — anything with a `/` in it — is loaded from wherever it is. The
path is written from the deck's own directory, the way a relative image path is, and
`beside()` is how the resolver is told where the deck is: the renderer hands it its own
directory, which is the directory a relative image path in the deck resolves against.

```php
use MarkdownPresentation\Theme\ThemeResolver;

$root = (string) getenv('MARKDOWN_PRESENTATION_ROOT');
$theme = (new ThemeResolver())->beside($root)->resolve('./themes/minimal');

echo $theme->theme()->name(), "\n";
echo $theme->directory(), "\n";
```

```text
Minimal
<root>/themes/minimal
```

A path that names an absolute one is read as it stands, and a path may name the manifest
itself rather than the directory holding it. The theme is then that file, and the layouts,
the styles and the template beside it are the theme's own:

```php
use MarkdownPresentation\Theme\ThemeResolver;

$root = (string) getenv('MARKDOWN_PRESENTATION_ROOT');
$theme = (new ThemeResolver())->beside($root)->resolve('./themes/minimal/theme.yml');

echo $theme->theme()->name(), "\n";
echo $theme->directory(), "\n";
```

```text
Minimal
<root>/themes/minimal
```

Only a `theme.yml` is a manifest, so a `styles.yml` named as the theme is one of a theme's
own files rather than the theme itself. The name the deck wrote is what decides that, not
the name the file carries once it has been resolved — so a `theme.yml` that is a link to a
file of another name is still the manifest, and the roots below still hold the file it
reaches.

**A theme path has to land inside the deck's own directory, the working directory, the
built-in themes, or another root the resolver was given.** A `theme:` is a path a deck
writes, so the same rule as an image's applies, and the reason is more than tidiness: a
deck naming no `author` of its own takes `dc:creator` from the theme it was rendered in,
so `theme: ../../secrets/theme.yml` was a way to ask for an arbitrary file's `author:`
line and be handed it in `docProps/core.xml` of the download. `../`, an absolute path
elsewhere and a symlink out of the tree are all refused, and the failure names the path it
found and the roots it may read:

```
The theme "../secrets" is at "/srv/decks/../secrets" rather than one this render may read (/srv/decks). Copy it under one of those directories, or give the ThemeResolver that directory as one of its additional roots.
```

**The files inside a theme are held to the same roots.** A directory that is inside them
may hold a `theme.yml` that is a link out of them, and the file the link reaches is read
as readily as one beside it — the `theme.yml`, the `styles.yml`, the `layouts.yml` and the
`template.pptx` are each resolved and held before they are used, and the refusal names the
file it found and the roots it may read. A link that resolves *inside* the roots is a file
like any other, so a theme laid out with links still reads.

A shared library of themes beside the project is a real case and is reachable, as a
constructor argument — `new ThemeResolver($root, roots: ['/srv/themes'])` — because that is
the program's decision and never the deck's. A root is a directory of themes, the way the
themes that ship with the library are one: a name is looked for in it directly, and the
themes in it are named in the list of the themes that resolve.

### Layout roles

A deck writes `layout: two-column`, not `layout: "Two Content"`. The theme maps the
portable role onto whatever its own template calls that layout, so the same deck
works against any theme that maps the role:

```php
use MarkdownPresentation\Theme\ThemeResolver;

$theme = (new ThemeResolver((string) getenv('MARKDOWN_PRESENTATION_ROOT')))->resolve('corporate-dark');

echo $theme->layout('two-column'), "\n";
echo implode(', ', $theme->roles()), "\n";
```

```text
Two Content
title, content, two-column, section, blank, image-text, image-title, image-slide
```

A name that is not one of them, and a role the theme does not map, are both failures
that name the alternatives — because the author of the deck can act on that and nobody
else can. Here a theme whose template maps only two roles:

```php
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Theme\ThemeLoader;

$theme = (new ThemeLoader())->load((string) getenv('MARKDOWN_PRESENTATION_ROOT') . '/tests/fixtures/themes/sparse');

foreach (['three-column', 'section'] as $role) {
    try {
        $theme->layout($role);
    } catch (UnknownLayoutRole $failure) {
        echo $failure->getMessage(), "\n";
    }
}
```

```text
"three-column" is not a layout role. The roles are: title, content, two-column, section, blank, image-text, image-title, image-slide.
The theme "Sparse" maps no "section" layout. It maps: title, content.
```

### Type scale and rhythm

`style:` is the part of a theme that decides what a slide looks like rather than what colour
it is. Without it three themes are the same deck in three swatches: the same type at the same
sizes, the same margins, the same gaps, and a reader who saw all three would remember the
palette and nothing else.

| Key | What it decides | Unit |
| --- | --- | --- |
| `margin` | The space left all the way round the page. Every band on the slide is a multiple of it, so widening the margin moves the title, the body and the gap between them together | px |
| `gutter` | The gap that separates rather than encloses: two columns, a bullet from its text, one level of a list from the one above | px |
| `block` | The gap after a paragraph — in a frame and between two shapes. Every other gap is a fraction of this, or a function of a heading's own size | px |
| `rule` | How thick a thematic break's bar is drawn. The room it occupies is the layout's, not the theme's | px |
| `body` | The size body text and list items are drawn at | pt |
| `title` | The size a content slide's title is drawn at | pt |
| `cover` | The size the title layout's title is drawn at | pt |
| `section` | The size a section layout's title is drawn at | pt |
| `headings` | The size a heading in a body is drawn at, by depth, shallowest first. A scale that runs out keeps its last size rather than failing | pt, a list |
| `leading` | The multiple of a line's own height one line is drawn at | a number |
| `heading-weight` | `bold`, or `regular` for the heading face's own reading weight | a word |
| `heading-ink` | `ink` for the colour the body text is in, or `accent` for the theme's accent | a word |

Every key is optional and takes the library's own value where a file does not mention it, so
a theme that names one thing — a wider margin, a heavier rule — and leaves the rest alone is a
theme that works:

```php
use Symfony\Component\Yaml\Yaml;
use MarkdownPresentation\Theme\Theme;

$theme = Theme::fromArray(Yaml::parse("name: Wide\nstyle:\n  margin: 64\n  heading-ink: accent\n"));
$style = $theme->style();

printf("%dpx margin, %dpt body, %dpx rule\n", $style->margin(), $style->body(), $style->ruleWeight());
printf("body: %dpt  title: %dpt  headings: %s\n", $style->body(), $style->title(), implode('/', $style->headings()));
```

```text
64px margin, 16pt body, 2px rule
body: 16pt  title: 28pt  headings: 22/20/18/16/14
```

Two things are deliberately *not* in it. The code and table sizes stay with the library, because
a table is legible at twelve points whatever the body text is set at and tying the two together
would make a deck with a large body unreadable in its own tables. And a **title's** ink is the
body ink whatever `heading-ink` says, because `heading-ink` is about a heading *in a body*; a
slide whose title were a different colour from its own text would read as two decks.

The four shipped themes are deliberately far apart on every one of these axes, which is what
makes the files in `examples/out/03-themes-*.pptx` and `examples/out/11-type-scale-*.pptx`
comparable: `default` is Office's palette at the library's own scale, `corporate-dark` is
larger and heavier throughout with its headings in the accent, `minimal` is a serif deck with
the widest margins, a hairline rule and headings in their own face's reading weight rather
than a synthesised bold, and `dense` is the one where the whole scale moves down at once — a
13pt body on a 28px margin, for a deck that has to hold a page rather than make a point.

Note that the names are about different axes: `minimal` is *dense in content*, with the
widest margins and the largest light-theme body, while `dense` is *dense in type*. A deck
that wants a great deal of text on a slide wants `minimal`; a deck that wants the type itself
smaller wants `dense`.

Because the air between blocks is a fraction of `block:` or a function of a heading's own
size, it follows a theme's type scale rather than standing still while the type moves: at
`dense`'s 13pt body a paragraph gap is 8px and at `corporate-dark`'s 18pt body it is 16px,
and a heading's air above it follows whatever that theme's `headings` scale says. The two
`11-type-scale` decks exist to be looked at side by side for exactly that reason.

### Style slots

`styles.yml` overrides Word style slots. A slot that does not exist is a typo, and a
typo here would otherwise be ignored at render time and produce a document with none
of the styling the theme asked for:

```php
use MarkdownPresentation\Exception\UnknownStyleSlot;
use MarkdownPresentation\Theme\ThemeLoader;

$root = (string) getenv('MARKDOWN_PRESENTATION_ROOT');

try {
    (new ThemeLoader())->load($root . '/tests/fixtures/themes/broken');
} catch (UnknownStyleSlot $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
In "<root>/tests/fixtures/themes/broken/styles.yml": "blockquote" is not a style slot. The slots are: heading.1, heading.2, heading.3, heading.4, heading.5, heading.6, paragraph, blockQuote, codeBlock, thematicBreak, htmlFallback, listParagraph, codeFont, linkFont, bulletList, orderedList, table, tableHeaderRow, tableCell.
```

A value is either a styleId from a `.docx` template — `Heading1`, not `Heading 1` —
or an inline style array:

```yaml path=themes/corporate-dark/styles.yml
# Word style slots, by the names in a .docx template's styles. A value is either a
# styleId — "Heading1", not "Heading 1" — or an inline style array.
heading.1: Heading1
heading.2: Heading2
paragraph: BodyText
blockQuote: PullQuote
codeFont:
  name: JetBrains Mono
  size: 9
linkFont:
  color: 89B4FA
  underline: single
```

References to theme tokens — `{accent1}`, `{fonts.mono}` — are **not** resolved yet,
so a `styles.yml` written with them carries the braces through untouched.

## Loud failures

Every mistake above is an exception naming what would have worked instead, because
the deck being written is as likely to be written by a model as by a person, and a
model can act on a message and cannot act on a deck that quietly came out wrong.

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\FencedFrontmatter;

try {
    (new DeckParser())->parse("```yaml\ntitle: Deck\n---\n\n# One\n");
} catch (FencedFrontmatter $failure) {
    echo $failure->getMessage(), "\n";
}
```

```text
Slide 1 opens with a fenced yaml block. Slidev reads that as frontmatter; this library does not, so it is refused rather than read as a code block. Delimit the frontmatter with "---" instead.
```

Everything this library reports extends `MarkdownPresentation\Exception\Exception`, so one
`catch` covers it without also catching the failures of the libraries underneath.
The failures a deck author can fix by naming something else extend
`MarkdownPresentation\Exception\InvalidInput` below it.

### What a failure repeats

A failure repeats the path it looked at, so a message reads
`Slide 1 shows the image "../logo.png", which is a file at "/srv/brand/logo.png" rather
than one this render may read`, and a message that names a `theme.yml` names the full path
to it.

**That repeats absolute paths of the machine that rendered the deck**, and that is a stated
choice rather than an oversight. For the person running this from a terminal it is the
useful half of the message: a deck written on one machine and converted on another resolves
its paths against the wrong directory, and the difference between `logo.png` and
`/Users/someone/deck/logo.png` is the whole of what is wrong. A failure that refused to say
where it looked would send the author to `find`, or — in a container — to nowhere at all.

The cost is real and it is worth stating rather than defending: a deck whose failure ends up
in a log, an issue tracker or a CI transcript carries the layout of the machine that
produced it. So:

- a deck this library renders is not untrusted input, because nothing untrusted gets to
  choose the paths in the message — see [Images](#images) and the theme resolution order
  for what a deck may point at;
- if your failures leave your machine, catch `MarkdownPresentation\Exception\Exception`,
  log `getMessage()` where your users can see it, and consider rendering with the paths you
  would not publish. There is no switch that turns the paths off, because a switch is
  something somebody would eventually get wrong.

Several sit beside those two rather than under `InvalidInput`:
`MarkdownPresentation\Exception\UnreadablePackage`, for a `.pptx` that cannot be opened and
a part of one that cannot be decompressed; `MarkdownPresentation\Exception\UnreadableDeck`,
for a deck this library cannot read; `MarkdownPresentation\Exception\UnpatchedDependency`,
when the installed PHPPresentation lacks what the library needs of it;
`MarkdownPresentation\Exception\NothingToConvert`, when there is nothing to convert; and
`MarkdownPresentation\Exception\FileNotWritable`, when the result cannot be written. Nothing
a deck names fixes any of them — it is the disk, the download that stopped, the file that
was not a deck, the installer — and a deck author told to go and rename something would go
and rename something. They are all still under the base class, so the same one `catch`
reaches them.

```php
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\NotesColourMapPass;

try {
    NotesColourMapPass::applyTo(sys_get_temp_dir() . '/deck.pptx');
} catch (Exception $failure) {
    echo get_class($failure), ": ", $failure->getMessage(), "\n";
}
```

## Conformance

Splitting is checked against the real Slidev parser rather than a reading of it: every deck
in `tests/fixtures/slidev/expected.json` was run through `parseSync` at a pinned commit and
recorded, and each of the divergences this library takes deliberately is written down with
its reason. Rendering has no such oracle, so every claim it makes is a test asserting on a
part of the file it wrote.

[CONTRIBUTING.md](./CONTRIBUTING.md) says what the check is, which strictnesses were taken
and why, and what a change has to pass.

## How this page is checked

Every example on this page is a test. A fenced block followed by a fenced block of
expected output is a claim about what the first converts to, and the suite runs it
and compares the two exactly — a change to either side of the page fails the build.
That is why the `<ol class="deck">` above is HTML at all: the library renders none,
so `tests/Support/DeckHtml.php` projects a split deck onto one for the page to show,
and every value in it is read straight off the deck.

```php
echo strtoupper('executed, not trusted');
```

```text
EXECUTED, NOT TRUSTED
```

The PHP examples run out of process, with `MARKDOWN_PRESENTATION_ROOT` pointing at the
project root. The YAML blocks carrying a `path=` are compared byte for byte with the
file they name. `tests/Readme/examples.php` is the mechanism.

## Licence

MIT. See [LICENSE](./LICENSE).

[command-line]: https://markdown-office.github.io/command-line/basic/
[composer-patches]: https://github.com/cweagans/composer-patches
[mdoffice]: https://github.com/markdown-office/mdoffice
