<?php

declare(strict_types=1);

namespace Charter;

/**
 * The manager returns names as one object per language. The site is Italian, so
 * Italian if there is one, then English, then whatever else came.
 *
 * The original listed five languages and returned an empty string for anything
 * outside the list, which is a silent way to lose a name. This one falls back
 * to the first non-empty text of any language, because a name in the wrong
 * language beats a blank on the page.
 */
final class Text
{
    public static function of(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return '';
        }

        foreach (['textIT', 'textEN'] as $preferred) {
            $said = $value[$preferred] ?? null;

            if (is_string($said) && $said !== '') {
                return $said;
            }
        }

        foreach ($value as $key => $said) {
            if (is_string($key) && str_starts_with($key, 'text') && is_string($said) && $said !== '') {
                return $said;
            }
        }

        return '';
    }
}
