<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\Slide as SourceSlide;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\ContentOverflow;
use MarkdownPresentation\Exception\InvalidInput;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Render\Block\Block;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\LoadedTheme;
use MarkdownPresentation\Theme\Theme;
use MarkdownPresentation\Theme\ThemeResolver;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Slide as WrittenSlide;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;
use PhpOffice\PhpPresentation\Slide\Note;
use PhpOffice\PhpPresentation\Slide\SlideLayout;
use PhpOffice\PhpPresentation\Slide\SlideMaster;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\SchemeColor;

/**
 * A deck and the theme it names, written out as a `.pptx`.
 *
 * The order of the work is the order of what can go wrong: every slide's frontmatter is read
 * and checked, the theme is resolved, and only then is anything built. A deck whose fourth
 * slide names a directive nobody reads therefore fails before it has written a byte rather
 * than leaving a half-finished file where a whole one was expected.
 *
 * Two things this renderer deliberately does not do, both because doing them quietly would
 * be the failure this library exists to avoid:
 *
 * - A deck that names no theme gets the built-in `default`. That is the absence of a
 *   directive, not a fallback from a directive that failed — a named theme that does not
 *   resolve is still {@see ThemeNotFound}.
 * - `styles.yml` is not read. {@see \MarkdownPresentation\Theme\StyleSlots} is the Word
 *   half's vocabulary and the shipped themes write `.docx` style ids, which a `.pptx` has
 *   no use for; mapping them onto shapes would be inventing a vocabulary nobody wrote down.
 *   A slot that does not exist still fails at {@see \MarkdownPresentation\Theme\ThemeLoader}
 *   time, so a typo in a `styles.yml` is never rendered as silence.
 *
 * And one it does: a directive in the body of a slide it cannot draw — a `v-*` element, a
 * `:::` fence, a comment that is neither a step nor the note after the last line — is a
 * failure from {@see \MarkdownPresentation\Render\BlockParser} rather than a shape carrying
 * the directive's own text. That last one is the case this library exists for: a deck
 * written by a model, a directive it does not know, and a slide with the directive printed
 * on it reads as a rendered deck.
*/
final class PresentationRenderer
{
    /**
     * The frontmatter keys this library acts on, in the order a failure offers them.
     *
     * Closed on purpose. Slidev accepts dozens more, so refusing one is a real cost, and
     * the whole of what makes it affordable is that {@see UnknownDirective} names this list
     * in the failure itself.
    */
    public const DIRECTIVES = ['title', 'name', 'level', 'layout', 'theme', 'author', 'description'];

    /** The keys that describe the deck rather than a slide, and so belong to slide 1's block. */
    public const DECK_DIRECTIVES = ['theme', 'author', 'description'];

    /** The theme a deck that names none gets. */
    public const DEFAULT_THEME = 'default';

    /** The step a slide with no `v-click` in it is drawn at. */
    private const FIRST_STEP = 1;

    /**
     * Every block that did not fit its slide during the last render, in the order they were
     * laid out.
     *
     * @var list<array{slide: int, step: int, column: int, block: string, wanted: int, available: int}>
     */
    private array $overflows = [];

    /**
     * @param string $directory The directory a relative image path in the deck resolves
     *                          against, which is the directory the deck itself is in.
     * @param bool   $strict    Whether a block that does not fit is {@see ContentOverflow}
     *                          rather than clamped and recorded. Strict is for a caller that
     *                          would rather have no file than a file with a slide on it that
     *                          is missing something.
     */
    public function __construct(
        private readonly string $directory = '.',
        private readonly ThemeResolver $themes = new ThemeResolver(),
        private readonly BlockParser $blocks = new BlockParser(),
        private readonly SlideGeometry $geometry = new SlideGeometry(),
        private readonly bool $strict = false,
    ) {
    }

    /**
     * Every block the last render had to make smaller than it asked to be.
     *
     * A block that does not fit is clamped to the room left rather than drawn over whatever
     * comes after it, which means the file itself says nothing about what was lost. This is
     * where it is said: one entry per clamped block, naming the slide, the step and the
     * column, how much height the block wanted and how much there was. A slide whose
     * contents are taller than its box yields an entry for every block below the point
     * where it ran out, which is the truth about a column that is simply full.
     *
     * Emptied by {@see self::render()} rather than accumulated, because a layout belongs to
     * one deck: a caller converting several in a loop wants the last one's overflows, not
     * all of them.
     *
     * @return list<array{slide: int, step: int, column: int, block: string, wanted: int, available: int}>
     */
    public function overflows(): array
    {
        return $this->overflows;
    }

    /**
     * @throws InvalidInput     When a slide names something this library cannot render.
     * @throws ThemeNotFound    When the deck names a theme that does not resolve.
     * @throws ContentOverflow  When a block does not fit and the renderer is strict.
     * @throws Exception         When the file cannot be written.
     *
     * @return string The path written.
     */
    public function render(Deck $deck, string $path): string
    {
        $this->overflows = [];

        foreach ($deck->slides() as $slide) {
            $this->check($slide);
        }

        $theme = $this->theme($deck);
        $roles = $this->roles($deck, $theme);

        $presentation = new PhpPresentation();
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);

        $layouts = $this->layouts($this->slideMaster($presentation), $theme, $this->usedRoles($roles));
        $this->properties($presentation, $deck, $theme);

        $writer = new ShapeWriter($theme->theme(), $this->geometry);

        foreach ($deck->slides() as $slide) {
            $this->write($presentation, $slide, $roles[$slide->index()], $layouts, $writer);
        }

        // PHPPresentation's constructor leaves an empty slide behind so that a fresh object is
        // usable. A deck that had slides does not want it, and a deck that had none still
        // does: PowerPoint will not open a presentation with no slides at all, so the empty
        // one is what a deck with no slides is.
        if ($deck->count() > 0) {
            $presentation->removeSlideByIndex(0);
        }

        return $this->save($presentation, $path);
    }

    /**
     * Every frontmatter key of every slide, checked before anything is built.
     *
     * @throws UnknownDirective
    */
    private function check(SourceSlide $slide): void
    {
        foreach (array_keys($slide->frontmatter()) as $key) {
            $key = (string) $key;

            if (!in_array($key, self::DIRECTIVES, true)) {
                throw UnknownDirective::named($slide->index() + 1, $key, self::DIRECTIVES);
            }

            if ($slide->index() !== 0 && in_array($key, self::DECK_DIRECTIVES, true)) {
                throw UnknownDirective::misplaced($slide->index() + 1, $key);
            }
        }
    }

    /**
     * @throws ThemeNotFound
     * @throws MalformedFrontmatter
    */
    private function theme(Deck $deck): LoadedTheme
    {
        $name = $deck->frontmatterValue('theme', self::DEFAULT_THEME);

        if (!is_string($name) || trim($name) === '') {
            throw MalformedFrontmatter::wrongType(1, 'theme', 'a theme name');
        }

        return $this->themes->resolve(trim($name));
    }

    /**
     * The role of each of the deck's slides, by index.
     *
     * Resolved before anything is built so that a role the theme does not map fails while
     * there is still no file on disk to clean up.
     *
     * @return array<int, LayoutRole>
     *
     * @throws MalformedFrontmatter
     * @throws UnknownLayoutRole
    */
    private function roles(Deck $deck, LoadedTheme $theme): array
    {
        $roles = [];

        foreach ($deck->slides() as $slide) {
            $named = $slide->frontmatterValue('layout', LayoutRole::Content->value);

            if (!is_string($named) || trim($named) === '') {
                throw MalformedFrontmatter::wrongType(
                    $slide->index() + 1,
                    'layout',
                    sprintf('a layout role — one of: %s', implode(', ', LayoutRole::names())),
                );
            }

            $roles[$slide->index()] = LayoutRole::from($this->checkRole(trim($named), $theme, $slide->index() + 1));
        }

        return $roles;
    }

    /**
     * @throws UnknownLayoutRole
    */
    private function checkRole(string $role, LoadedTheme $theme, int $slide): string
    {
        if (LayoutRole::tryFrom($role) === null) {
            throw UnknownLayoutRole::notARoleOn($slide, $role, LayoutRole::names());
        }

        // Asked of the theme rather than checked here, because a role the theme does not map
        // is the theme's to fix and its message already says so.
        $theme->layout($role);

        return $role;
    }

    /**
     * The roles the deck's slides use, in first-seen order and each of them once.
     *
     * Only the roles that are used are built. A theme that maps two of the five would
     * otherwise fail on the three its deck never asked for, which is not the same failure as
     * a deck that asks for one it did not map.
     *
     * Compared on the role's value rather than with `array_unique`, which casts each element
     * to a string and an enum cannot be cast to one.
     *
     * @param array<int, LayoutRole> $roles
     *
     * @return list<LayoutRole>
    */
    private function usedRoles(array $roles): array
    {
        $used = [];

        foreach ($roles as $role) {
            if (!in_array($role->value, array_column($used, 'value'), true)) {
                $used[] = $role;
            }
        }

        return $used;
    }

    /**
     * The master the slides hang off.
     *
     * PHPPresentation's constructor always makes one; the `??` is what happens if it ever
     * stops, and making a master is cheaper than refusing to draw a deck over it.
    */
    private function slideMaster(PhpPresentation $presentation): SlideMaster
    {
        return $presentation->getAllMasterSlides()[0] ?? $presentation->createMasterSlide();
    }

    /**
     * The master: its colour scheme, and the page behind every slide.
     *
     * All twelve slots go in whatever the theme named, and the ones no shape uses go in
     * anyway: DrawingML's `CT_ColorScheme` is a sequence of exactly twelve required
     * elements, so a scheme with five of them is not a scheme with five colours, it is an
     * invalid theme part. `accent2`–`accent6` and `folHlink` are written because the format
     * has no way to leave them out, and because they are what PowerPoint's own colour picker
     * offers for a theme someone edits in the application.
     *
     * What that does *not* do is make a shape refer to a slot by name.
     * {@see \PhpOffice\PhpPresentation\Writer\PowerPoint2007\AbstractDecoratorWriter::writeColor()}
     * writes `a:srgbClr` for every `Color` it is given, `SchemeColor` included, so the accent
     * a bullet or a rule is painted with reaches the file as a literal value. Re-theming the
     * deck in PowerPoint restyles the picker's swatches; it does not restyle the shapes
     * already on the slides.
     */
    private function master(SlideMaster $master, Theme $theme): void
    {
        foreach (Theme::COLOURS as $slot) {
            $colour = new SchemeColor();
            $colour->setValue($slot);
            $colour->setRGB(ltrim($theme->colour($slot) ?? '#000000', '#'));
            $master->addSchemeColor($colour);
        }

        $background = new BackgroundColor();
        $background->setColor(new Color(ltrim($theme->colour('lt1') ?? '#FFFFFF', '#')));
        $master->setBackground($background);
    }

    /**
     * One slide layout per role the deck used, each named the way the theme names it.
     *
     * This is where a {@see LayoutRole} becomes a layout in the file. A deck that writes
     * `layout: two-column` against a theme that maps it to `Two Content` gets slides whose
     * layout part is called `Two Content`, and the deck's portable vocabulary and the
     * template's private one stay apart — which is the whole point of the role.
     *
     * A theme's `template.pptx` is not opened: taking a role's placeholders from one is the
     * same job `layouts.yml` already does by naming them, and no shipped theme ships one to
     * take them from.
     *
     * @param list<LayoutRole> $roles
     *
     * @return array<string, SlideLayout>
    */
    private function layouts(SlideMaster $master, LoadedTheme $theme, array $roles): array
    {
        $this->master($master, $theme->theme());

        $layouts = [];
        $existing = $master->getAllSlideLayouts();

        foreach ($roles as $position => $role) {
            // PHPPresentation's constructor leaves the master holding one empty layout.
            // Reusing it for the first role is what stops a deck with a single role from
            // carrying a second, nameless layout part in its file.
            $layout = $position === 0 && isset($existing[0]) ? $existing[0] : $master->createSlideLayout();
            $layout->setLayoutName($theme->layout($role->value));
            $layouts[$role->value] = $layout;
        }

        return $layouts;
    }

    /**
     * The deck's own metadata, on the properties part every `.docx` and `.pptx` has.
     *
     * @throws MalformedFrontmatter
    */
    private function properties(PhpPresentation $presentation, Deck $deck, LoadedTheme $theme): void
    {
        $properties = $presentation->getDocumentProperties();

        // The deck's author where it names one, the theme's otherwise. The deck is the more
        // specific of the two — it is the document, and the theme is the template it was
        // written in. A theme that names no author leaves the library's own name there, which
        // is a statement about the file rather than about who made it.
        $author = $deck->frontmatterValue('author');

        $properties->setCreator($author === null
            ? $theme->theme()->author() ?? 'markdown-presentation'
            : self::text($author, 'author'));

        $title = $deck->frontmatterValue('title');

        if ($title !== null) {
            // Stripped, because this part is a string in a metadata field: there is nothing
            // on the other end to interpret `**`, and a title of `Ops **runbook**` is a
            // title of "Ops runbook" to every reader of the file.
            $properties->setTitle($this->blocks->inline->plain(self::text($title, 'title')));
        }

        $description = $deck->frontmatterValue('description');

        if ($description !== null) {
            $properties->setDescription(self::text($description, 'description'));
        }
    }

    /**
     * @throws MalformedFrontmatter
    */
    private static function text(mixed $value, string $key): string
    {
        if (!is_string($value)) {
            throw MalformedFrontmatter::wrongType(1, $key, 'a string');
        }

        return $value;
    }

    /**
     * One source slide, and as many written slides as it has steps.
     *
     * @param array<string, SlideLayout> $layouts
    */
    private function write(
        PhpPresentation $presentation,
        SourceSlide $source,
        LayoutRole $role,
        array $layouts,
        ShapeWriter $writer,
    ): void {
        $title = $this->geometry->title($role);
        $level = $source->frontmatterValue('level');
        $blocks = $this->underTitle(
            $this->blocks->parse($source->content(), $source->index() + 1, $this->directory),
            $source,
            $title !== null,
            $this->depth($level, $source->index() + 1),
        );

        // A step nothing was written on is not a slide. A marker on the last line of a
        // slide asks for a click that would reveal nothing, and the file would carry two
        // slides drawn the same way — which is not what the deck said, it is a copy of it.
        $steps = array_reduce(
            $blocks,
            static fn (int $most, Block $block): int => max($most, $block->step()),
            self::FIRST_STEP,
        );

        for ($step = self::FIRST_STEP; $step <= $steps; $step++) {
            $slide = $presentation->createSlide();
            $slide->setSlideLayout($layouts[$role->value]);
            $slide->setName($source->title() === null ? null : sprintf('slide %d', $source->index() + 1));

            if ($source->note() !== null) {
                $this->note($slide, $source->note());
            }

            if ($title !== null && $source->title() !== null) {
                $size = $this->depth($level, $source->index() + 1);

                $writer->title(
                    $slide,
                    $this->blocks->inline->parse($source->title()),
                    $title['box'],
                    $size === null ? $title['size'] : $this->geometry->titleSize($title['size'], $size),
                    $title['alignment'],
                );
            }

            $this->stack($slide, $blocks, $step, $role, $writer, $source->index() + 1);
        }
    }

    /**
     * The depth the frontmatter gave a slide's title, or null where it named none.
     *
     * Read out of the frontmatter rather than from {@see SourceSlide::level()}, which also
     * reports how deep a heading that became the title was written: a slide opening on a
     * `##` would otherwise have its title drawn at body-heading size, which is a change to
     * every deck that ever wrote one and nothing anybody asked for. What the directive means
     * is what it says — how deep to read a title that need not be a heading at all.
     *
     * A `Slide` built by hand rather than parsed is checked here for the same reason `theme`
     * and `layout` are: every directive this renderer acts on is its own to type.
     *
     * @throws MalformedFrontmatter
     */
    private function depth(mixed $level, int $slide): ?int
    {
        if ($level === null) {
            return null;
        }

        if (!is_int($level) || $level < 1) {
            throw MalformedFrontmatter::wrongType($slide, 'level', 'a positive integer');
        }

        return $level;
    }

    /**
     * The heading a role with no title box draws a title as.
     *
     * At the depth the frontmatter named, the same way {@see self::underTitle()} re-reads a
     * heading that became the title: `level: 1` is the top of what a body can draw and every
     * level below it is the size a heading of that depth is drawn at.
     */
    private function titleHeading(string $title, ?int $depth): Heading
    {
        return new Heading(
            $this->blocks->inline->parse($title),
            $depth === null ? 1 : max(1, $depth - 1),
            self::FIRST_STEP,
        );
    }

    /**
     * The slide's blocks, with the heading that became its title taken out of them.
     *
     * A slide's title is its first heading, and that heading is still a block of the content
     * the parser was given. Drawing both would put the same line on the slide twice, so the
     * heading that *is* the title is dropped once the title box has drawn it — and only
     * then, because a role without a title box has nowhere else to draw it.
     *
     * The first *heading* and not the first block, because the title is a heading and a
     * paragraph written above one does not stop it being the title. Only the first heading
     * can be: {@see \MarkdownPresentation\Document\DeckParser} reads no other as one, so a
     * heading further down the slide is content the author put there.
     *
     * The two are told apart by their text and not by their markup, both sides stripped by
     * the one {@see InlineParser} that read the heading: `# Release **v2** notes` is the
     * title of a slide titled `Release **v2** notes`, not a heading drawn a second time on
     * top of it. A strip is lossy, so two titles that read the same are one title — which is
     * what a reader of the slide would say too.
     *
     * On a role with no title box that heading *is* the title, and `$depth` is what says how
     * deep to read it: a blank layout draws its title as a heading, so `level:` would
     * otherwise govern nothing at all there.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param ?int                                                        $depth What `level:` named.
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
    */
    private function underTitle(array $blocks, SourceSlide $source, bool $hasTitleBox, ?int $depth): array
    {
        $title = $source->title();

        if ($title === null) {
            return $blocks;
        }

        $heading = null;
        $at = 0;

        foreach ($blocks as $offset => $block) {
            if ($block instanceof Heading) {
                $heading = $block;
                $at = $offset;

                break;
            }
        }

        if (
            $heading === null
            || InlineParser::text($heading->runs) !== $this->blocks->inline->plain($title)
        ) {
            // A role with no title box has nothing but its body to draw a title in, and a
            // title it does not draw is the silence this library refuses everywhere else —
            // the fifth version of it, after `author:`, `level:`, a trailing marker and a
            // directive in a body. The frontmatter title becomes a heading, which is what a
            // blank slide's title already is.
            return $hasTitleBox ? $blocks : [$this->titleHeading($title, $depth), ...$blocks];
        }

        if ($hasTitleBox) {
            return array_merge(array_slice($blocks, 0, $at), array_slice($blocks, $at + 1));
        }

        if ($depth === null) {
            return $blocks;
        }

        // As a heading rather than as a title box, so the level itself moves: a title of
        // level `$depth` is drawn at the size a heading one level shallower is drawn at, and
        // `level: 1` is the top of what a body can draw.
        $blocks[$at] = new Heading($heading->runs, max(1, $depth - 1), $heading->step);

        return $blocks;
    }

    private function note(WrittenSlide $slide, string $text): void
    {
        $note = new Note();
        $note->createRichTextShape()->createTextRun($text);
        $slide->setNote($note);
    }

    /**
     * A step's blocks, stacked into the role's body boxes.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
    */
    private function stack(
        WrittenSlide $slide,
        array $blocks,
        int $step,
        LayoutRole $role,
        ShapeWriter $writer,
        int $number,
    ): void {
        $columns = $this->columns($blocks, $role);

        foreach ($this->geometry->body($role) as $column => $box) {
            $y = $box->y;
            $bottom = $box->y + $box->height;

            foreach ($this->visible($blocks, $columns, $step, $column) as $block) {
                // A column whose contents are taller than its box runs off the bottom of it,
                // and an image spends the whole of the room left — so both the cursor and
                // the height are held inside the box. A block that does not fit is drawn as
                // small as the room allows rather than over whatever is under it, and
                // {@see self::overflow()} is what says that it had to be.
                $y = min($y, $bottom - SlideGeometry::MIN_SIZE);
                $room = max(0, $bottom - $y);
                $wanted = $writer->height($block, $box, $room);
                $height = max(min($wanted, $room), SlideGeometry::MIN_SIZE);

                if ($height < $wanted) {
                    $this->overflow($number, $step, $column, $block->name(), $wanted, $room);
                }

                $writer->write($slide, $block, new Box($box->x, $y, $box->width, $height), $height);
                $y += $height + SlideGeometry::BLOCK_GAP;
            }
        }
    }

    /**
     * A block that did not fit, said out loud.
     *
     * Clamping without saying so is the one thing this library must not do: a slide with a
     * block quietly squeezed into the last pixel renders as a deck, and nobody looking at it
     * can tell what is missing. Strict mode raises instead, for a caller that would rather
     * have no file.
     *
     * @throws ContentOverflow
     */
    private function overflow(int $slide, int $step, int $column, string $block, int $wanted, int $available): void
    {
        if ($this->strict) {
            throw ContentOverflow::on($slide, $step, $column, $block, $wanted, $available);
        }

        $this->overflows[] = [
            'slide' => $slide,
            'step' => $step,
            'column' => $column,
            'block' => $block,
            'wanted' => $wanted,
            'available' => $available,
        ];
    }

    /**
     * Which column each block belongs to.
     *
     * Two columns divide at the first heading of the second level, because that is the
     * heading a writer puts on a column of a slide and there is nothing else to go on. A deck
     * that never writes one fills the left column and leaves the right empty, which is
     * honest: it asked for two columns and wrote one.
     *
     * Decided once for the whole slide rather than per column, because a block that opens the
     * second column belongs to the second column, and a per-column walk that decided
     * otherwise after collecting it drew it in both.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     *
     * @return list<int>
    */
    private function columns(array $blocks, LayoutRole $role): array
    {
        $columns = [];
        $current = 0;

        foreach ($blocks as $block) {
            if ($role === LayoutRole::TwoColumn && $current === 0 && $this->opensSecondColumn($block)) {
                $current = 1;
            }

            $columns[] = $current;
        }

        return $columns;
    }

    /**
     * The blocks of one column at one step, in order.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param list<int>                                                       $columns Which column each block belongs to.
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
    */
    private function visible(array $blocks, array $columns, int $step, int $column): array
    {
        $visible = [];

        foreach ($blocks as $offset => $block) {
            if ($columns[$offset] === $column && $block->step() <= $step) {
                $visible[] = $block;
            }
        }

        return $visible;
    }

    private function opensSecondColumn(Block $block): bool
    {
        return $block instanceof Heading && $block->level === 2;
    }

    /**
     * PHPPresentation answers a path whose directory is not there by emitting a warning and a
     * low-level "could not close zip file", after a broken archive has been left behind.
     * Saying so here is what makes the failure one a caller can act on.
     *
     * @throws Exception
    */
    private function save(PhpPresentation $presentation, string $path): string
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            throw new Exception(sprintf('There is no directory at "%s" to write "%s" into.', $directory, basename($path)));
        }

        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

        return $path;
    }
}