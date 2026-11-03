<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\MalformedGradient;
use MarkdownPresentation\Render\Free\Colour;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Slide\AbstractBackground;
use PhpOffice\PhpPresentation\Slide\Background\Color as WrittenColour;
use PhpOffice\PhpPresentation\Slide\Background\Gradient as WrittenGradient;
use PhpOffice\PhpPresentation\Style\Color;

/**
 * The background a slide is drawn on: one colour or one gradient.
 *
 * The background of a slide is the slide's own and not the theme's: the master keeps the
 * theme's page colour, and a slide that names a background of its own is drawn on it.
 */
final class Background
{
    private function __construct(
        private readonly ?Color $colour,
        private readonly ?Gradient $gradient,
    ) {
    }

    public static function colour(Color $colour): self
    {
        return new self($colour, null);
    }

    public static function gradient(Gradient $gradient): self
    {
        return new self(null, $gradient);
    }

    /**
     * The background a slide's settings hold.
     *
     * The four keys are one background: a slide that names a gradient over a template's
     * colour has a gradient, and not a gradient and a colour.
     *
     * @param array<string, mixed> $background The background's own keys.
     *
     * @throws MalformedFrontmatter
     * @throws MalformedGradient
     */
    public static function read(int $slide, array $background, Theme $theme): self
    {
        $where = sprintf('The frontmatter of slide %d gives', $slide);

        if (array_key_exists('background-gradient', $background)) {
            if (array_key_exists('background', $background)) {
                throw MalformedGradient::refused(
                    $where,
                    'a background and a gradient background, and a background is one colour or '
                    . 'one gradient: background="accent1" or background-gradient="accent1,lt2".',
                );
            }

            $radial = self::radial($slide, $background);
            $angle = self::angle($slide, $background, $radial);

            return self::gradient(Gradient::of(
                $where,
                self::text($slide, 'background-gradient', $background['background-gradient']),
                $theme,
                $radial,
                $angle,
            ));
        }

        if (array_key_exists('background', $background)) {
            // An angle and a radial flag belong to a gradient, and a colour has neither: the
            // two are one setting, and one of them with the other is a mistake.
            foreach (['background-angle', 'background-radial'] as $key) {
                if (array_key_exists($key, $background)) {
                    throw MalformedGradient::refused(
                        $where,
                        'a background and a ' . $key . ', and a background of a colour has '
                        . 'neither an angle nor a radial flag.',
                    );
                }
            }

            return self::colour(self::readColour($slide, $background['background'], $theme));
        }

        throw MalformedGradient::refused(
            $where,
            'an angle or a radial gradient on its own, and a background is one colour or one '
            . 'gradient: background="accent1" or background-gradient="accent1,lt2".',
        );
    }

    /**
     * The background written on the slide.
     */
    public function written(): AbstractBackground
    {
        if ($this->gradient !== null) {
            return (new WrittenGradient())->setFill($this->gradient->fill());
        }

        return (new WrittenColour())->setColor($this->colour);
    }

    /**
     * The colour the background is painted with.
     *
     * @throws MalformedFrontmatter
     */
    private static function readColour(int $slide, mixed $written, Theme $theme): Color
    {
        $wanted = 'a colour such as "#3F6FB5", or a theme slot such as "accent1"';

        if (!is_string($written) || trim($written) === '') {
            throw MalformedFrontmatter::wrongType($slide, 'background', $wanted);
        }

        $written = strtolower(trim($written));

        if (Colour::isNone($written)) {
            throw MalformedFrontmatter::wrongType($slide, 'background', $wanted);
        }

        $colour = Colour::resolve($written, $theme);

        if ($colour === null) {
            throw MalformedFrontmatter::wrongType($slide, 'background', $wanted);
        }

        return $colour;
    }

    /**
     * The angle the background's gradient runs at.
     *
     * @param array<string, mixed> $background
     *
     * @throws MalformedFrontmatter
     * @throws MalformedGradient
     */
    private static function angle(int $slide, array $background, bool $radial): ?int
    {
        if (!array_key_exists('background-angle', $background)) {
            return null;
        }

        $where = sprintf('The frontmatter of slide %d gives', $slide);
        $written = $background['background-angle'];

        if (!is_int($written) && !(is_string($written) && preg_match('/\A-?\d+\z/', trim($written)) === 1)) {
            throw MalformedFrontmatter::wrongType($slide, 'background-angle', 'a whole number of degrees');
        }

        if ($radial) {
            throw MalformedGradient::angle(
                $where,
                (string) $written,
                'A radial gradient runs from the middle outwards and has no angle.',
            );
        }

        return Gradient::angle($where, $written);
    }

    /**
     * Whether the background's gradient is radial.
     *
     * @param array<string, mixed> $background
     *
     * @throws MalformedFrontmatter
     */
    private static function radial(int $slide, array $background): bool
    {
        if (!array_key_exists('background-radial', $background)) {
            return false;
        }

        $written = $background['background-radial'];

        if (!is_bool($written)) {
            throw MalformedFrontmatter::wrongType($slide, 'background-radial', 'true or false');
        }

        return $written;
    }

    /**
     * A value the frontmatter wrote, as a string.
     *
     * @throws MalformedFrontmatter
     */
    private static function text(int $slide, string $key, mixed $written): string
    {
        if (!is_string($written) || trim($written) === '') {
            throw MalformedFrontmatter::wrongType($slide, $key, 'a list of stops');
        }

        return trim($written);
    }
}
