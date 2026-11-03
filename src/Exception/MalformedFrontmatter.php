<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

use Throwable;

/**
 * A frontmatter block that is not YAML, or is YAML that is not a mapping.
 *
 * A deck is written by a person or a model, so the failure belongs with the input
 * rather than with the machine: the two are told apart because only this one is
 * worth writing a different document to fix.
*/
final class MalformedFrontmatter extends InvalidInput
{
    public static function notYaml(int $slide, Throwable $previous): self
    {
        return new self(
            sprintf('The frontmatter of slide %d is not valid YAML: %s', $slide, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function notAMapping(int $slide): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d is not a mapping of keys to values, so there is nothing to read configuration from.',
            $slide,
        ));
    }

    /**
     * A value of the wrong type in a frontmatter.
     *
     * The slide number is null where the frontmatter is a template definition's: a
     * definition is not a slide of the deck and has no number, and naming one would name a
     * slide that has nothing to do with it.
     */
    public static function wrongType(?int $slide, string $key, string $wanted): self
    {
        if ($slide === null) {
            return new self(sprintf(
                'The frontmatter of a template definition has a "%s" that is not %s.',
                $key,
                $wanted,
            ));
        }

        return new self(sprintf(
            'The frontmatter of slide %d has a "%s" that is not %s.',
            $slide,
            $key,
            $wanted,
        ));
    }

    /**
     * Two definitions of the same template.
     *
     * A template is defined once. Two of them are a mistake, and the second one is the one
     * that would be drawn — so it is refused rather than left to win.
     */
    public static function duplicateTemplate(string $name): self
    {
        return new self(sprintf(
            'The template "%s" is defined twice: a template is defined once.',
            $name,
        ));
    }

    /**
     * A setting of a template that is not one.
     *
     * The settings of a template are the definition's, and a definition is not a slide of
     * the deck and has no number.
     *
     * @param list<string> $known
     */
    public static function unknownSetting(string $where, string $key, array $known): self
    {
        return new self(sprintf(
            'The "%s" of a template definition names "%s", which is not a setting. The settings are: %s.',
            $where,
            $key,
            implode(', ', $known),
        ));
    }

    /**
     * The settings of a template, on a slide that names one but is not one.
     *
     * The settings of a template belong to its definition, and they are named as the
     * template's: a slide that draws a template names its own settings in the ordinary
     * keys, and the ordinary keys mean the slide's own everywhere.
     */
    public static function templateSettingsOnASlide(int $slide): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d names "template-settings", which are the settings of a template, '
            . 'and the slide is not one: a definition names a template and nothing but its own settings, '
            . 'so remove the other keys to define the template with them — or name your own settings in the '
            . 'ordinary keys, which are the slide\'s own.',
            $slide,
        ));
    }

    /**
     * The settings of a template, where no template is named.
     *
     * The settings of a template belong to its definition, and a definition names the
     * template it defines.
     */
    public static function templateSettingsWithoutATemplate(int $slide): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d names "template-settings", which are the settings of a template, '
            . 'and names no template: write "template: name" beside them to define a template with them.',
            $slide,
        ));
    }

    /**
     * The name of a template, in the frontmatter of the slide that defines it.
     *
     * Without a slide number, because a template definition is not a slide of the deck and
     * has no number: it is furniture, and the number a reader would look for is the number
     * of the slide it is drawn on.
     */
    public static function notATemplateName(): self
    {
        return new self(sprintf(
            'A template definition names the template it defines, and the name is a string: template: brand.',
        ));
    }
}
