<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * The customer that was built with care, and the customer that was sent.
 */
return [
    'the customer the operator receives is the one who filled the form' => static function (string $which): void {
        $bench = Bench::of($which);

        // A form posted with nothing in it. The browser would not allow this;
        // the endpoint was open to everything that is not a browser.
        $empty = [
            'yachtId' => 5001,
            'name' => '',
            'surname' => '',
            'email' => '',
            'phone' => '',
            'address' => '',
            'zip' => '',
            'city' => '',
        ] + $bench->week();

        $said = $bench->routes->yachtRequest($empty, Caller::fromOurPages());

        check_same(false, $said->ok, 'a booking with no customer in it was accepted');
        check_same(0, count($bench->manager->reservations()), 'a nameless booking reached the operator');

        // And when there is a customer, every field of them arrives.
        $bench = Bench::of($which);
        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5001] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'a complete booking was refused: ' . $said->said);

        $sent = null;

        foreach ($bench->manager->calls() as $call) {
            if ($call['path'] === '/booking/v6/createInfo/') {
                $sent = $call['body']['client'] ?? null;

                break;
            }
        }

        check_true(is_array($sent), 'nothing was sent to the operator to look at');

        foreach (['name' => 'Giulia', 'surname' => 'Verdi', 'email' => 'giulia.verdi@example.invalid', 'city' => 'Porto Finto', 'zip' => '00100'] as $field => $ought) {
            check_same($ought, $sent[$field] ?? null, 'the customer reached the operator with a wrong ' . $field);
        }
    },

    'what the customer wrote is not thrown away' => static function (string $which): void {
        $bench = Bench::of($which);

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5003] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'the booking was refused: ' . $said->said);

        $kept = $bench->notes->all();

        check_same(1, count($kept), 'the message on the booking form went nowhere at all');
        check_same(
            'We are bringing a two-year-old, we will need a cot.',
            $kept[0]['message'] ?? '',
            'the message that was kept is not the message that was written',
        );
    },

    'a country nobody has heard of is not accepted' => static function (string $which): void {
        $bench = Bench::of($which);

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5004] + $bench->week() + ['countryId' => 999999] + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(false, $said->ok, 'a booking was made for a country the operator does not have');
    },
];
