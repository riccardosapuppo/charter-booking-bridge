<?php

declare(strict_types=1);

namespace Charter;

/**
 * What WordPress calls a transient, with one difference that matters.
 *
 * `get` returns null only when nothing was ever stored. A catalogue that came
 * back legitimately empty is stored, and comes back as an empty array.
 *
 * The original could not tell those two apart: every one of its eleven
 * catalogue readers guarded with `is_array($cached) && !empty($cached)`, so a
 * catalogue the manager answered with an empty list was fetched again on every
 * single request, for ever, and nothing ever looked wrong.
 */
interface Cache
{
    /** @return array<mixed>|null null when this key was never written */
    public function get(string $key): ?array;

    /** @param array<mixed> $value */
    public function put(string $key, array $value, int $seconds): void;
}
