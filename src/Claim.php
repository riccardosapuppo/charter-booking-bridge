<?php

declare(strict_types=1);

namespace Charter;

/**
 * One thing this repository says about itself, with the working shown and a
 * verdict that can go either way.
 */
final class Claim
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public readonly string $title,
        public readonly bool $holds,
        public readonly array $lines,
    ) {
    }
}
