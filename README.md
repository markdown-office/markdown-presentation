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
composer require markdown-presentation/markdown-presentation
```

PHP 8.2 or newer, `symfony/yaml` and `phpoffice/phppresentation` as hard dependencies: the
frontmatter of a deck and every file of a theme is YAML, and a partial parser is worse for
the machines that write them than for the people that do. The renderer is pinned to a
commit of the PHPPresentation `master` branch rather than to a tag — see
[Conformance](#conformance).

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

$deck = (new DeckParser())->parse("---\ntitle: Deck\n---\n\n# One\n\n---\nlayout: two-column\n---\n\n## Left\n");

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

A speaker note is an HTML comment that runs to the end of a slide.

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

An HTML comment that runs to the end of a slide is its speaker note, and comes off
the content:

```php
use MarkdownPresentation\Document\DeckParser;

$deck = (new DeckParser())->parse("# One\n\n<!--\nRead this out loud.\n-->\n");

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
| `# Heading` | `title`, then `heading` | The first heading becomes the title and is not drawn twice, whatever markup it carries |
| A paragraph | `text` | `**bold**`, `*italic*`, `***both***`, `__bold__`, `_italic_`, `~~struck~~`, `` `code` `` and `[text](url)` are runs, not characters. A link target is checked — see [Links](#links) |
| `- a` / `1. a` | `text` | Ordered lists are numbered, not bulleted, from the number the deck writes; nesting is the indent |
| ```` ```lang ```` | `code` | Mono font, filled with `bg2`, the language in the shape's description. No colouring |
| `\| a \| b \|` under an alignment row | `table` | Alignment comes from the row; a ragged row keeps the cells it has; a cell carries the same inline markup a paragraph does. The header row is bold, filled with `bg2`, and the columns are as wide as what they hold |
| `> a` | `quote` | Italic, in `tx2`, indented. Written over several lines it is one shape |
| `***` | `rule` | A bar in `accent1`. `---` is a slide delimiter and never reaches here |
| A line that is only `![alt](path)` | `image` | Fitted to its box, aspect kept, centred. The alt text is the shape's description, and the written path stands in where the deck named none. A path that is not a file is a failure |

A title is text like any other: a frontmatter `title: Ops **runbook**` is drawn with the
word in bold rather than as asterisks, and the deck's own `dc:title` carries the same title as
plain text, because nothing on the other end of a metadata field reads a pair of asterisks.

### One box for a run of text

A run of paragraphs and lists is drawn as **one** shape holding one paragraph each — the way a
person builds a slide, by clicking into a placeholder and typing. Three paragraphs with a
list between them are one box, so clicking any of them selects all of them, moving one moves
the lot, and PowerPoint lays them out inside the one box they were measured for rather than
six boxes that might not agree about where they are.

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

A heading, a quote, a rule, a table, a picture and a code block are shapes of their own, and
any of them ends the run: a heading is a different size and colour, and the others are not
text. A quote ends it because it is drawn a gutter in from the margin at another size in
another colour, which one box's paragraphs cannot each be.

The gap between two paragraphs is the theme's own `block:` spacing, written into the shape as
`a:spcAft` — the same number the layout spends between two shapes of a column, spent in the
other of its two places, and counted once rather than twice.

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

Shapes stack down the body box, each one starting where the one above it ended with a gap
between them.

Every block is measured before it is drawn: `Render\TextMetric` counts the lines it will
wrap to, from an advance-width table bundled in the package. It is an estimate and says so
— the widths are Helvetica's, kerning is not modelled, and a theme naming a face of a
different width is not detected — which is why every text shape is written `a:normAutofit`.
The box keeps the height the layout gave it and the reader scales the type down to fit, so
an estimate that came out a line short costs a little whitespace instead of drawing one
block over the next.

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
The link "javascript:alert(1" is not a URL a deck may carry. A .pptx hyperlink is followed by the person who opens it: a Windows share is a credential relay, and a javascript: target is a script in any reader that renders the deck. Write it as "https://…", "http://…" or "mailto:…".
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
        "slide %d step %d column %d: %s wants %dpx, %dpx left\n",
        $overflow['slide'],
        $overflow['step'],
        $overflow['column'] + 1,
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
shape, so it is one entry where there used to be one per block. The list is emptied by each
`render()`: a layout belongs to one deck, so a caller converting several in a loop reads the
last one's.

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

$deck = (new DeckParser())->parse("---\nlayout: two-column\n---\n\n# Both\n\nLeft.\n\n## Right\n\nRight side.\n");
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
text heading text
```

Two columns divide at the first `##` in the body, because that is the heading a writer puts
on a column of a slide and there is nothing else to go on. A deck that never writes one fills
the left column and leaves the right empty, which is honest: it asked for two columns and
wrote one.

The two `text` shapes are the paragraphs either side of the `##`, one shape each — see
[What a slide becomes](#what-a-slide-becomes).

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

`image-text` divides at the first `##` exactly as `two-column` does, so the picture — everything
above the heading — is the left column. Its two columns are separated by the same gutter a
bullet is from its text, not by a gap of its own, and the text column is wider than a picture's
share of the page would suggest: `ShapeWriter` estimates wrapping from a table of Helvetica's
widths and a theme's own face is wider than that, so a column narrow enough to need the
estimate to be exactly right is a column where two paragraphs are drawn on top of one another.

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
than a theme that quietly renders as though it had not said where its geometry comes from.

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

### Steps

`<!-- v-click -->` on a line of its own opens a step. PowerPoint has no "press a key to
reveal this", so the build-up becomes the slides a presenter would click through, each
revealing everything the one before it had:

```php
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

$deck = (new DeckParser())->parse("# One\n\nFirst.\n\n<!-- v-click -->\n\nSecond.\n");
$path = sys_get_temp_dir() . '/deck.pptx';
(new PresentationRenderer())->render($deck, $path);

$zip = new ZipArchive();
$zip->open($path);

$parts = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $parts[] = $zip->getNameIndex($i);
}

echo count(preg_grep('#^ppt/slides/slide\d+\.xml$#', $parts)), " slides\n";
echo str_contains($zip->getFromName('ppt/slides/slide1.xml'), 'Second.') ? 'too soon' : 'not yet', "\n";
echo str_contains($zip->getFromName('ppt/slides/slide2.xml'), 'Second.') ? 'then' : 'never', "\n";
```

```text
2 slides
not yet
then
```

`<!-- v-click=3 -->` names step three outright. A step number that does not go forwards, a
`v-click=` with nothing after the equals, and a step that is not a number are all failures.
A marker at the end of a list item is refused rather than ignored: honouring one would mean
splitting a single list block into several, which is a different feature from the one claimed
here, so the refusal names the mistake instead of drawing the comment on the slide.

### How many steps a deck may have

Two ceilings, because they bound two different things and a deck gets past the first with
the second:

| Ceiling | Value | Bounds |
| --- | --- | --- |
| `Render\BlockParser::MAX_STEP` | 500 | the steps one slide may name |
| `Render\PresentationRenderer::MAX_SLIDES` | 1000 | the slides one deck may write |

Both exist because a step *is* a slide: every step of a build-up is written as a `.pptx`
part with its shapes, its rels and its entry in `[Content_Types].xml`, and the Markdown
asking for them is kilobytes. `<!-- v-click=2147483647 -->` is 27 bytes and asks for two
billion of them, and a twenty-digit number saturates the cast to `PHP_INT_MAX` — which is why
the cast is refused rather than trusted. Both are refused by name, with the number that would
have worked in the message.

The second is not the first restated. A deck of forty slides with twenty steps each is
inside every per-slide check and still asks for 800 written slides, so the total is counted
from the parsed blocks before the first slide is built rather than while they are being.

The numbers are generous on purpose: a build-up of 500 clicks is not a slide anyone gives,
and a talk of 1000 slides does not exist. A deck that reaches either was not written to.

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

An `.svg` is the sharp case, because SVG is text and is embedded byte for byte (see
[Image formats](#image-formats)): reading somebody's `~/.aws/credentials` into a deck is one
`![a](…)` away. The deck did not have to be trying — a deck written by a model, from a file
it was given, is enough.

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
Slide 1 shows "diagram.png", which this PHP cannot read as an image at all — a truncated download, or something that is not one. Convert it to GIF, JPEG, PNG or SVG first.
```

So are a format nothing here can decode — an ICO, say — and a file whose payload was cut
short, which measures perfectly and is still not an image anybody can draw.

An SVG is taken on its extension and never measured: `getimagesize()` cannot read one that
sizes itself by `viewBox`, and before PHP 8.5 it could not read one at all. Every other
carried format is a raster, so it is measured, and a picture that claims more than
**4096 × 4096 pixels** is refused before anything opens it:

```
Slide 1 shows "bomb.png", which measures 50000 x 50000 pixels. Decoding it is about 9.3 GB of memory, and this render opens no picture over 16777216 pixels: scale it down before putting it in a deck.
```

That is a decompression bomb — a hundred bytes on disk, gigabytes of memory the moment it
is decoded — and the size is out of the values `getimagesize()` had already returned rather
than out of a second look at the file.

### What an SVG costs you

**An SVG is written into the package byte for byte, unexamined.** Nothing here parses one,
filters one, or measures one, and that is not an oversight that could be fixed here: an SVG
is arbitrary XML with scripts, external references and embedded HTML in it, and a correct
sanitiser is a parser and a policy about what to drop. This library has no claim to either,
so it does not pretend to.

What that means in practice:

- the file is in the deck whatever it contains — `<script>`, `<foreignObject>`, or
  `<image href="https://tracker.example/pixel">`;
- what any reader does with that is the reader's business. PowerPoint does not run a script
  out of an SVG; a browser, a preview service and a converter to HTML or PDF all might;
- the `MAX_PIXELS` bound above does not apply to an SVG, because nothing here opens one.

So an SVG is the one image this library will not tell you anything about. If a deck can come
from somebody you would not hand a file to, treat the output as untrusted: render it, then
open it somewhere isolated, and prefer a raster. The containment rule above still applies —
an SVG is read from a directory the caller allowed, like any other picture — but what is
inside it is nobody's decision but yours.

### What the theme reaches

The twelve colours of `theme.yml` are written into the presentation's colour scheme, which
is what PowerPoint's colour picker reads, so a theme edited in the application offers the
theme's own swatches. The page behind every slide, the type on it and the panels it fills
follow `dark:` — see [Dark themes](#dark-themes). The `heading`, `body` and `mono` fonts are
applied to the runs that use them, because a `.pptx`'s font scheme is not reachable from
here.

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

Slidev's in-slide vocabulary is closed here too. A step comment, a `v-*` element and a `:::`
container fence are the three shapes Slidev nests content in, and none of the other two lands
where a person would read prose — so both are failures naming the spelling that would have
worked, rather than shapes carrying the directive's own text. A plain HTML tag is none of
those and is still text: `<b>` is not a directive, and this library has no opinion about what
a deck says about HTML.

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
Slide 1 opens the container ":::note", which is not something a slide draws. Divide a deck with a "---" line, write a section as a heading, and write a note as a quote: "> ".
Slide 1 has the element "<v-click>", which is not something a slide draws. A step is the comment "<!-- v-click -->" on a line of its own, and there is no other element.
```

## Themes

A theme is a **directory**, and only `theme.yml` is required. Nothing is compiled
and nothing is a PHP class:

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

Three themes ship with the library, and they are three different decks rather than one deck in
three swatches — see [Type scale and rhythm](#type-scale-and-rhythm). Here is one of them,
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

1. an explicit path — anything with a `/` in it;
2. `./themes/<name>` beside the deck;
3. a theme that ships with the library;
4. nothing, which is a failure listing every name that *did* resolve.

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
corporate-dark, default, minimal
There is no theme named "corprate-dark". Available: corporate-dark, default, minimal.
```

Falling back to the default theme instead would produce a deck in the wrong colours
with nothing in the output to say so, which is the failure mode this whole library is
built to avoid.

A theme named by path is loaded from wherever it is — under the directory the resolver was
given:

```php
use MarkdownPresentation\Theme\ThemeResolver;

$root = (string) getenv('MARKDOWN_PRESENTATION_ROOT');
$theme = (new ThemeResolver($root))->resolve($root . '/themes/minimal');

echo $theme->theme()->name(), "\n";
echo $theme->directory(), "\n";
```

```text
Minimal
<root>/themes/minimal
```

**A theme path has to land inside the working directory, the built-in themes, or another
root the resolver was given.** A `theme:` is a path a deck writes, so the same rule as an
image's applies, and the reason is more than tidiness: a deck naming no `author` of its own
takes `dc:creator` from the theme it was rendered in, so `theme: ../../secrets/theme.yml`
was a way to ask for an arbitrary file's `author:` line and be handed it in `docProps/core.xml`
of the download. `../`, an absolute path elsewhere and a symlink out of the tree are all
refused, and the failure names the directory it found and the roots it may read:

```
The theme "../secrets" is a directory at "/srv/decks/../secrets" rather than one this render may read (/srv/decks). Copy it under the working directory, or give the ThemeResolver that directory as one of its additional roots.
```

A shared library of themes beside the project is a real case and is reachable, as a
constructor argument — `new ThemeResolver($root, roots: ['/srv/themes'])` — because that is
the program's decision and never the deck's.

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
| `block` | The gap left between two blocks in a column | px |
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

The three shipped themes are deliberately far apart on every one of these axes, which is what
makes the three files in `examples/out/03-themes-*.pptx` comparable: `default` is Office's
palette at the library's own scale, `corporate-dark` is larger and heavier throughout with its
headings in the accent, and `minimal` is a serif deck with the widest margins, a hairline rule
and headings in their own face's reading weight rather than a synthesised bold.

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

One sits beside those two rather than under `InvalidInput`:
`MarkdownPresentation\Exception\UnreadablePackage`, for a `.pptx` that cannot be opened and
a part of one that cannot be decompressed. Nothing a deck names fixes either — it is the
disk, the download that stopped, the file that was not a deck — and a deck author told to go
and rename something would go and rename something. It is still under the base class, so the
same one `catch` reaches it.

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
