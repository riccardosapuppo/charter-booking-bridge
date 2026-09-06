<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * The anagraphic that arrives from the form is the anagraphic that arrives at
 * the operator.
 */
return [
    'the customer the operator receives is the one who filled the form' => static function (): void {
        $bench = Bench::of();
        $someone = Bench::someone();

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5001] + $bench->week() + $someone,
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

        // Every field, not a sample of them. The body that goes to the operator
        // is built from the checked customer and from nothing else, and this is
        // the assertion that says so.
        $fields = ['name', 'surname', 'email', 'phone', 'address', 'zip', 'city'];

        foreach ($fields as $field) {
            check_same(
                $someone[$field],
                $sent[$field] ?? null,
                'the form said ' . check_show($someone[$field]) . ' for ' . $field
                    . ' and the operator was sent ' . check_show($sent[$field] ?? null),
            );
        }

        check_same(
            (string) $someone['countryId'],
            $sent['countryId'] ?? null,
            'the country that was chosen is not the country that was sent',
        );

        // And the check has been looking at something: seven fields, all
        // filled in, none of them empty at either end.
        check_same(
            0,
            count(array_filter($fields, static fn (string $one): bool => ($sent[$one] ?? '') === '')),
            'the customer that arrived has empty fields in it, so the comparison above was between blanks',
        );
    },

    'a booking with no customer does not happen' => static function (): void {
        $bench = Bench::of();

        // A form posted with nothing in it. The browser would not allow this;
        // the endpoint is open to everything that is not a browser.
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
        check_same('not_a_customer', $said->code, 'the empty booking was refused for another reason: ' . $said->said);
        check_same(0, count($bench->manager->reservations()), 'a nameless booking reached the operator');
        check_same(
            0,
            $bench->manager->howManyCalls('/booking/v6/createInfo/'),
            'a nameless booking opened a reservation before anybody looked at it',
        );

        // A half-filled one too: a surname and an address that is not one.
        $bench = Bench::of();

        $half = $bench->routes->yachtRequest(
            ['yachtId' => 5001] + $bench->week() + Bench::someone() + [],
            Caller::fromOurPages(),
        );

        check_same(true, $half->ok, 'the complete booking this compares against was refused: ' . $half->said);

        foreach (['name' => '', 'surname' => '', 'email' => 'not-an-address'] as $field => $bad) {
            $bench = Bench::of();

            $said = $bench->routes->yachtRequest(
                ['yachtId' => 5001] + $bench->week() + [$field => $bad] + Bench::someone(),
                Caller::fromOurPages(),
            );

            check_same(false, $said->ok, 'a booking with ' . $field . ' = ' . check_show($bad) . ' was accepted');
            check_same(0, count($bench->manager->reservations()), 'it reached the operator anyway');
        }
    },

    'what the customer wrote is kept' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5003] + $bench->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'the booking was refused: ' . $said->said);
        check_same(true, $said->body['messageKept'] ?? false, 'the answer does not say the message was kept');

        $kept = $bench->notes->all();

        check_same(1, count($kept), 'the message on the booking form went nowhere at all');
        check_same(
            'We are bringing a two-year-old, we will need a cot.',
            $kept[0]['message'] ?? '',
            'the message that was kept is not the message that was written',
        );
        check_same(
            'giulia.verdi@example.invalid',
            $kept[0]['email'] ?? '',
            'the message was kept without the person who wrote it',
        );
    },

    'the country on a booking is one the operator has' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5004] + $bench->week() + ['countryId' => 999999] + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(false, $said->ok, 'a booking was made for a country the operator does not have');
        check_same(0, count($bench->manager->reservations()), 'it reached the operator anyway');

        // The list it is checked against is the list behind the dropdown, so a
        // country that is on the dropdown is accepted.
        $bench = Bench::of();
        $countries = $bench->routes->countries([], Caller::anonymous());
        $first = $countries->body['items'][0]['id'] ?? 0;

        check_true($first > 0, 'the country list came back empty, so nothing was checked against it');

        $said = $bench->routes->yachtRequest(
            ['yachtId' => 5004] + $bench->week() + ['countryId' => $first] + Bench::someone(),
            Caller::fromOurPages(),
        );

        check_same(true, $said->ok, 'a country off the site\'s own dropdown was refused: ' . $said->said);
    },
];
