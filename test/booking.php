<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * The route that commits a week of somebody else's boat.
 */
return [
    'the route that books has a door on it' => static function (string $which): void {
        $bench = Bench::of($which);

        $booking = ['yachtId' => 5001] + $bench->week() + Bench::someone();

        $said = $bench->routes->yachtRequest($booking, Caller::anonymous());

        check_same(
            false,
            $said->ok,
            'a POST from nowhere, with no nonce and no session, was accepted by the route that books',
        );

        check_same(
            0,
            count($bench->manager->reservations()),
            'a stranger left something in the operator\'s system',
        );

        // And the door is not only about who: it is also about how often. Five
        // bookings an hour from one address is generous for a family choosing a
        // holiday and useless to a script.
        $accepted = 0;

        // Six different boats, so that it is the counting being tested and not
        // the fleet running out of free weeks.
        foreach ([5001, 5002, 5003, 5004, 5005, 5006] as $boat) {
            if ($bench->routes->yachtRequest(['yachtId' => $boat] + $bench->week() + Bench::someone(), Caller::fromOurPages())->ok) {
                $accepted++;
            }
        }

        check_same(
            5,
            $accepted,
            'one address booked ' . $accepted . ' boats in an hour and nothing counted',
        );
    },

    'a booking made on the site\'s own form goes through' => static function (string $which): void {
        $bench = Bench::of($which);

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5001] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'a proper booking was refused: ' . $said->said);
        check_same('RESERVATION', $said->body['reservation']['reservationStatus'], 'the booking did not become a reservation');
        check_same(1, count($bench->manager->reservations('RESERVATION')), 'the operator has no reservation for it');
    },

    'dates that are not dates do not reach the operator' => static function (string $which): void {
        $bench = Bench::of($which);
        $week = $bench->week();

        $nonsense = [
            'a word' => ['periodFrom' => 'pippo', 'periodTo' => $week['periodTo']],
            'a thirteenth month' => ['periodFrom' => $week['periodFrom'], 'periodTo' => '2026-13-45'],
            'the 31st of February' => ['periodFrom' => '31.02.2026', 'periodTo' => '07.03.2026'],
            'a week that ends before it starts' => ['periodFrom' => '18.07.2026', 'periodTo' => '11.07.2026'],
            'a week in the past' => ['periodFrom' => '11.07.2020', 'periodTo' => '18.07.2020'],
        ];

        foreach ($nonsense as $what => $dates) {
            $said = $bench->routes->yachtRequest(
                ['yachtId' => 5001] + $dates + Bench::someone(),
                Caller::fromOurPages(),
            );

            check_same(false, $said->ok, 'a booking for ' . $what . ' was accepted');
        }

        check_same(
            0,
            count($bench->manager->reservations()),
            'nonsense dates left records in the operator\'s system',
        );
    },

    'a week that has gone is not sold twice' => static function (string $which): void {
        $bench = Bench::of($which);
        $week = $bench->week();

        $first = $bench->routes->yachtRequest(
            ['yachtId' => 5002] + $week + Bench::someone(),
            Caller::fromOurPages('203.0.113.7'),
        );

        check_same(true, $first->ok, 'the first booking was refused: ' . $first->said);

        // Somebody else, from another address, a moment later, for the same
        // boat and the same week. Availability was fetched in the original and
        // used for one thing: to set a flag on the option. Nothing stopped.
        $second = $bench->routes->yachtRequest(
            ['yachtId' => 5002] + $week + Bench::someone(),
            Caller::fromOurPages('203.0.113.9'),
        );

        check_same(false, $second->ok, 'the same week on the same boat was booked twice');

        check_same(
            1,
            count($bench->manager->reservations('RESERVATION')),
            'the operator has two reservations for one boat and one week',
        );
    },
];
