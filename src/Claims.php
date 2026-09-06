<?php

declare(strict_types=1);

namespace Charter;

/**
 * Six things this repository says, each one run against both bridges and each
 * one able to come out wrong.
 *
 * Nothing here is remembered from a previous run. Every figure the README
 * quotes is computed here, printed by bin/measure.php, and compared with the
 * README by a check and by CI. Nine projects in this portfolio had figures that
 * had been true once; this is the arrangement that stops it happening again.
 */
final class Claims
{
    /** @return list<Claim> */
    public static function all(): array
    {
        return [
            self::strangers(),
            self::theCustomer(),
            self::guessing(),
            self::theTotal(),
            self::theAccount(),
            self::theCost(),
        ];
    }

    private static function strangers(): Claim
    {
        $tries = 1000;
        $counted = [];

        foreach (Bench::both() as $which) {
            $bench = Bench::of($which);
            $booking = ['yachtId' => 5001] + $bench->week() + Bench::someone();

            for ($try = 1; $try <= $tries; $try++) {
                $bench->routes->yachtRequest($booking, Caller::anonymous());
            }

            $counted[$which] = [
                'bookings' => count($bench->manager->reservations('RESERVATION')),
                'records' => count($bench->manager->reservations()),
                'calls' => $bench->manager->howManyCalls(),
            ];
        }

        return new Claim(
            'A stranger cannot book a boat',
            $counted['repaired']['records'] === 0,
            [
                $tries . ' POSTs at the booking route from an address with no session, no nonce and no',
                'form behind it. Nothing else: the same body, a thousand times.',
                '',
                self::table(
                    ['', 'as it was', 'the bridge'],
                    [
                        ['confirmed reservations', (string) $counted['as-it-was']['bookings'], (string) $counted['repaired']['bookings']],
                        ['records of any kind', (string) $counted['as-it-was']['records'], (string) $counted['repaired']['records']],
                        ['calls to the operator', (string) $counted['as-it-was']['calls'], (string) $counted['repaired']['calls']],
                    ],
                ),
                '',
                'Every one of those is a week the operator has to take off the market and then',
                'cancel by hand. The route was registered with permission_callback => \'__return_true\',',
                'like the nine that only read a catalogue.',
            ],
        );
    }

    private static function theCustomer(): Claim
    {
        $sent = [];

        foreach (Bench::both() as $which) {
            $bench = Bench::of($which);

            $said = $bench->routes->yachtRequest(
                ['yachtId' => 5001, 'name' => '', 'surname' => '', 'email' => '', 'phone' => '', 'address' => '', 'zip' => '', 'city' => ''] + $bench->week(),
                Caller::fromOurPages(),
            );

            $client = [];

            foreach ($bench->manager->calls() as $call) {
                if ($call['path'] === '/booking/v6/createInfo/') {
                    $client = (array) ($call['body']['client'] ?? []);

                    break;
                }
            }

            $sent[$which] = [
                'booked' => $said->ok,
                'sent' => $client !== [],
                'said' => $said->ok ? 'booked' : $said->said,
                'empty' => count(array_filter(
                    ['name', 'surname', 'email', 'phone', 'address', 'zip', 'city'],
                    static fn (string $field): bool => ($client[$field] ?? '') === '',
                )),
                'fields' => $client === [] ? 'nothing was sent' : (string) json_encode(array_intersect_key($client, array_flip(['name', 'surname', 'email', 'zip']))),
            ];
        }

        return new Claim(
            'The customer the operator receives is the one who filled the form',
            !$sent['repaired']['booked'],
            [
                'A booking posted with every field of the form left empty.',
                '',
                self::table(
                    ['', 'as it was', 'the bridge'],
                    [
                        ['what happened', $sent['as-it-was']['booked'] ? 'booked' : 'refused', $sent['repaired']['booked'] ? 'booked' : 'refused'],
                        [
                            'customer fields sent empty',
                            $sent['as-it-was']['sent'] ? $sent['as-it-was']['empty'] . ' of 7' : 'nothing was sent',
                            $sent['repaired']['sent'] ? $sent['repaired']['empty'] . ' of 7' : 'nothing was sent',
                        ],
                    ],
                ),
                '',
                'as it was, the operator received: ' . $sent['as-it-was']['fields'],
                'the bridge answered:             ' . $sent['repaired']['said'],
                '',
                'The fallbacks for this were written — Promo, Code, a zip of 00000, a phone of ten',
                'zeroes — fourteen lines of them, into a variable that was never read again. Thirty',
                'lines further down the request body built a second customer out of the raw values,',
                'and that is the one that went.',
            ],
        );
    }

    private static function guessing(): Claim
    {
        $tries = 200;
        $counted = [];

        foreach (Bench::both() as $which) {
            $bench = Bench::of($which);
            $week = $bench->week();

            for ($try = 1; $try <= $tries; $try++) {
                $bench->routes->yachtPromo(['yachtId' => 5001, 'promoCode' => 'GUESS-' . $try] + $week, Caller::fromOurPages());
            }

            $counted[$which] = [
                'calls' => $bench->manager->howManyCalls('/booking/v6/createInfo/'),
                'records' => count($bench->manager->reservations()),
            ];
        }

        return new Claim(
            'Guessing promo codes does not fill the operator\'s system',
            $counted['repaired']['records'] <= 2,
            [
                $tries . ' codes tried from one address, none of them real.',
                '',
                self::table(
                    ['', 'as it was', 'the bridge'],
                    [
                        ['calls that open a reservation', (string) $counted['as-it-was']['calls'], (string) $counted['repaired']['calls']],
                        ['records left behind', (string) $counted['as-it-was']['records'], (string) $counted['repaired']['records']],
                    ],
                ),
                '',
                'Two calls per try, because the way to find out whether a code is any good was to',
                'ask for a quote without it, ask again with it, and compare the prices. Both of',
                'those are records in the operator\'s system. A five-hundred-word dictionary left a',
                'thousand of them, and told whoever ran it which codes exist and what each is worth.',
            ],
        );
    }

    private static function theTotal(): Claim
    {
        $counted = [];

        foreach (Bench::both() as $which) {
            $bench = Bench::of($which);
            $week = $bench->week();

            $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());

            $ticked = array_values(array_filter(
                $page->body['additionalEquipment'],
                static fn (array $one): bool => in_array($one['equipmentId'], [31, 32], true),
            ));

            $shown = Shown::total((float) $page->body['price'], $ticked);

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

            $booked = (float) ($said->body['reservation']['price'] ?? 0);

            $counted[$which] = ['shown' => $shown, 'booked' => $booked, 'gap' => $shown - $booked];
        }

        return new Claim(
            'The total on the screen is the total that gets booked',
            abs($counted['repaired']['gap']) < 0.005,
            [
                'One week on a boat that lets for 6400, plus bed linen at 35 a head for six, plus a',
                'tender at 250. The page adds those up in the browser; the operator adds them up',
                'again when the booking is made.',
                '',
                self::table(
                    ['', 'as it was', 'the bridge'],
                    [
                        ['shown on the page', number_format($counted['as-it-was']['shown'], 2), number_format($counted['repaired']['shown'], 2)],
                        ['booked by the operator', number_format($counted['as-it-was']['booked'], 2), number_format($counted['repaired']['booked'], 2)],
                        ['apart', number_format($counted['as-it-was']['gap'], 2), number_format($counted['repaired']['gap'], 2)],
                    ],
                ),
                '',
                'The page multiplies by the amount on the extra. The request sent quantity: 1, and',
                'the bridge threw even that away and forwarded a bare number, which the operator',
                'reads as one of them. Neither side had anything that could notice the difference.',
            ],
        );
    }

    private static function theAccount(): Claim
    {
        $counted = [];

        foreach (Bench::both() as $which) {
            $rotated = new InventedManager('rotated-account@invented', 'rotated-password');
            $bench = Bench::of($which, $rotated);

            putenv(Secrets::USERNAME . '=rotated-account@invented');
            putenv(Secrets::PASSWORD . '=rotated-password');

            $week = $bench->week();

            $routes = [
                static fn (): bool => $bench->routes->yachtsCarousel($week, Caller::anonymous())->ok,
                static fn (): bool => $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous())->ok,
                static fn (): bool => $bench->routes->freeYachtsSearch($week, Caller::anonymous())->ok,
                static fn (): bool => $bench->routes->countries([], Caller::anonymous())->ok,
                static fn (): bool => $bench->routes->locations([], Caller::anonymous())->ok,
                static fn (): bool => $bench->routes->yachtCategories([], Caller::anonymous())->ok,
                static fn (): bool => $bench->routes->yachtsMostSearched($week, Caller::anonymous())->ok,
                static fn (): bool => $bench->routes->yachtPromo(['yachtId' => 5001, 'promoCode' => Fleet::PROMO] + $week, Caller::fromOurPages())->ok,
                static fn (): bool => $bench->routes->yachtRequest(['yachtId' => 5001] + $week + Bench::someone(), Caller::fromOurPages())->ok,
            ];

            $working = 0;

            foreach ($routes as $call) {
                if ($call()) {
                    $working++;
                }
            }

            putenv(Secrets::USERNAME . '=' . InventedManager::USERNAME);
            putenv(Secrets::PASSWORD . '=' . InventedManager::PASSWORD);

            $counted[$which] = ['working' => $working, 'of' => count($routes)];
        }

        $wasFile = (string) file_get_contents(__DIR__ . '/TheWayItWas.php');
        $copies = preg_match_all('/\$MANAGER_USER\s*=\s*\'/', $wasFile);

        return new Claim(
            'One place holds the account, and changing it changes everything',
            $counted['repaired']['working'] === $counted['repaired']['of'],
            [
                'The operator issues a new password. It is set in one place and nothing else is',
                'touched. Then all nine routes that talk to the operator are called.',
                '',
                self::table(
                    ['', 'as it was', 'the bridge'],
                    [
                        ['routes still working, of ' . $counted['repaired']['of'], (string) $counted['as-it-was']['working'], (string) $counted['repaired']['working']],
                        ['copies of the account in the code', (string) $copies, '0'],
                    ],
                ),
                '',
                'Nine copies, not the eight everybody counts, and the difference is the whole point:',
                'a rotation is nine coordinated edits, and the one that gets missed leaves part of',
                'the site failing intermittently for a reason nobody can reproduce.',
            ],
        );
    }

    private static function theCost(): Claim
    {
        $counted = [];

        foreach (Bench::both() as $which) {
            $bench = Bench::of($which);
            $week = $bench->week();

            $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
            $cold = $bench->manager->howManyCalls();

            $bench->manager->forgetCalls();

            for ($view = 1; $view <= 49; $view++) {
                $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
            }

            $counted[$which] = [
                'cold' => $cold,
                'fifty' => $cold + $bench->manager->howManyCalls(),
                'groups' => count($page->body['equipmentGrouped']),
                'kind' => $page->body['yacht']['yachtCategoryName'] ?? 'nothing',
            ];
        }

        return new Claim(
            'A boat\'s page costs one call once the catalogue has been read',
            $counted['repaired']['fifty'] - $counted['repaired']['cold'] === 49,
            [
                'The same boat, opened fifty times, on a cold cache.',
                '',
                self::table(
                    ['', 'as it was', 'the bridge'],
                    [
                        ['calls for the first view', (string) $counted['as-it-was']['cold'], (string) $counted['repaired']['cold']],
                        ['calls for fifty views', (string) $counted['as-it-was']['fifty'], (string) $counted['repaired']['fifty']],
                        ['groups the fittings fall into', (string) $counted['as-it-was']['groups'], (string) $counted['repaired']['groups']],
                        ['the boat\'s kind', (string) $counted['as-it-was']['kind'], (string) $counted['repaired']['kind']],
                    ],
                ),
                '',
                'The last two rows are one mistake with two faces. The equipment categories were',
                'read out of the wrong key of the answer, so the map came back empty, every fitting',
                'fell into "Other", and — because an empty map is indistinguishable from a cache',
                'that was never filled — it was fetched again on every single view, for ever.',
            ],
        );
    }

    /**
     * @param list<string> $headings
     * @param list<list<string>> $rows
     */
    private static function table(array $headings, array $rows): string
    {
        $widths = [];

        foreach ([$headings, ...$rows] as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
            }
        }

        $lines = [];

        foreach ([$headings, ...$rows] as $number => $row) {
            $line = '';

            foreach ($row as $column => $cell) {
                $line .= $column === 0
                    ? str_pad($cell, $widths[0] + 2)
                    : str_pad($cell, $widths[$column] + 4, ' ', STR_PAD_LEFT);
            }

            $lines[] = rtrim($line);

            if ($number === 0) {
                $lines[] = str_repeat('-', array_sum($widths) + 2 + 4 * (count($widths) - 1));
            }
        }

        return implode("\n", $lines);
    }
}
