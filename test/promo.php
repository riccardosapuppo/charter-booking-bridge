<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;
use Charter\Fleet;

/**
 * Asking whether a promo code is any good is not a question. The only way to
 * price a code is to ask for a quote carrying it, and a quote is a real record
 * in the operator's system that somebody has to live with.
 *
 * So the route counts per address, and the half of the comparison that does not
 * depend on the code is asked once and kept.
 */
return [
    'guessing promo codes does not fill the operator\'s system' => static function (): void {
        $bench = Bench::of();
        $week = $bench->week();

        $tries = 200;

        for ($try = 1; $try <= $tries; $try++) {
            $bench->routes->yachtPromo(
                ['yachtId' => 5001, 'promoCode' => 'GUESS-' . $try] + $week,
                Caller::fromOurPages(),
            );
        }

        $calls = $bench->manager->howManyCalls('/booking/v6/createInfo/');
        $left = count($bench->manager->reservations());

        check_true(
            $calls <= 10,
            $tries . ' guesses from one address cost the operator ' . $calls . ' calls to the endpoint that opens a reservation',
        );

        check_true(
            $left <= 2,
            $tries . ' guesses left ' . $left . ' records in the operator\'s system for somebody to clean up',
        );

        // The price without a code is the same for everybody asking about this
        // boat and this week, so it is asked once. Two of anything here would
        // mean a try costs two calls again.
        check_same(
            1,
            $left,
            'the guesses left ' . $left . ' records, and the price without a code is one question asked once',
        );
    },

    'a real promo code still works' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtPromo(
            ['yachtId' => 5001, 'promoCode' => Fleet::PROMO] + $bench->week(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'a valid promo code was refused: ' . $said->said);

        // Ten per cent off a boat that lets for 6400.
        check_near(5760.0, (float) $said->body['price'], 'the discounted price is not what the operator quoted');
    },

    'a code that is not a code is refused, and says so' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtPromo(
            ['yachtId' => 5001, 'promoCode' => 'NOT-A-CODE'] + $bench->week(),
            Caller::fromOurPages(),
        );

        check_same(false, $said->ok, 'a code nobody issued was accepted');
        check_same('invalid_promo', $said->code, 'it was refused for another reason: ' . $said->said);

        // What the visitor is told, and what the log is told, are not the same
        // string: the operator's own error text is the log's.
        check_not($said->said, $said->logged, 'the visitor is shown the operator\'s own words about its own system');
    },
];
