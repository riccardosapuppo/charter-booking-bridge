<?php

declare(strict_types=1);

namespace Charter;

/**
 * Time, so that a rate limit can be tested without sleeping through it.
 */
class Clock
{
    private ?int $pinned = null;

    public function now(): int
    {
        return $this->pinned ?? time();
    }

    public function pin(int $when): void
    {
        $this->pinned = $when;
    }

    public function advance(int $seconds): void
    {
        $this->pinned = $this->now() + $seconds;
    }
}
