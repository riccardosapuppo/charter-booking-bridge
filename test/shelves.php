<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * The shelves on the front page.
 *
 * This is the check that came out of `php bin/walkthrough.php` rather than out
 * of the code: a shelf headed "special offers" is worth opening as a visitor,
 * because an empty carousel on a fleet that plainly has offers looks like
 * nothing at all to anybody reading a diff.
 */
return [
    'the discounted shelf shows the discounted boats' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtsCarousel(
            $bench->week() + ['take' => 4, 'promoOnly' => 1],
            Caller::anonymous(),
        );

        check_same(true, $said->ok, 'the shelf did not load: ' . $said->said);

        $items = $said->body['items'] ?? [];

        // Two of the six invented boats are discounted, and they are the two
        // oldest — which is how discounting works everywhere: it is done on the
        // unsold end of the fleet. So a shelf that sorts by build year and cuts
        // to the newest four before filtering finds nothing, and the shelf that
        // filters first finds both.
        check_same(
            2,
            count($items),
            'the shelf of discounted boats has ' . count($items) . ' on it, and the fleet has two',
        );

        foreach ($items as $one) {
            check_true(
                ($one['discountPercent'] ?? 0) > 0,
                'a boat with no discount is on the discounted shelf: ' . check_show($one['name']),
            );
        }

        // And the ones it found are the two the fleet discounts, by name, so
        // that "two" is not two of anything.
        $names = array_column($items, 'name');
        sort($names);

        check_same(['Echo', 'Foxtrot'], $names, 'the discounted shelf found ' . check_show($names));
    },

    'the ordinary shelf is the newest boats, and it is cut to what was asked for' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtsCarousel($bench->week() + ['take' => 3], Caller::anonymous());

        check_same(true, $said->ok, 'the shelf did not load: ' . $said->said);

        $years = array_column($said->body['items'] ?? [], 'buildYear');

        check_same(3, count($years), 'a shelf asked for three boats came back with ' . count($years));
        check_same([2023, 2022, 2021], $years, 'the shelf is not showing the newest boats first: ' . check_show($years));
    },
];
