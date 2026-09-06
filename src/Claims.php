<?php

declare(strict_types=1);

namespace Charter;

/**
 * Eight things this bridge guarantees, each one measured against an invented
 * operator and each one able to come out wrong.
 *
 * Nothing here is remembered from a previous run. Every figure the README
 * quotes is computed in this file, printed by bin/measure.php, and compared
 * with the README by a check and by CI. A claim whose measurement stops
 * agreeing with it turns `holds` false, and bin/measure.php exits non-zero.
 *
 * The eight are the eight sentences in the README's first table, in this order.
 */
final class Claims
{
    /** @return list<Claim> */
    public static function all(): array
    {
        return [
            self::theDoor(),
            self::theCustomer(),
            self::theDates(),
            self::theTotal(),
            self::theAccount(),
            self::theCost(),
            self::anEmptyList(),
            self::aBlip(),
        ];
    }

    /**
     * The route that commits a week of the operator's boat wants a nonce, and
     * counts per address. So does the route that tries a promo code, because
     * trying one leaves a record behind.
     */
    private static function theDoor(): Claim
    {
        $tries = 1000;

        $strangers = Bench::of();
        $booking = ['yachtId' => 5001] + $strangers->week() + Bench::someone();

        for ($try = 1; $try <= $tries; $try++) {
            $strangers->routes->yachtRequest($booking, Caller::anonymous());
        }

        $left = count($strangers->manager->reservations());
        $rung = $strangers->manager->howManyCalls();

        // The same address, from the site's own form, on six different boats,
        // so that it is the counting being measured and not the fleet running
        // out of free weeks.
        $counting = Bench::of();
        $accepted = 0;
        $refused = '';

        foreach ([5001, 5002, 5003, 5004, 5005, 5006] as $boat) {
            $said = $counting->routes->yachtRequest(
                ['yachtId' => $boat] + $counting->week() + Bench::someone(),
                Caller::fromOurPages(),
            );

            if ($said->ok) {
                $accepted++;
            } else {
                $refused = $said->said;
            }
        }

        $guesses = 200;
        $guessing = Bench::of();
        $week = $guessing->week();

        for ($try = 1; $try <= $guesses; $try++) {
            $guessing->routes->yachtPromo(
                ['yachtId' => 5001, 'promoCode' => 'GUESS-' . $try] + $week,
                Caller::fromOurPages(),
            );
        }

        $guessCalls = $guessing->manager->howManyCalls('/booking/v6/createInfo/');
        $guessLeft = count($guessing->manager->reservations());

        return new Claim(
            'A booking comes from the site\'s own form, and one address gets five an hour',
            $left === 0 && $rung === 0 && $accepted === 5 && $guessLeft <= 2,
            [
                $tries . ' POSTs at the booking route from an address with no session, no nonce and no',
                'form behind it. Nothing else: the same body, a thousand times.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['confirmed reservations', (string) count($strangers->manager->reservations('RESERVATION'))],
                        ['records of any kind', (string) $left],
                        ['calls to the operator', (string) $rung],
                    ],
                ),
                '',
                'Nothing reaches the operator, because the refusal happens before the first call.',
                'Any record that did reach it would be a week somebody has to take off the market',
                'and then cancel by hand.',
                '',
                'Then six bookings from the site\'s own form: one address, six different boats.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['bookings accepted', $accepted . ' of 6'],
                        ['what the sixth was told', $refused],
                    ],
                ),
                '',
                'And ' . $guesses . ' promo codes tried from one address, none of them real. Trying a code',
                'is not a question: the only way to price one is to ask for a quote carrying it,',
                'and a quote is a record in the operator\'s system.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['calls that open a reservation', (string) $guessCalls],
                        ['records left behind', (string) $guessLeft],
                    ],
                ),
            ],
        );
    }

    /**
     * One customer, checked, and the one that is sent.
     */
    private static function theCustomer(): Claim
    {
        $empty = Bench::of();

        $refused = $empty->routes->yachtRequest(
            ['yachtId' => 5001, 'name' => '', 'surname' => '', 'email' => '', 'phone' => '', 'address' => '', 'zip' => '', 'city' => '']
                + $empty->week(),
            Caller::fromOurPages(),
        );

        $sentAnything = false;

        foreach ($empty->manager->calls() as $call) {
            if ($call['path'] === '/booking/v6/createInfo/') {
                $sentAnything = true;
            }
        }

        $full = Bench::of();
        $someone = Bench::someone();

        $booked = $full->routes->yachtRequest(
            ['yachtId' => 5001] + $full->week() + $someone,
            Caller::fromOurPages(),
        );

        $client = [];

        foreach ($full->manager->calls() as $call) {
            if ($call['path'] === '/booking/v6/createInfo/') {
                $client = (array) ($call['body']['client'] ?? []);

                break;
            }
        }

        $fields = ['name', 'surname', 'email', 'phone', 'address', 'zip', 'city'];
        $same = 0;

        foreach ($fields as $field) {
            if (($client[$field] ?? null) === $someone[$field]) {
                $same++;
            }
        }

        return new Claim(
            'The customer the operator receives is the one who filled the form',
            !$refused->ok && !$sentAnything && $booked->ok && $same === count($fields),
            [
                'A booking posted with every field of the form left empty, and then the same',
                'booking with a customer in it.',
                '',
                self::table(
                    ['', 'the empty form', 'a filled form'],
                    [
                        ['what happened', $refused->ok ? 'booked' : 'refused', $booked->ok ? 'booked' : 'refused'],
                        ['reached the operator', $sentAnything ? 'a customer' : 'nothing at all', 'a customer'],
                        ['fields that arrived unchanged', '-', $same . ' of ' . count($fields)],
                    ],
                ),
                '',
                'the empty form was told: ' . $refused->said,
                '',
                'The body that goes to the operator is built from the checked customer and from',
                'nothing else, which is what makes the second row a property of this code rather',
                'than of the browser. The form insists on those fields too, and the endpoint is',
                'open to everything that is not a browser.',
            ],
        );
    }

    /**
     * Dates are days on the calendar, in order, and ahead of us.
     */
    private static function theDates(): Claim
    {
        $bench = Bench::of();
        $week = $bench->week();

        $nonsense = [
            'a word' => ['periodFrom' => 'pippo', 'periodTo' => $week['periodTo']],
            'a thirteenth month' => ['periodFrom' => $week['periodFrom'], 'periodTo' => '2026-13-45'],
            'the 31st of February' => ['periodFrom' => '31.02.2026', 'periodTo' => '07.03.2026'],
            'a week that ends before it starts' => ['periodFrom' => '18.07.2026', 'periodTo' => '11.07.2026'],
            'a week that has been' => ['periodFrom' => '11.07.2020', 'periodTo' => '18.07.2020'],
        ];

        $rows = [];
        $accepted = 0;

        foreach ($nonsense as $what => $dates) {
            $said = $bench->routes->yachtRequest(
                ['yachtId' => 5001] + $dates + Bench::someone(),
                Caller::fromOurPages(),
            );

            if ($said->ok) {
                $accepted++;
            }

            $rows[] = [$what, $said->ok ? 'BOOKED' : $said->said];
        }

        $left = count($bench->manager->reservations());
        $rung = $bench->manager->howManyCalls('/booking/v6/createInfo/');

        $good = Bench::of();

        $real = $good->routes->yachtRequest(
            ['yachtId' => 5001] + $good->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        return new Claim(
            'The dates that reach the operator are days on the calendar, in order, ahead of us',
            $accepted === 0 && $left === 0 && $rung === 0 && $real->ok,
            [
                'Five periods that look like periods, posted at the route that books.',
                '',
                self::table(['', 'the bridge'], $rows),
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['records left in the operator\'s system', (string) $left],
                        ['calls that open a reservation', (string) $rung],
                        ['a real week, same route', $real->ok ? 'booked' : 'refused'],
                    ],
                ),
                '',
                'The 31st of February is the one that is easy to let through: it has the shape of',
                'a date, and a converter that is not asked to consult a calendar will make it the',
                '3rd of March. Nothing downstream of this takes two strings, so nothing downstream',
                'has to wonder whether somebody checked.',
            ],
        );
    }

    /**
     * The operator decides the total, and the quantity is the server's.
     */
    private static function theTotal(): Claim
    {
        $bench = Bench::of();
        $week = $bench->week();

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());

        $ticked = array_values(array_filter(
            $page->body['additionalEquipment'],
            static fn (array $one): bool => in_array($one['equipmentId'], [31, 32], true),
        ));

        $shown = Shown::total((float) $page->body['price'], $ticked);

        // What the browser sends, exactly as the page sends it: the id, and a
        // quantity, whatever the screen was adding up.
        $said = $bench->routes->yachtRequest(
            [
                'yachtId' => 5001,
                'equipmentIds' => [
                    ['equipmentId' => 31, 'quantity' => 99],
                    ['equipmentId' => 32, 'quantity' => 99],
                ],
            ] + $week + Bench::someone(),
            Caller::fromOurPages(),
        );

        $booked = (float) ($said->body['reservation']['price'] ?? 0);
        $gap = $shown - $booked;

        $sent = [];

        foreach ($bench->manager->calls() as $call) {
            if ($call['path'] === '/booking/v6/createInfo/') {
                foreach ((array) ($call['body']['equipment'] ?? []) as $line) {
                    $sent[(int) $line['equipmentId']] = (int) $line['quantity'];
                }

                break;
            }
        }

        $offered = 0;

        foreach ($ticked as $one) {
            if ((int) $one['equipmentId'] === 31) {
                $offered = (int) $one['amount'];
            }
        }

        // And an extra this boat does not offer at all. The quantity being the
        // server's is only half of it: the line has to be one the boat has, for
        // this base and this week, or there is nothing to read a quantity off.
        $strange = Bench::of();

        $turnedAway = $strange->routes->yachtRequest(
            [
                'yachtId' => 5001,
                'serviceIds' => [['serviceId' => 424242, 'quantity' => 1]],
            ] + $strange->week() + Bench::someone(),
            Caller::fromOurPages(),
        );

        $leftBehind = count($strange->manager->reservations());

        return new Claim(
            'The total on the screen is the total that gets booked, and the extras are the boat\'s',
            abs($gap) < 0.005 && ($sent[31] ?? 0) === $offered && $offered > 1
                && !$turnedAway->ok && $leftBehind === 0,
            [
                'One week on a boat that lets for 6400, plus bed linen at 35 a head for six, plus a',
                'tender at 250. The page adds those up in the browser; the operator adds them up',
                'again when the booking is made. No request body anywhere carries a price.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['shown on the page', number_format($shown, 2)],
                        ['booked by the operator', number_format($booked, 2)],
                        ['apart', number_format($gap, 2)],
                    ],
                ),
                '',
                'Those two are computed in two places, in two languages, and what holds them',
                'together is that the quantity on each line is read off the boat\'s own offer and',
                'never off the request. So the request in this measurement asked for ninety-nine.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['sets of linen the browser asked for', '99'],
                        ['sets of linen the boat offers', (string) $offered],
                        ['sets of linen the operator was sent', (string) ($sent[31] ?? 0)],
                    ],
                ),
                '',
                'And a booking carrying a service this boat does not offer at all.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['what happened', $turnedAway->ok ? 'booked' : 'refused'],
                        ['records left in the operator\'s system', (string) $leftBehind],
                    ],
                ),
                '',
                'the visitor is told: ' . $turnedAway->said,
                '',
                'A line the boat does not have is one the operator has to sort out by hand, and',
                'it is also a line with no offer behind it to read a quantity off. The two rows',
                'above and the three before them are the same guarantee seen twice.',
            ],
        );
    }

    /**
     * The account comes from the environment, and from nowhere else.
     */
    private static function theAccount(): Claim
    {
        $rotated = new InventedManager('rotated-account@invented', 'rotated-password');
        $bench = Bench::of($rotated);

        putenv(Secrets::USERNAME . '=rotated-account@invented');
        putenv(Secrets::PASSWORD . '=rotated-password');

        $working = self::howManyRoutesReachTheOperator($bench);
        $of = 9;

        // And with nothing in the environment at all. Anything that still
        // reached the operator would be reading the account from somewhere this
        // repository says does not exist.
        $bare = Bench::of();

        putenv(Secrets::USERNAME);
        putenv(Secrets::PASSWORD);

        $withoutAnAccount = self::howManyRoutesReachTheOperator($bare);

        putenv(Secrets::USERNAME . '=' . InventedManager::USERNAME);
        putenv(Secrets::PASSWORD . '=' . InventedManager::PASSWORD);

        $reads = 0;

        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
            if (basename($file) === 'InventedManager.php') {
                continue;
            }

            $reads += preg_match_all('/getenv\s*\(/', (string) file_get_contents($file));
        }

        return new Claim(
            'One place holds the account, and it is the environment',
            $working === $of && $withoutAnAccount === 0 && $reads === 1,
            [
                'The operator issues a new password. It is set in the two environment variables',
                'the bridge reads, nothing else is touched, and all nine routes that talk to the',
                'operator are called. Then the same nine again, with the environment emptied.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['routes reaching the operator after a rotation, of ' . $of, (string) $working],
                        ['routes reaching the operator with no account set', (string) $withoutAnAccount],
                        ['places in src/ that read the account', (string) $reads],
                    ],
                ),
                '',
                'The second row is the half worth measuring. A copy of the account left anywhere',
                'in the code would keep some of those nine working with nothing in the',
                'environment, and that is exactly the state in which a rotation looks done and is',
                'not: half the site working, the other half failing for a reason nobody can',
                'reproduce.',
            ],
        );
    }

    /**
     * A boat's page, and what a second view of it costs.
     */
    private static function theCost(): Claim
    {
        $bench = Bench::of();
        $week = $bench->week();

        $page = $bench->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        $cold = $bench->manager->howManyCalls();

        $bench->manager->forgetCalls();

        for ($view = 1; $view <= 49; $view++) {
            $bench->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        }

        $fifty = $cold + $bench->manager->howManyCalls();
        $groups = count($page->body['equipmentGrouped']);
        $kind = (string) ($page->body['yacht']['yachtCategoryName'] ?? 'nothing');

        return new Claim(
            'A boat\'s page costs one call once the catalogue has been read',
            $fifty - $cold === 49 && $groups === 3 && $kind !== 'nothing',
            [
                'The same boat, opened fifty times, on a cold cache.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['calls for the first view', (string) $cold],
                        ['calls for fifty views', (string) $fifty],
                        ['calls for each view after the first', (string) (int) (($fifty - $cold) / 49)],
                        ['groups the fittings fall into', (string) $groups],
                        ['the boat\'s kind', $kind],
                    ],
                ),
                '',
                'The one call a later view still costs is the week\'s price, which is the only',
                'thing that can have changed. The last two rows are here because they are read',
                'out of the same catalogue: a fitting\'s category and a boat\'s category are two',
                'different lists, and a page that has lost one of them shows every fitting in a',
                'single heap called "Other" and no kind at all.',
            ],
        );
    }

    /**
     * "There, and empty" is not "never asked".
     */
    private static function anEmptyList(): Claim
    {
        $bench = Bench::of();
        $bench->manager->emptyLists[] = '/catalogue/v6/equipmentCategories';
        $week = $bench->week();

        $page = null;

        for ($view = 1; $view <= 10; $view++) {
            $page = $bench->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        }

        $asked = $bench->manager->howManyCalls('/catalogue/v6/equipmentCategories');

        $full = Bench::of();

        for ($view = 1; $view <= 10; $view++) {
            $full->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        }

        $askedWhenFull = $full->manager->howManyCalls('/catalogue/v6/equipmentCategories');

        return new Claim(
            'A list the operator answers with nothing is remembered as nothing',
            $asked === 1 && $askedWhenFull === 1 && $page !== null && $page->ok,
            [
                'Ten views of one boat\'s page, with the operator answering the equipment',
                'categories endpoint with a list that has nothing in it. An ordinary state: an',
                'operator who has not filled that shelf in.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['calls for that list, when it comes back empty', (string) $asked],
                        ['calls for that list, when it comes back full', (string) $askedWhenFull],
                        ['the page still loads', $page !== null && $page->ok ? 'yes' : 'no'],
                    ],
                ),
                '',
                'Those two being the same number is the claim. A cache that answers "not here" for',
                'a stored empty list re-fetches it on every request of the site\'s life, for',
                'something that will never have anything in it — and nothing ever looks broken:',
                'the site is only slow, and the operator sees traffic nobody can explain.',
            ],
        );
    }

    /**
     * A dropped packet is not a broken page.
     */
    private static function aBlip(): Claim
    {
        $survives = Bench::of();
        $survives->manager->unreachableFor = 2;

        $page = $survives->routes->yachtDetail(['yachtId' => 5001] + $survives->week(), Caller::anonymous());

        $gone = Bench::of();
        $gone->manager->unreachableFor = 30;

        $lost = $gone->routes->yachtDetail(['yachtId' => 5001] + $gone->week(), Caller::anonymous());

        return new Claim(
            'A blip on the way to the operator is not a broken page',
            $page->ok && !$lost->ok && $lost->status === 502 && $lost->said !== $lost->logged,
            [
                'A boat\'s page makes ten calls. The first of them finds nobody at the other end,',
                'twice, and then the network is back. Then the same page with the operator gone.',
                '',
                self::table(
                    ['', 'the bridge'],
                    [
                        ['two dropped calls in a row', $page->ok ? 'the page loads' : 'the page is lost'],
                        ['the operator unreachable', $lost->ok ? 'the page loads' : 'refused, ' . $lost->status],
                    ],
                ),
                '',
                'the visitor is shown: ' . $lost->said,
                'the log is given:     ' . $lost->logged,
                '',
                'Reaching nobody is worth trying again; being told no is not. And when there is',
                'nothing left to try, those are two different texts on purpose: what the',
                'operator\'s own system says about itself is written for whoever runs it, and it',
                'goes to the log rather than onto a public page.',
            ],
        );
    }

    /**
     * The nine routes that need an account, called once each. Anything thrown
     * counts as not having reached the operator.
     */
    private static function howManyRoutesReachTheOperator(Bench $bench): int
    {
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
            try {
                if ($call()) {
                    $working++;
                }
            } catch (\Throwable) {
                // Not reaching the operator is one of the two answers this is
                // counting, so it is an answer and not an accident.
            }
        }

        return $working;
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
