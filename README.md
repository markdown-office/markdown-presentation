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

Every block of a slide becomes a shape, named after the kind of block it came from, so the
mapping can be read off `ppt/slides/slideN.xml` without the library:

| Markdown | Shape | Notes |
| --- | --- | --- |
| `# Heading` | `title`, then `heading` | The first heading becomes the title and is not drawn twice, whatever markup it carries |
| A paragraph | `paragraph` | `**bold**`, `*italic*`, `***both***`, `__bold__`, `_italic_`, `~~struck~~`, `` `code` `` and `[text](url)` are runs, not characters |
| `- a` / `1. a` | `list` | Ordered lists are numbered, not bulleted, from the number the deck writes; nesting is the indent |
| ```` ```lang ```` | `code` | Mono font, filled with `lt2`, the language in the shape's description. No colouring |
| `\| a \| b \|` under an alignment row | `table` | Alignment comes from the row; a ragged row keeps the cells it has; a cell carries the same inline markup a paragraph does. The header row is bold, filled with `lt2`, and the columns are as wide as what they hold |
| `> a` | `quote` | Italic, in `dk2`, indented |
| `***` | `rule` | A bar in `accent1`. `---` is a slide delimiter and never reaches here |
| A line that is only `![alt](path)` | `image` | Fitted to its box, aspect kept, centred. A path that is not a file is a failure |

A title is text like any other: a frontmatter `title: Ops **runbook**` is drawn with the
word in bold rather than as asterisks, and the deck's own `dc:title` carries the same title as
plain text, because nothing on the other end of a metadata field reads a pair of asterisks.

Blocks stack down the body box, each one starting where the one above it ended with a gap
between them.

Every block is measured before it is drawn: `Render\TextMetric` counts the lines it will
wrap to, from an advance-width table bundled in the package. It is an estimate and says so
— the widths are Helvetica's, kerning is not modelled, and a theme naming a face of a
different width is not detected — which is why every text shape is written `a:normAutofit`.
The box keeps the height the layout gave it and the reader scales the type down to fit, so
an estimate that came out a line short costs a little whitespace instead of drawing one
block over the next.

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

One entry per block that had to be clamped, so a slide whose contents are taller than its
box reports every block below the point it ran out. The list is emptied by each
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
paragraph heading paragraph
```

Two columns divide at the first `##` in the body, because that is the heading a writer puts
on a column of a slide and there is nothing else to go on. A deck that never writes one fills
the left column and leaves the right empty, which is honest: it asked for two columns and
wrote one.

The five roles are `title`, `content`, `two-column`, `section` and `blank`. Only the ones a
deck uses are built, so a theme that maps two of the five does not fail on the three nobody
asked for.

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
A marker closing a list item is not honoured: that would mean splitting one list into several,
which is a different feature from the one claimed here.

### Images

A relative image path resolves against the directory the renderer was given, which is the
first constructor argument. A path that is not a file is a failure naming where it looked,
because PowerPoint embeds what is on disk and a missing image is a shape with no picture in
it and no complaint from anywhere.

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
sizes itself by `viewBox`, and before PHP 8.5 it could not read one at all.

### What the theme reaches

The twelve colours of `theme.yml` are written into the presentation's colour scheme, which
is what PowerPoint's colour picker reads, so a theme edited in the application offers the
theme's own swatches. `lt1` becomes the page behind every slide. The `heading`, `body` and
`mono` fonts are applied to the runs that use them, because a `.pptx`'s font scheme is not
reachable from here.

Two limits worth knowing. All twelve slots are written whether a shape uses them or not:
DrawingML's `CT_ColorScheme` is twelve required elements, so a scheme missing one is an
invalid theme part, and `accent2`–`accent6` and `folHlink` are written because the format
leaves no way to leave them out. And a colour reaching a *shape* is written as `a:srgbClr`,
not as an `a:schemeClr` naming the slot — PHPPresentation's writer emits `a:srgbClr` for
every `Color` it is handed, `SchemeColor` included. So re-theming in PowerPoint changes the
swatches the picker offers; it does not restyle the bullets, rules and text already on the
slides.

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

## Themes

A theme is a **directory**, and only `theme.yml` is required. Nothing is compiled
and nothing is a PHP class:

```
themes/corporate-dark/
├── theme.yml        required   colours, fonts, metadata
├── styles.yml       optional   Word style-slot overrides
├── layouts.yml      optional   semantic role → template layout name
├── template.pptx    optional   PowerPoint master and layouts
├── README.md        optional   attribution
└── LICENSE          optional
```

Three themes ship with the library — `default`, `corporate-dark` and `minimal`. Here
is one of them, which is the file on disk:

```yaml path=themes/corporate-dark/theme.yml
name: Corporate Dark
description: Dark corporate deck for technical briefings.
author: markdown-office
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

A theme named by path is loaded from wherever it is:

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
title, content, two-column, section, blank
```

The roles are `title`, `content`, `two-column`, `section` and `blank`. A name that is
not one of them, and a role the theme does not map, are both failures that name the
alternatives — because the author of the deck can act on that and nobody else can.
Here a theme whose template has only two of the five:

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
"three-column" is not a layout role. The roles are: title, content, two-column, section, blank.
The theme "Sparse" maps no "section" layout. It maps: title, content.
```

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

## Conformance

Splitting is derived from `parseSync` in `slidev/packages/parser/src/core.ts`, pinned
at commit `11fe3f79`. Every deck in `tests/fixtures/slidev/expected.json` was run
through that real parser and recorded, so parity is checked against the thing rather
than against a reading of it. `tools/slidev-parity/regenerate.sh` re-records it.

```
94 decks · 77 split identically · 17 declared divergences
```

The 17 are in `tests/fixtures/slidev/divergences.json`, each with the reason and the
output this library produces instead. The test fails if a declared divergence stops
being one, so a strictness cannot quietly change.

What is kept, because it is the grammar worth having:

| Behaviour | Kept |
| --- | --- |
| `---` closes a slide | yes |
| `***` works as a thematic break inside a slide | yes |
| Deck frontmatter only at the top; later blocks are per-slide | yes |
| Speaker notes as trailing HTML comments | yes |

What is not, because Slidev gets it wrong rather than choosing differently:

| Behaviour | Slidev | Here |
| --- | --- | --- |
| `~~~` as a fence | not a fence | a fence |
| Closing fence indented less than the opener | does not close | closes |
| Closing fence carrying anything after it | closes the block | does not |
| A fence that is never closed | leaves the block; a `---` inside splits the deck | runs to the end of the deck |

The last one is the one that matters. A missing backtick is a typo, and under
Slidev's rule it silently splits the deck and leaves the next slide opening with a
literal `---`. These four are CommonMark implemented incorrectly, not a dialect worth
preserving, so taking them would have cost a correctness bug for the sake of a parity
number.

The remaining divergences are all strictness, all one-way: malformed YAML, a
frontmatter block that is not a mapping, a `title` or `level` of the wrong type, and
a slide opening with a fenced ```` ```yaml ```` block. Those refuse rather than guess.

### The rendering slice

Splitting has an oracle. Rendering has none — there is no other implementation to be
parity-checked against — so every claim it makes is checked against a part of the file it
wrote, and each row below is a test in `tests/Unit/rendering.php` or
`tests/Unit/layouts-and-steps.php`.

Nothing compares two `.pptx`. A zip stores two-second timestamps in every entry and
`docProps/core.xml` carries `dcterms:created` and `dcterms:modified`, so two correct
renders of one deck differ; the parts are what a test reads.

| Claim | Read off |
| --- | --- |
| One slide part per slide of the deck, and every one declared | `[Content_Types].xml`, `ppt/slides/` |
| One slide per step, each revealing the steps before it | `ppt/slides/slideN.xml` |
| A `layout:` role becomes a slide layout part named by the theme | `ppt/slideLayouts/`, `ppt/slides/_rels/` |
| One shape per block, named after the kind of block it was | `<p:cNvPr name="…">` |
| The theme's twelve colours become the colour scheme | `ppt/theme/theme1.xml` |
| The theme's `heading`, `body` and `mono` reach the runs that use them | `<a:latin typeface="…">` |
| `styles.yml` reaches no shape, because a `.pptx` has no use for a `.docx` style id | — |

The last row is the only one asserted by what is *absent*. `styles.yml` is not read, and
a slot that does not exist still fails when the theme is loaded, so a typo in one is never
rendered as silence — which is a test, but a test of the theme loader rather than of a
rendered file.

What the renderer refuses is refused loudly, and each refusal names what would have
worked instead: a directive nobody reads, a theme that does not resolve, a layout role
that is not one, a `v-click` step that does not go forwards, an image that is not a file,
and an image no `.pptx` can carry. Those messages are listed one by one in
`tests/Unit/exceptions.php`, which finds the exception classes by reading the directory
rather than by a list that would quietly stop being true.

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