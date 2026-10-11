<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

use RuntimeException;

/**
 * The base for every failure this library reports, so one `catch` covers all of
 * them without also catching the failures of the libraries underneath.
*/
class Exception extends RuntimeException
{
}
