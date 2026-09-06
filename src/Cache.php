<?php

declare(strict_types=1);

namespace Charter;

/**
 * What WordPress calls a transient, with one thing said out loud.
 *
 * `get` returns null when, and only when, nothing was ever stored under this
 * key. A list that came back from the operator legitimately empty is stored,
 * and comes back as an empty array.
 *
 * That distinction is the whole interface. Without it there is no way to write
 * a cache that can hold an empty answer, and an empty answer that cannot be
 * held is re-fetched on every request for ever, quietly, while nothing looks
 * broken.
 */
interface Cache
{
    /** @return array<mixed>|null null when this key was never written */
    public function get(string $key): ?array;

    /** @param array<mixed> $value */
    public function put(string $key, array $value, int $seconds): void;
}
