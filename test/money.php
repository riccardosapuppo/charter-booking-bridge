<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;
use Charter\Shown;

/**
 * The operator decides the total. The page may show it, and nothing else.
 */
return [
    'the total on the screen is the total that gets booked' => static function (): void {
        $bench = Bench::of();
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

        // The comparison has to be between two different numbers to be worth
        // anything: the extras must actually move the total.
        check_true(
            $shown > (float) $page->body['price'] + 400.0,
            'the two extras add ' . number_format($shown - (float) $page->body['price'], 2)
                . ' to the week, which is not enough for this check to be about anything',
        );

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

    'the quantity on an extra comes from the boat, not from the request' => static function (): void {
        $bench = Bench::of();
        $week = $bench->week();

        // Bed linen: the boat offers six sets, and the request asks for
        // ninety-nine of them.
        $said = $bench->routes->yachtRequest(
            [
                'yachtId' => 5001,
                'equipmentIds' => [['equipmentId' => 31, 'quantity' => 99]],
            ] + $week + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'the booking was refused: ' . $said->said);

        $lines = [];

        foreach ($bench->manager->calls() as $call) {
            if ($call['path'] === '/booking/v6/createInfo/') {
                $lines = (array) ($call['body']['equipment'] ?? []);

                break;
            }
        }

        check_same(1, count($lines), 'the operator was sent ' . count($lines) . ' equipment lines instead of one');

        check_same(
            6,
            (int) ($lines[0]['quantity'] ?? 0),
            'the request asked for 99 sets of linen and the operator was sent '
                . check_show($lines[0]['quantity'] ?? null) . ', where the boat offers 6',
        );

        // And the price follows from that, which is the reason it matters: a
        // week at 6400 plus six sets at 35.
        check_near(
            6400.0 + 6 * 35.0,
            (float) $said->body['reservation']['price'],
            'the operator charged something other than the week plus the six sets the boat offers',
        );
    },

    'an extra the boat does not offer is refused' => static function (): void {
        $bench = Bench::of();

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

        check_same('extra_not_offered', $said->code, 'it was refused for another reason: ' . $said->said);

        check_same(
            0,
            count($bench->manager->reservations()),
            'the booking was refused and a record was left in the operator\'s system anyway',
        );

        // An extra the boat does offer goes through, so this is a check about
        // the boat's list and not a route that refuses every extra.
        $bench = Bench::of();

        $good = $bench->routes->yachtRequest(
            [
                'yachtId' => 5001,
                'serviceIds' => [['serviceId' => 77, 'quantity' => 1]],
            ] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $good->ok, 'a skipper, which this boat does offer, was refused: ' . $good->said);
    },

    'no route reads a price out of a request' => static function (): void {
        // The most defensible thing about the arrangement this replaces: the
        // form sends identifiers, dates and a customer. The operator decides
        // what a week costs and the site commits whatever it says. This is a
        // check rather than a sentence because it is one line away from not
        // being true any more.
        $reads = 0;
        $found = [];

        foreach (glob(check_root() . '/src/*.php') ?: [] as $file) {
            // Shown is the browser's arithmetic, transcribed, and it is the
            // other side of the comparison rather than part of the bridge.
            if (basename($file) === 'Shown.php') {
                continue;
            }

            $text = (string) file_get_contents($file);
            $reads += preg_match_all('/\$input\s*\[/', $text);

            if (preg_match_all('/\$input\s*\[\s*\'(price|total|amount|clientPrice|priceListPrice|discount\w*)\'/', $text, $said) > 0) {
                foreach ($said[0] as $one) {
                    $found[] = basename($file) . ': ' . $one;
                }
            }
        }

        check_true($reads >= 10, 'only ' . $reads . ' reads of a request were found, so this check looked almost nowhere');

        check_same([], $found, 'a route reads money out of the request: ' . implode(', ', $found));
    },
];
