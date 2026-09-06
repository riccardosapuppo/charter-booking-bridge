<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * The shelves on the front page.
 *
 * This one was not found by reading the file. It was found by walking the site
 * as a visitor — `php bin/walkthrough.php` — and noticing that the shelf headed
 * "special offers" was empty while the fleet plainly had offers on it.
 */
return [
    'the discounted shelf shows the discounted boats' => static function (string $which): void {
        $bench = Bench::of($which);

        $said = $bench->routes->yachtsCarousel(
            $bench->week() + ['take' => 4, 'promoOnly' => 1],
            Caller::anonymous(),
        );

        check_same(true, $said->ok, 'the shelf did not load: ' . $said->said);

        $items = $said->body['items'] ?? [];

        // Two of the six invented boats are discounted, and they are the two
        // oldest — which is how discounting works everywhere: it is done on the
        // unsold end of the fleet.
        //
        // The shelf sorted by build year, cut to the newest four, and only then
        // filtered for a discount. The sort had just pushed the discounted ones
        // to the end and the cut had just thrown them away. Two pages in
        // production asked for the twelve newest discounted boats and were
        // usually handed nothing at all.
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
    },
];
