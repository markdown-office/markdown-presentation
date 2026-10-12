<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\Slide as SourceSlide;
use MarkdownPresentation\Exception\ContentOverflow;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\InvalidInput;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\MalformedTemplate;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\TooManySlides;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Filesystem\PathScope;
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
use MarkdownPresentation\Theme\ThemeResolver;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Slide as WrittenSlide;
use PhpOffice\PhpPresentation\Slide\Note;
use PhpOffice\PhpPresentation\Slide\SlideLayout;

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
 * `:::` fence, an HTML comment, an unclosed `::note` — is a
 * failure from {@see \MarkdownPresentation\Render\BlockParser} rather than a shape carrying
 * the directive's own text. That last one is the case this library exists for: a deck
 * written by a model, a directive it does not know, and a slide with the directive printed
 * on it reads as a rendered deck.
 *
 * Two things it does not do are somebody else's job, and both classes that do them sit in
 * this directory rather than being reached through a caller: {@see MasterLayouts} writes the
 * master, the layouts and the document's metadata, and {@see DeckWriter} writes the file and
 * runs the passes over it. What is left here is the walk: a deck's blocks, and the shapes
 * they are drawn as.
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

    /** The step a slide with no marker in it is drawn at. */
    private const FIRST_STEP = 1;

    /**
     * The id the first shape written on a slide takes.
     *
     * Not 1: the shape tree the format requires every slide to carry is what takes that, and
     * PHPPresentation's `writeShapeCollection()` counts it in with the shapes — it starts at
     * 1 and increments before it uses the number, so the first shape of the deck is 2.
     * {@see PlaceholderPass::FIRST_ID} is the same number for the same reason.
     */
    private const FIRST_SHAPE_ID = 2;

    /**
     * The most slides one deck may write.
     *
     * One slide out per slide in, so this bounds what the deck itself asks for rather than
     * anything a marker multiplies it by — the reason it was a second ceiling, a deck of
     * markers asking for hundreds of written slides out of a few lines, is gone with a step
     * being an animation rather than a slide. What is left is the plain fact that a
     * presentation that long is not one, and it is still worth refusing before the work
     * rather than after it.
     *
     * 1000 is five times the longest talk anybody gives, which is the point of a ceiling that
     * is generous: it bounds the damage without being something a real deck can reach by
     * accident.
     */
    public const MAX_SLIDES = 1000;

    /**
     * Every block that did not fit its slide during the last render, in the order they were
     * laid out.
     *
     * @var list<array{slide: int, step: int, column: int, block: string, wanted: int, available: int}>
     */
    private array $overflows = [];

    private readonly PathScope $files;

    /**
     * @param string       $directory  The directory a relative image path in the deck
     *                                 resolves against, which is the directory the deck
     *                                 itself is in. It is also the first of the roots an
     *                                 image may be read from: see {@see self::$files}.
     * @param bool         $strict     Whether a block that does not fit is
     *                                 {@see ContentOverflow} rather than clamped and
     *                                 recorded. Strict is for a caller that would rather
     *                                 have no file than a file with a slide on it that is
     *                                 missing something.
     * @param list<string> $imageRoots Further directories a deck may read a picture from,
     *                                 for a library of images the deck draws from by name
     *                                 rather than shipping. The caller's decision and never
     *                                 the deck's: a deck that could widen this would be the
     *                                 arbitrary path read this constructor argument exists
     *                                 to stop.
     */
    public function __construct(
        private readonly string $directory = '.',
        private readonly ThemeResolver $themes = new ThemeResolver(),
        private readonly BlockParser $blocks = new BlockParser(),
        private readonly SlideGeometry $geometry = new SlideGeometry(),
        private readonly bool $strict = false,
        array $imageRoots = [],
    ) {
        $this->files = new PathScope([$this->directory, ...$imageRoots]);
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
     * The step is the block's own, which is what makes the entry worth reading now that a
     * slide is written once: it names the click the reader would have reached the block on,
     * rather than repeating every step of a build-up that was measured once.
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
     * @throws MalformedTemplate When the theme's `template.pptx` cannot be read.
     * @throws TooManySlides    When the deck asks for more slides than {@see self::MAX_SLIDES}.
     * @throws \MarkdownPresentation\Exception\Exception When the file cannot be written.
     *
     * @return string The path written.
     */
    public function render(Deck $deck, string $path): string
    {
        $this->overflows = [];

        // Before anything is read rather than while the slides are being built, so a deck
        // over the ceiling is refused with no work done rather than after every one of them.
        // It is the deck's own slide count now: one source slide is one written slide, and
        // {@see self::blocks()} has nothing left to add up.
        if ($deck->count() > self::MAX_SLIDES) {
            throw TooManySlides::in($deck->count(), self::MAX_SLIDES);
        }

        foreach ($deck->slides() as $slide) {
            $this->check($slide);
        }

        $theme = $this->theme($deck);
        $roles = $this->roles($deck, $theme);

        $presentation = new PhpPresentation();

        $used = $this->usedRoles($roles);
        // The theme's type scale and spacing first, then the boxes a template gives for the
        // roles it covers: a template places, and placing is the later of the two answers.
        // The parentheses are load-bearing, not decoration: `new X()->y()` on one expression
        // is PHP 8.4 syntax, and `composer.json` promises 8.2, where it does not parse. Written
        // without them this file cannot be loaded at all, so every test that renders a deck
        // fails on the 8.2 job and nothing local says why.
        $geometry = (new SlideGeometry(style: $theme->theme()->style()))->with($this->template($theme, $used));
        $layouts = MasterLayouts::build($presentation, $deck, $theme, $used, $this->blocks->inline);

        $writer = new ShapeWriter($theme->theme(), $geometry);
        $blocks = $this->blocks($deck);
        $builds = [];

        foreach ($deck->slides() as $slide) {
            // Keyed by the slide's own number, which is the number of the part it is written
            // to: one slide out per source slide in, in order, with the constructor's empty
            // one removed below.
            $builds[$slide->index() + 1] = $this->write(
                $presentation,
                $slide,
                $roles[$slide->index()],
                $layouts,
                $writer,
                $geometry,
                $blocks[$slide->index()],
            );
        }

        // PHPPresentation's constructor leaves an empty slide behind so that a fresh object is
        // usable. A deck that had slides does not want it, and a deck that had none still
        // does: PowerPoint will not open a presentation with no slides at all, so the empty
        // one is what a deck with no slides is.
        if ($deck->count() > 0) {
            $presentation->removeSlideByIndex(0);
        }

        return DeckWriter::save($presentation, $path, $geometry, $used, $this->chain($builds));
    }

    /**
     * The shape→step maps {@see self::write()} returns as the steps each slide's click chain
     * is written from.
     *
     * Turned inside out here rather than in `write()`, which knows which step each of its
     * shapes is on and not which click it belongs to: a step is revealed by the click that
     * *precedes* it, and the first step is on the slide before any click at all, so it names
     * no branch and is dropped. Steps nothing was written on are dropped for the same reason
     * {@see TimingPass::addTiming()} drops them — a click that reveals nothing is a click the
     * reader spends to see no change, which is also why a marker naming a step nothing was
     * written on costs no click rather than an empty one.
     *
     * @param array<int, array<int, int>> $builds Slide number to the shapes it wrote.
     *
     * @return array<int, list<list<int>>> Slide number to its steps, each to the ids it shows.
     */
    private function chain(array $builds): array
    {
        $chain = [];

        foreach ($builds as $number => $steps) {
            $clicks = [];

            foreach ($steps as $id => $step) {
                if ($step > self::FIRST_STEP) {
                    $clicks[$step] ??= [];
                    $clicks[$step][] = $id;
                }
            }

            ksort($clicks);
            $chain[$number] = array_values($clicks);
        }

        return $chain;
    }

    /**
     * Every source slide's blocks, read once, keyed by the slide's index.
     *
     * Read here rather than in {@see self::write()} so that a slide which cannot be read at
     * all — an image that does not resolve, a marker that goes backwards — is a failure with
     * no file written behind it, rather than one found after the first few slides are built.
     *
     * @return array<int, list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>>
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
     */
    private function blocks(Deck $deck): array
    {
        $blocks = [];

        foreach ($deck->slides() as $source) {
            $blocks[$source->index()] = $this->blocks->parse(
                $source->content(),
                $source->index() + 1,
                $this->directory,
                $this->files,
            );
        }

        return $blocks;
    }

    /**
     * The boxes a theme's own `template.pptx` gives the roles the deck uses.
     *
     * A theme ships one or ships none, and this is where that decides anything: the roles a
     * template covers are drawn in the template's geometry and the rest keep
     * {@see SlideGeometry}'s, which is the precedence — a template fills in what it has and
     * says nothing about what it does not, the same rule `layouts.yml` follows. Read before
     * anything is built, so a template that cannot be read fails while there is still no file
     * on disk to clean up.
     *
     * Keyed by the layout *name* rather than by the role, because that is what the template
     * has: two roles a theme maps onto one name are one layout, and take the same boxes.
     *
     * @param list<LayoutRole> $roles
     *
     * @return array<string, array{title?: Box, body?: non-empty-list<Box>}>
     *
     * @throws MalformedTemplate
     */
    private function template(LoadedTheme $theme, array $roles): array
    {
        if ($theme->template() === null) {
            return [];
        }

        $layouts = (new TemplateGeometry((string) $theme->template()))->boxes();
        $boxes = [];

        foreach ($roles as $role) {
            $name = $theme->layout($role->value);

            if (isset($layouts[$name])) {
                $boxes[$role->value] = $layouts[$name];
            }
        }

        return $boxes;
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

            $role = trim($named);

            if (LayoutRole::tryFrom($role) === null) {
                throw UnknownLayoutRole::notARoleOn($slide->index() + 1, $role, LayoutRole::names());
            }

            // Asked of the theme rather than checked here, because a role the theme does not
            // map is the theme's to fix and its message already says so.
            $theme->layout($role);

            $roles[$slide->index()] = LayoutRole::from($role);
        }

        return $roles;
    }

    /**
     * The roles the deck's slides use, in first-seen order and each of them once.
     *
     * Only the roles that are used are built. A theme that maps some of them would
     * otherwise fail on the ones its deck never asked for, which is not the same failure as a
     * deck that asks for one it did not map.
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
     * One source slide as the one written slide it is, and the shapes each of its steps
     * shows.
     *
     * A step used to be a slide of its own, which made a build of twenty steps twenty copies
     * of one slide in the package. It is an animation on one slide instead — every block is
     * written at once and {@see TimingPass} names the shapes each click reveals — so what is
     * measured here is the finished slide, which is the strictest of the drawings it used to
     * make: a block that did not fit any step still does not fit, and one that fitted the last
     * step fits exactly.
     *
     * The returned map is what makes a step an animation rather than a slide: shape id to
     * step. {@see self::stack()} counts the shapes as it writes them, because the id is a
     * position in the slide's shape collection and the collection is the only place the
     * number is actually kept.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param array<string, SlideLayout>                                     $layouts
     *
     * @return array<int, int> Shape id to the step it appears on, in write order.
     */
    private function write(
        PhpPresentation $presentation,
        SourceSlide $source,
        LayoutRole $role,
        array $layouts,
        ShapeWriter $writer,
        SlideGeometry $geometry,
        array $blocks,
    ): array {
        $title = $geometry->title($role);
        $level = $source->frontmatterValue('level');
        $blocks = $this->underTitle(
            $blocks,
            $source,
            $title !== null,
            $this->depth($level, $source->index() + 1),
        );

        $slide = $presentation->createSlide();
        $slide->setSlideLayout($layouts[$role->value]);
        $slide->setName($source->title() === null ? null : sprintf('slide %d', $source->index() + 1));

        if ($source->note() !== null) {
            $this->note($slide, $source->note());
        }

        // The title is written before the body and is never animated: it is on the slide from
        // the first click and never changes, so it is counted here and named in no step.
        $next = self::FIRST_SHAPE_ID;

        if ($title !== null && $source->title() !== null) {
            $size = $this->depth($level, $source->index() + 1);

            $writer->title(
                $slide,
                $this->blocks->inline->parse($source->title()),
                $title['box'],
                $size === null ? $title['size'] : $geometry->titleSize($title['size'], $size),
                $title['alignment'],
            );

            $next++;
        }

        return $this->stack($slide, $blocks, $role, $writer, $source->index() + 1, $geometry, $next);
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
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks The slide's blocks, in reading order.
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

        $drawn = $heading;

        if (!$hasTitleBox && $depth !== null) {
            // As a heading rather than as a title box, so the level itself moves: a title of
            // level `$depth` is drawn at the size a heading one level shallower is drawn at,
            // and `level: 1` is the top of what a body can draw.
            $drawn = new Heading($heading->runs, max(1, $depth - 1), $heading->step);
        }

        // One list, then one question: a title box draws the title, so the heading goes
        // rather than being drawn a second time; without one the heading *is* the title, so
        // it stays, at the depth it was written unless `level:` named another.
        $blocks[$at] = $drawn;
        $kept = array_values($blocks);

        return $hasTitleBox
            ? array_merge(array_slice($kept, 0, $at), array_slice($kept, $at + 1))
            : $kept;
    }

    private function note(WrittenSlide $slide, string $text): void
    {
        $note = new Note();
        $note->createRichTextShape()->createTextRun($text);
        $slide->setNote($note);
    }

    /**
     * Every block of a slide, stacked into the role's body boxes, and the step each written
     * shape belongs to.
     *
     * Stacked as shapes rather than as blocks: {@see TextFrame::group()} decides which blocks
     * share one text frame first, because the air between two of them is then the frame's own
     * paragraph spacing rather than room taken out of the column between two boxes. What
     * follows is the arithmetic it has always been, one drawable at a time.
     *
     * `$first` is the id the first shape written here will take, which is one past the title
     * where there is one. The ids are counted rather than looked up because a shape's id is
     * its position in the slide's shape collection — the tree's own group is 1, and
     * PHPPresentation's `writeShapeCollection()` increments before it uses the number — and
     * {@see ShapeWriter::write()} adds exactly one shape per call, which is what makes the
     * count and the file agree.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     *
     * @return array<int, int> Shape id to the step it appears on, in write order.
     */
    private function stack(
        WrittenSlide $slide,
        array $blocks,
        LayoutRole $role,
        ShapeWriter $writer,
        int $number,
        SlideGeometry $geometry,
        int $first,
    ): array {
        $columns = $this->columns($blocks, $role);
        $steps = [];

        foreach ($geometry->body($role) as $column => $box) {
            $y = $box->y;
            $bottom = $box->y + $box->height;
            $visible = $this->visible($blocks, $columns, $column);

            foreach (TextFrame::group($visible) as $drawable) {
                // A column whose contents are taller than its box runs off the bottom of it,
                // and an image spends the whole of the room left — so both the cursor and
                // the height are held inside the box. A shape that does not fit is drawn as
                // small as the room allows rather than over whatever is under it, and
                // {@see self::overflow()} is what says that it had to be.
                $y = min($y, $bottom - SlideGeometry::MIN_SIZE);
                $room = max(0, $bottom - $y);
                $wanted = $writer->height($drawable, $box, $room);
                $height = max(min($wanted, $room), SlideGeometry::MIN_SIZE);

                if ($height < $wanted) {
                    $this->overflow($number, $this->stepOf($drawable), $column, $drawable->name(), $wanted, $room);
                }

                $writer->write($slide, $drawable, new Box($box->x, $y, $box->width, $height), $height);
                $steps[$first++] = $this->stepOf($drawable);
                $y += $height + $geometry->style()->blockGap();
            }
        }

        return $steps;
    }

    /**
     * The step a drawn shape belongs to.
     *
     * A drawable that is a block says so itself; a {@see TextFrame} does not, because it is
     * several blocks drawn as one shape. Every block in a frame is on the same step —
     * {@see TextFrame::group()} ends a run at a step boundary for exactly that reason — so
     * the frame's step is its first block's.
     *
     * @param Code|TextFrame|Heading|Image|Quote|Rule|Table $drawable
     */
    private function stepOf(Code|TextFrame|Heading|Image|Quote|Rule|Table $drawable): int
    {
        return $drawable instanceof TextFrame
            ? $drawable->firstStep()
            : $drawable->step();
    }

    /**
     * A block that did not fit, said out loud.
     *
     * Clamping without saying so is the one thing this library must not do: a slide with a
     * block quietly squeezed into the last pixel renders as a deck, and nobody looking at it
     * can tell what is missing. Strict mode raises instead, for a caller that would rather
     * have no file.
     *
     * @param int $step The step of the block that did not fit, which is the click the reader
     *                  would have reached it on.
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
     * Both two-column roles divide the same way, so `image-text` reads as the layout it is
     * named after: the picture and the text before the `##` on the left, everything after it
     * on the right.
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
            if ($this->splits($role) && $current === 0 && $block instanceof Heading && $block->level === 2) {
                $current = 1;
            }

            $columns[] = $current;
        }

        return $columns;
    }

    /**
     * The blocks of one column, in order.
     *
     * Every one of them, whatever step it is on: a build-up is one slide holding every
     * block, and which of them a reader can see is what the click chain says rather than
     * which of them were written.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param list<int>                                                       $columns Which column each block belongs to.
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
    */
    private function visible(array $blocks, array $columns, int $column): array
    {
        $visible = [];

        foreach ($blocks as $offset => $block) {
            if ($columns[$offset] === $column) {
                $visible[] = $block;
            }
        }

        return $visible;
    }

    /**
     * Whether a role's blocks are divided between two columns at all.
     *
     * Asked of the geometry rather than read off a list of roles, because the boxes are the
     * fact: a template that gives a role two body boxes has made it two columns whatever the
     * role is called, and one that gives it one has not.
     */
    private function splits(LayoutRole $role): bool
    {
        return count($this->geometry->body($role)) > 1;
    }

}
