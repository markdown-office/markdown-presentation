<?php

declare(strict_types=1);

namespace MarkdownPresentation;

use MarkdownPresentation\Exception\UnpatchedDependency;
use PhpOffice\PhpPresentation\Reader\PowerPoint2007;
use PhpOffice\PhpPresentation\Slide\Background\Gradient as BackgroundGradient;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Shape\Drawing\AbstractDrawingAdapter;
use PhpOffice\PhpPresentation\Shape\Drawing\Gd;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\GradientStop;

/**
 * The fixes the library needs from PHPPresentation, and the check that they are there.
 *
 * The library writes a few things a released PHPPresentation cannot: a table that says
 * which row is its header, a shape that says how round its corners are, a run of text
 * that says what it is, a picture that says where it was read from, a gradient of more
 * than two stops, a background painted with a gradient, and a picture that says what
 * shape it is drawn in. It gets them from a patch applied to the installed package.
 *
 * The check is made once per conversion because the alternative is a fatal error in the
 * middle of the work naming a method rather than the reason. A reader of that error
 * would not know to look at the installer.
 *
 * The methods are the ones the patch adds, so the check says what the code needs
 * rather than which version carries it. Three of them are not called by this
 * library — `zipPath`, `setContents` and `getPosition` — and they are there
 * because the patch adds them and a released PHPPresentation has none, so they
 * stand in for the patch being applied. A PHPPresentation that has them by any
 * other means passes, which is what happens once upstream releases them and the
 * patch is dropped.
 */
final class Fixes
{
    /**
     * The methods the patch adds, which a released PHPPresentation does not have.
     *
     * @var array<class-string, string>
     */
    private const REQUIRED = [
        PowerPoint2007::class => 'zipPath',
        Table::class => 'setFirstRow',
        AutoShape::class => 'setRoundRectCorner',
        RichText::class => 'setDescription',
        Gd::class => 'setContents',
        Fill::class => 'getGradientStops',
        GradientStop::class => 'getPosition',
        BackgroundGradient::class => 'getFill',
        AbstractDrawingAdapter::class => 'setGeometry',
    ];

    /**
     * @throws UnpatchedDependency when the installed PHPPresentation lacks them.
     */
    public static function require(): void
    {
        self::requireAll(self::REQUIRED);
    }

    /**
     * @param array<class-string, string> $required
     *
     * @throws UnpatchedDependency when a class does not have the method asked of it.
     */
    public static function requireAll(array $required): void
    {
        foreach ($required as $class => $method) {
            if (! method_exists($class, $method)) {
                throw UnpatchedDependency::withoutTheFixes();
            }
        }
    }
}
