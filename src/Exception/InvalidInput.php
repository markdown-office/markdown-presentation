<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * Something the author of the deck can fix by naming something else — a theme, a
 * role, a style slot, a delimiter. A failure on this library's own side is not
 * this, and is not the deck author's to fix.
*/
class InvalidInput extends Exception
{
}
