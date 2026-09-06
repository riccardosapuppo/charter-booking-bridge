<?php

declare(strict_types=1);

namespace Charter;

/**
 * What the page put in front of the visitor.
 *
 * This is the browser's arithmetic, transcribed from the fragment that draws
 * the boat's page, and it is here for one reason: so that a check can compare
 * the figure the visitor read with the figure the operator booked. Two numbers
 * computed in two places, in two languages, by two people who never met, is
 * exactly the arrangement in which they drift apart without a sound.
 *
 * The page's rule, kept exactly: an extra included in the price adds nothing,
 * and an extra with an amount between two and thirty is multiplied by it.
 */
final class Shown
{
    /**
     * @param list<array<string,mixed>> $ticked the extras the visitor ticked
     */
    public static function total(float $base, array $ticked): float
    {
        $sum = $base;

        foreach ($ticked as $one) {
            if (strtoupper((string) ($one['calculationType'] ?? '')) === 'INCLUDED_IN_PRICE') {
                continue;
            }

            $price = is_numeric($one['price'] ?? null) ? (float) $one['price'] : 0.0;
            $amount = is_numeric($one['amount'] ?? null) ? (float) $one['amount'] : 0.0;
            $quantity = $amount > 1 && $amount <= 30 ? $amount : 1.0;

            $sum += $price * $quantity;
        }

        return $sum;
    }
}
