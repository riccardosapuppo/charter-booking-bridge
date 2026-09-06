<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * The route that commits a week of the operator's boat.
 *
 * Four guarantees: it wants a nonce, it counts per address, it takes dates that
 * are dates, and it does not sell the same week twice.
 */
return [
    'a booking has to have been composed on the site\'s own form' => static function (): void {
        $bench = Bench::of();

        $booking = ['yachtId' => 5001] + $bench->week() + Bench::someone();

        $said = $bench->routes->yachtRequest($booking, Caller::anonymous());

        check_same(
            false,
            $said->ok,
            'a POST from nowhere, with no nonce and no session, was accepted by the route that books',
        );

        check_same(
            'not_from_our_pages',
            $said->code,
            'the booking was refused, but not for the reason this check is about',
        );

        check_same(
            0,
            count($bench->manager->reservations()),
            'a stranger left something in the operator\'s system',
        );

        // Nothing was even asked. The refusal is the first thing the route
        // does, so an anonymous flood costs the operator no calls at all.
        check_same(
            0,
            $bench->manager->howManyCalls(),
            'a refused booking still rang the operator ' . $bench->manager->howManyCalls() . ' times',
        );
    },

    'one address gets five bookings an hour' => static function (): void {
        $bench = Bench::of();
        $accepted = 0;
        $lastRefusal = null;

        // Six different boats, so that it is the counting being tested and not
        // the fleet running out of free weeks.
        foreach ([5001, 5002, 5003, 5004, 5005, 5006] as $boat) {
            $said = $bench->routes->yachtRequest(
                ['yachtId' => $boat] + $bench->week() + Bench::someone(),
                Caller::fromOurPages(),
            );

            if ($said->ok) {
                $accepted++;
            } else {
                $lastRefusal = $said;
            }
        }

        check_same(
            5,
            $accepted,
            'one address booked ' . $accepted . ' boats in an hour, and five is the limit',
        );

        check_true($lastRefusal !== null, 'nothing was refused, so nothing was counted');
        check_same(429, $lastRefusal?->status, 'the sixth booking was refused for another reason: ' . $lastRefusal?->said);

        // A limit is a limit within a window, and a window that never ends is a
        // ban. An hour later the same address can book again.
        $bench->clock->advance(3601);

        $later = $bench->routes->yachtRequest(
            ['yachtId' => 5006] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $later->ok, 'an hour later the same address is still shut out: ' . $later->said);

        // And the count is per address: somebody else is not affected by it.
        $bench = Bench::of();

        foreach ([5001, 5002, 5003, 5004, 5005] as $boat) {
            $bench->routes->yachtRequest(
                ['yachtId' => $boat] + $bench->week() + Bench::someone(),
                Caller::fromOurPages('203.0.113.7'),
            );
        }

        $somebodyElse = $bench->routes->yachtRequest(
            ['yachtId' => 5006] + $bench->week() + Bench::someone(),
            Caller::fromOurPages('203.0.113.201'),
        );

        check_same(
            true,
            $somebodyElse->ok,
            'one address using up its five shut a different address out: ' . $somebodyElse->said,
        );
    },

    'a booking made on the site\'s own form goes through' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5001] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'a proper booking was refused: ' . $said->said);
        check_same('RESERVATION', $said->body['reservation']['reservationStatus'], 'the booking did not become a reservation');
        check_same(1, count($bench->manager->reservations('RESERVATION')), 'the operator has no reservation for it');
    },

    'the dates that reach the operator are days on the calendar, in order, ahead of us' => static function (): void {
        $bench = Bench::of();
        $week = $bench->week();

        $nonsense = [
            'a word' => ['periodFrom' => 'pippo', 'periodTo' => $week['periodTo']],
            'a thirteenth month' => ['periodFrom' => $week['periodFrom'], 'periodTo' => '2026-13-45'],
            // The one that is easy to let through: it has the shape of a date,
            // and a converter that never consults a calendar makes it the 3rd
            // of March without saying so.
            'the 31st of February' => ['periodFrom' => '31.02.2026', 'periodTo' => '07.03.2026'],
            'a week that ends before it starts' => ['periodFrom' => '18.07.2026', 'periodTo' => '11.07.2026'],
            'a week that has been' => ['periodFrom' => '11.07.2020', 'periodTo' => '18.07.2020'],
        ];

        foreach ($nonsense as $what => $dates) {
            $said = $bench->routes->yachtRequest(
                ['yachtId' => 5001] + $dates + Bench::someone(),
                Caller::fromOurPages(),
            );

            check_same(false, $said->ok, 'a booking for ' . $what . ' was accepted');
            check_same('bad_period', $said->code, 'a booking for ' . $what . ' was refused for another reason: ' . $said->said);
        }

        check_same(
            0,
            count($bench->manager->reservations()),
            'dates that are not dates left records in the operator\'s system',
        );

        check_same(
            0,
            $bench->manager->howManyCalls('/booking/v6/createInfo/'),
            'dates that are not dates reached the endpoint that opens a reservation',
        );

        // And a week that is a week is not refused, which is the half that
        // stops this check from being satisfied by a route that refuses
        // everything.
        $bench = Bench::of();

        $real = $bench->routes->yachtRequest(
            ['yachtId' => 5001] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $real->ok, 'a real week was refused too, so the route refuses everything: ' . $real->said);
    },

    'a week that has gone is not sold twice' => static function (): void {
        $bench = Bench::of();
        $week = $bench->week();

        $first = $bench->routes->yachtRequest(
            ['yachtId' => 5002] + $week + Bench::someone(),
            Caller::fromOurPages('203.0.113.7'),
        );

        check_same(true, $first->ok, 'the first booking was refused: ' . $first->said);

        // Somebody else, from another address, a moment later, for the same
        // boat and the same week. Availability is not a flag to decorate the
        // answer with: it stops the flow.
        $second = $bench->routes->yachtRequest(
            ['yachtId' => 5002] + $week + Bench::someone(),
            Caller::fromOurPages('203.0.113.9'),
        );

        check_same(false, $second->ok, 'the same week on the same boat was booked twice');
        check_same('not_free', $second->code, 'the second booking was refused for another reason: ' . $second->said);

        check_same(
            1,
            count($bench->manager->reservations('RESERVATION')),
            'the operator has two reservations for one boat and one week',
        );
    },
];
