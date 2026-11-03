<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A gradient that is not one: too few stops, a stop that names no colour, a position that
 * is not a number, an angle that is not a whole number of degrees.
 *
 * The subject of the sentence is the caller's, because a gradient is written in two places —
 * in an attribute and in a frontmatter — and each of them says where it was written in its
 * own words.
 */
final class MalformedGradient extends InvalidInput
{
    /**
     * The stops of a gradient that are not stops.
     *
     * @param string $where   The subject of the sentence: "Slide 3 gives "rectangle"".
     * @param string $written What the deck wrote.
     * @param string $advice  What would have worked instead.
     */
    public static function written(string $where, string $written, string $advice): self
    {
        return new self(sprintf('%s a gradient of "%s". %s', $where, $written, $advice));
    }

    /**
     * A gradient that cannot be written at all, where the mistake is not in a value.
     *
     * @param string $where   The subject of the sentence: "The frontmatter of slide 3 gives".
     * @param string $advice  What would have worked instead.
     */
    public static function refused(string $where, string $advice): self
    {
        return new self(sprintf('%s %s', $where, $advice));
    }

    /**
     * The angle of a gradient that is not an angle.
     *
     * @param string $where   The subject of the sentence: "Slide 3 gives "rectangle"".
     * @param string $written What the deck wrote.
     * @param string $advice  What would have worked instead.
     */
    public static function angle(string $where, string $written, string $advice): self
    {
        return new self(sprintf('%s an angle of "%s". %s', $where, $written, $advice));
    }
}
