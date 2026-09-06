<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;
use Charter\Shown;

/**
 * The one figure the visitor actually reads.
 */
return [
    'the total on the screen is the total that gets booked' => static function (string $which): void {
        $bench = Bench::of($which);
        $week = $bench->week();

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());

        check_same(true, $page->ok, 'the boat\'s page did not load: ' . $page->said);

        // Bed linen, which is priced per person and comes as six, and a tender,
        // which comes as one. The page multiplies the first by six.
        $ticked = array_values(array_filter(
            $page->body['additionalEquipment'],
            static fn (array $one): bool => in_array($one['equipmentId'], [31, 32], true),
        ));

        check_same(2, count($ticked), 'the boat is not offering the two extras this check is about');

        $shown = Shown::total((float) $page->body['price'], $ticked);

        // What the browser sends, exactly as the page sends it: the id, and a
        // quantity of one, whatever the screen was adding up.
        $said = $bench->routes->yachtRequest(
            [
                'yachtId' => 5001,
                'equipmentIds' => [
                    ['equipmentId' => 31, 'quantity' => 1],
                    ['equipmentId' => 32, 'quantity' => 1],
                ],
            ] + $week + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'the booking was refused: ' . $said->said);

        $booked = (float) $said->body['reservation']['price'];

        check_near(
            $shown,
            $booked,
            'the page showed ' . number_format($shown, 2) . ' and the operator booked ' . number_format($booked, 2),
        );
    },

    'an extra the boat does not offer is refused' => static function (string $which): void {
        $bench = Bench::of($which);

        $said = $bench->routes->yachtRequest(
            [
                'yachtId' => 5001,
                'serviceIds' => [['serviceId' => 424242, 'quantity' => 1]],
            ] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(
            false,
            $said->ok,
            'a booking went through carrying an extra that this boat does not have, for the operator to sort out by hand',
        );
    },
];
