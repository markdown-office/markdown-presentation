<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A conversion that was asked for without anything to convert.
 *
 * The converter was given no deck: not a path, and not the bytes of one.
 * There is nothing to do about it that the deck's author could have done
 * differently, and the error says what was missing.
 */
final class NothingToConvert extends Exception
{
    public static function noSource(): self
    {
        return new self(
            'There is no deck to convert. Give one to the constructor, or the bytes to '
            . 'PowerPointToMarkdown::toMarkdown().',
        );
    }
}
