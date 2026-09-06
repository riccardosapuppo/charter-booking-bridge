<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;
use Charter\Fleet;

/**
 * Asking whether a promo code is any good is not a question. Each try writes a
 * real record into the operator's system.
 */
return [
    'guessing promo codes does not fill the operator\'s system' => static function (string $which): void {
        $bench = Bench::of($which);
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
    },

    'a real promo code still works' => static function (string $which): void {
        $bench = Bench::of($which);

        $said = $bench->routes->yachtPromo(
            ['yachtId' => 5001, 'promoCode' => Fleet::PROMO] + $bench->week(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'a valid promo code was refused: ' . $said->said);

        // Ten per cent off a boat that lets for 6400.
        check_near(5760.0, (float) $said->body['price'], 'the discounted price is not what the operator quoted');
    },
];
