<?php

declare(strict_types=1);

namespace Charter;

/**
 * The operator returns names as one object per language. The site is Italian,
 * so Italian if there is one, then English, then whatever else came.
 *
 * The last step is the one that matters: any language beats a blank on the
 * page, so a name in a language nobody expected is still returned rather than
 * silently lost.
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
