<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * The slide's own markers, as a CommonMark extension.
 *
 * Registered ahead of the generic directives extension because the two grammars meet: both
 * read a line of colons and a name, and the markers a slide is written with carry an
 * argument the RFC's syntax has nowhere to put.
 */
final class MarkerExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addBlockStartParser(new MarkerStartParser(), 100);
    }
}
