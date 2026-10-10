<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `styles.yml` key that is not a Word style slot.
 *
 * A slot that does not exist is a typo, and a typo here would otherwise be
 * ignored at render time and produce a document with none of the styling the
 * theme asked for. The message names the slots that are real, so the fix is in the
 * message.
*/
final class UnknownStyleSlot extends InvalidInput
{
    /**
     * @param list<string> $slots
    */
    public static function named(string $file, string $slot, array $slots): self
    {
        return new self(sprintf(
            'In "%s": "%s" is not a style slot. The slots are: %s.',
            $file,
            $slot,
            implode(', ', $slots),
        ));
    }
}