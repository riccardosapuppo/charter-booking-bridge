<?php

declare(strict_types=1);

namespace Charter;

/**
 * A charter period that has been checked: two real days on the calendar, in
 * order, not behind us, and the number of days between them.
 *
 * It exists so that "the dates have been validated" is something the type
 * system says rather than something a reader has to trace. Nothing downstream
 * takes two strings, so nothing downstream can be handed a period that is not
 * one.
 */
final class Week
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $starts,
        public readonly int $ends,
        public readonly int $days,
    ) {
    }
}
