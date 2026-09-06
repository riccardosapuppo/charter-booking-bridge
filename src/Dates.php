<?php

declare(strict_types=1);

namespace Charter;

/**
 * Dates that are dates.
 *
 * Two days on the calendar, in that order, not behind us, and not further ahead
 * than the season goes. Anything else throws before it can reach the operator.
 *
 * The calendar part is the one that is easy to skip: a regular expression says
 * that 31.02.2026 has the shape of a date, and PHP will quietly turn it into
 * the 3rd of March. {@see checkdate()} is what makes the difference, and
 * {@see Week} is what carries the result, so that nothing downstream takes two
 * strings and nothing downstream has to wonder.
 */
final class Dates
{
    /** How far ahead a period may start. A year and a half of season. */
    private const HORIZON_DAYS = 550;

    /**
     * @throws BadPeriod with a message that is safe to show to whoever asked
     */
    public static function period(mixed $from, mixed $to, int $now): Week
    {
        $start = self::day($from);
        $end = self::day($to);

        if ($start === null || $end === null) {
            throw new BadPeriod('The dates are not a date.');
        }

        if ($end <= $start) {
            throw new BadPeriod('The end of the charter is not after its start.');
        }

        $today = (int) (floor($now / 86400) * 86400);

        if ($start < $today) {
            throw new BadPeriod('That week has already been.');
        }

        if ($start > $today + self::HORIZON_DAYS * 86400) {
            throw new BadPeriod('That week is further ahead than the season goes.');
        }

        return new Week(
            gmdate('d.m.Y', $start),
            gmdate('d.m.Y', $end),
            $start,
            $end,
            (int) round(($end - $start) / 86400),
        );
    }

    /**
     * A single day, as a UTC timestamp at midnight, from either shape the site
     * sends. Null when the value is not a day on the calendar, which includes
     * every day the shape allows and the calendar does not.
     */
    public static function day(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $said) === 1) {
            [$day, $month, $year] = [(int) $said[1], (int) $said[2], (int) $said[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $said) === 1) {
            [$day, $month, $year] = [(int) $said[3], (int) $said[2], (int) $said[1]];
        } else {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return (int) gmmktime(0, 0, 0, $month, $day, $year);
    }

    /**
     * The default week the site offers when nobody has picked one: the next
     * Saturday, and the Saturday after it. Saturday to Saturday is how the
     * fleet is let.
     */
    public static function nextWeek(int $now): Week
    {
        $day = (int) gmdate('w', $now);
        $ahead = (6 - $day + 7) % 7;

        if ($ahead === 0) {
            $ahead = 7;
        }

        $start = (int) (floor($now / 86400) * 86400) + $ahead * 86400;

        return new Week(gmdate('d.m.Y', $start), gmdate('d.m.Y', $start + 7 * 86400), $start, $start + 7 * 86400, 7);
    }
}
