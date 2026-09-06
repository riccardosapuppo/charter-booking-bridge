<?php

declare(strict_types=1);

namespace Charter;

/**
 * The ten routes, repaired.
 *
 * Read this next to {@see TheWayItWas}, which is the same ten as they were.
 * The shape is deliberately close: this is a repair, not a rewrite, and the
 * point of a repair is that somebody who knew the original can still find
 * their way around.
 *
 * What is different is short enough to list:
 *
 *  - The one route that commits somebody else's boat goes through a door.
 *  - There is one place the account is read, and it is the environment.
 *  - Dates are dates before anything is done with them.
 *  - The customer that was built is the customer that is sent.
 *  - Catalogue lists are read by one function against one table of keys.
 *  - A list that legitimately came back empty is remembered as empty.
 *  - Extras are checked against the boat, and carry the quantity that was shown.
 *  - The manager's own error text is logged, never shown.
 */
final class Bridge implements Routes
{
    private readonly Catalogue $catalogue;

    private readonly Guard $guard;

    public function __construct(
        private readonly Manager $manager,
        private readonly Cache $cache,
        private readonly Clock $clock = new Clock(),
        private readonly int $operator = Fleet::OPERATOR,
        private readonly ?Notes $notes = null,
    ) {
        $this->catalogue = new Catalogue($manager, $cache);
        $this->guard = new Guard($cache, $clock);
    }

    // ------------------------------------------------------------ the shelves

    public function yachtsCarousel(array $input, Caller $who): Answer
    {
        return $this->attempt(function () use ($input): Answer {
            $week = $this->weekFrom($input);
            $take = max(1, min(30, (int) ($input['take'] ?? 10)));
            $promoOnly = (bool) ($input['promoOnly'] ?? false);

            $fleet = $this->catalogue->fleet($this->operator);

            // The operator is not a query parameter. It was, in four routes,
            // with the site's own number as a default, so anybody could send
            // another operator's number and have the site fetch and cache a
            // catalogue nobody had asked for, using the site's account.
            $free = $this->availability(array_map('intval', array_keys($fleet)), $week);

            $items = [];

            foreach ($fleet as $boat) {
                $items[] = $this->card($boat, $free[(string) $boat['id']] ?? null, $week);
            }

            usort($items, static fn (array $a, array $b): int => ($b['buildYear'] ?? 0) <=> ($a['buildYear'] ?? 0));

            // Filter, then cut. The original cut first and filtered after, and
            // discounting is done on the oldest boats — exactly the ones the
            // sort had just pushed to the end and the cut had just removed. Two
            // pages in production asked for the twelve newest discounted boats
            // and were usually handed nothing.
            if ($promoOnly) {
                $items = array_values(array_filter(
                    $items,
                    static fn (array $one): bool => ($one['discountPercent'] ?? 0) > 0,
                ));
            }

            $items = array_slice($items, 0, $take);

            return Answer::ok([
                'periodFrom' => $week->from,
                'periodTo' => $week->to,
                'count' => count($items),
                'items' => $items,
            ]);
        });
    }

    public function yachtDetail(array $input, Caller $who): Answer
    {
        return $this->attempt(function () use ($input): Answer {
            $id = (int) ($input['yachtId'] ?? 0);

            if ($id <= 0) {
                return Answer::refused(400, 'missing_yacht', 'No boat was asked for.');
            }

            $week = $this->weekFrom($input);
            $boat = $this->catalogue->yacht($id);

            if ($boat === null) {
                return Answer::refused(404, 'no_such_yacht', 'That boat is not in the fleet.');
            }

            $free = $this->availability([$id], $week)[(string) $id] ?? null;

            $models = $this->catalogue->map('models');
            $equipment = $this->catalogue->map('equipment');
            $kitCategories = $this->catalogue->names('equipmentCategories');
            $services = $this->catalogue->names('services');
            $bases = $this->catalogue->names('bases');
            $locations = $this->catalogue->names('locations');
            $builders = $this->catalogue->names('builders');
            $categories = $this->catalogue->names('yachtCategories');

            $model = $models[(string) ($boat['yachtModelId'] ?? '')] ?? [];

            // The boat's own category, in a variable of its own.
            //
            // In the original this was `$categoryId`, and eighteen lines later
            // the loop over the standard equipment used `$categoryId` again for
            // the category of each *fitting*. By the time the loop ended it held
            // the category of the last piece of kit on the boat, and that is
            // what was looked up in the yacht-category map, and what came back
            // was null. The page said "Yacht".
            $categoryId = isset($model['yachtCategoryId']) ? (int) $model['yachtCategoryId'] : null;

            $standard = [];
            $grouped = [];

            foreach ((array) ($boat['standardYachtEquipment'] ?? []) as $fitted) {
                $entry = $equipment[(string) ($fitted['equipmentId'] ?? '')] ?? null;
                $name = Text::of($entry['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $kitCategoryId = isset($entry['categoryId']) ? (int) $entry['categoryId'] : null;
                $under = $kitCategories[(string) $kitCategoryId] ?? 'Other';

                $standard[] = $name;
                $grouped[$under][] = $name;
            }

            $season = $boat['seasonSpecificData'][0] ?? [];

            $additional = [];

            foreach ((array) ($season['additionalYachtEquipment'] ?? []) as $offer) {
                $entry = $equipment[(string) ($offer['equipmentId'] ?? '')] ?? null;

                $additional[] = [
                    'equipmentId' => (int) ($offer['equipmentId'] ?? 0),
                    // A string. The map holds a name and a category, and the
                    // loop above knows that; this line, in the original, put
                    // the whole entry where the name goes, so the REST contract
                    // promised a string and delivered an object. The page's own
                    // helper happened to dig the name back out, which is why it
                    // was never noticed.
                    'name' => Text::of($entry['name'] ?? ''),
                    'price' => $offer['price'] ?? null,
                    'currency' => $offer['currency'] ?? 'EUR',
                    'calculationType' => $offer['calculationType'] ?? null,
                    'amount' => $offer['amount'] ?? null,
                ];
            }

            $offeredServices = [];

            foreach ((array) ($season['services'] ?? []) as $offer) {
                $offeredServices[] = [
                    'serviceId' => (int) ($offer['serviceId'] ?? 0),
                    'name' => $services[(string) ($offer['serviceId'] ?? '')] ?? '',
                    'price' => $offer['price'] ?? null,
                    'currency' => $offer['currency'] ?? 'EUR',
                    'calculationType' => $offer['calculationType'] ?? null,
                    'amount' => $offer['amount'] ?? null,
                    'obligatory' => (bool) ($offer['obligatory'] ?? false),
                ];
            }

            $baseId = isset($boat['baseId']) ? (int) $boat['baseId'] : null;
            $locationId = isset($boat['locationId']) ? (int) $boat['locationId'] : null;
            $builderId = isset($model['yachtBuilderId']) ? (int) $model['yachtBuilderId'] : null;

            return Answer::ok([
                'yacht' => [
                    'id' => $id,
                    'name' => $boat['name'] ?? null,
                    'buildYear' => $boat['buildYear'] ?? null,
                    'yachtModelName' => Text::of($model['name'] ?? ''),
                    'yachtBuilderName' => $builderId === null ? null : ($builders[(string) $builderId] ?? null),
                    'yachtCategoryId' => $categoryId,
                    'yachtCategoryName' => $categoryId === null ? null : ($categories[(string) $categoryId] ?? null),
                    'baseName' => $baseId === null ? null : ($bases[(string) $baseId] ?? null),
                    'locationName' => $locationId === null ? null : ($locations[(string) $locationId] ?? null),
                    'cabins' => self::whole($boat['cabins'] ?? null),
                    'bathrooms' => self::whole($boat['wc'] ?? null),
                    'guests' => self::whole($boat['berthsTotal'] ?? null),
                    'lengthMeters' => self::number($model['loa'] ?? null),
                    'lengthFeet' => self::number($model['virtualLength'] ?? null),
                ],
                'periodFrom' => $week->from,
                'periodTo' => $week->to,
                'pictures' => $this->pictures($boat),
                'standardEquipment' => array_values(array_unique($standard)),
                'equipmentGrouped' => array_map(
                    static fn (string $under, array $items): array => [
                        'category' => $under,
                        'items' => array_values(array_unique($items)),
                    ],
                    array_keys($grouped),
                    array_values($grouped),
                ),
                'additionalEquipment' => $additional,
                'services' => $offeredServices,
                'availability' => $free['status'] ?? 'UNKNOWN',
                'price' => $free['price']['clientPrice'] ?? null,
                'priceListPrice' => $free['price']['priceListPrice'] ?? null,
                'currency' => $free['price']['currency'] ?? 'EUR',
            ]);
        });
    }

    public function countries(array $input, Caller $who): Answer
    {
        return $this->attempt(fn (): Answer => Answer::ok(['items' => $this->sorted('countries')]));
    }

    public function locations(array $input, Caller $who): Answer
    {
        return $this->attempt(fn (): Answer => Answer::ok(['items' => $this->sorted('locations')]));
    }

    public function yachtCategories(array $input, Caller $who): Answer
    {
        return $this->attempt(fn (): Answer => Answer::ok(['items' => $this->sorted('yachtCategories')]));
    }

    public function freeYachtsSearch(array $input, Caller $who): Answer
    {
        return $this->attempt(function () use ($input): Answer {
            $week = $this->weekFrom($input);

            // Every other limit in the original was clamped at both ends. The
            // page number had a floor and no ceiling, on the most expensive
            // read there is, and it is the one an idle script would walk.
            $page = max(1, min(200, (int) ($input['page'] ?? 1)));
            $perPage = max(1, min(50, (int) ($input['perPage'] ?? 12)));

            $said = $this->manager->booking('/yachtReservation/v6/freeYachtsSearch', [
                'periodFrom' => $week->from,
                'periodTo' => $week->to,
                'charterCompanies' => [$this->operator],
            ]);

            $fleet = $this->catalogue->fleet($this->operator);
            $items = [];

            foreach ((array) ($said['freeYachts'] ?? []) as $free) {
                $boat = $fleet[(string) ($free['yachtId'] ?? '')] ?? null;

                if ($boat === null) {
                    continue;
                }

                $items[] = $this->card($boat, $free, $week);
            }

            $showing = array_slice($items, ($page - 1) * $perPage, $perPage);

            return Answer::ok([
                'periodFrom' => $week->from,
                'periodTo' => $week->to,
                // Counted from what is being handed over, not from what the
                // search claimed before things were dropped from it. The
                // original passed the upstream total through while silently
                // dropping boats out of the list under it, so a page could say
                // "120 found", show three, and leave "load more" lit.
                'totalCount' => count($items),
                'count' => count($showing),
                'items' => $showing,
            ]);
        });
    }

    // ------------------------------------------------------------ the writing

    public function yachtRequest(array $input, Caller $who): Answer
    {
        $shut = $this->guard->admit('booking', $who, 5, 3600);

        if ($shut !== null) {
            return $shut;
        }

        return $this->attempt(function () use ($input): Answer {
            $id = (int) ($input['yachtId'] ?? 0);
            $boat = $id > 0 ? $this->catalogue->yacht($id) : null;

            if ($boat === null) {
                return Answer::refused(404, 'no_such_yacht', 'That boat is not in the fleet.');
            }

            try {
                $week = Dates::period($input['periodFrom'] ?? null, $input['periodTo'] ?? null, $this->clock->now());
            } catch (BadPeriod $why) {
                return Answer::refused(400, 'bad_period', $why->getMessage());
            }

            try {
                $customer = Customer::from($input, $this->catalogue->names('countries'));
            } catch (NotACustomer $why) {
                return Answer::refused(400, 'not_a_customer', $why->getMessage());
            }

            $baseId = isset($boat['baseId']) ? (int) $boat['baseId'] : null;
            $services = Extras::pick($boat, 'services', $input['serviceIds'] ?? [], $baseId, $week);
            $equipment = Extras::pick($boat, 'equipment', $input['equipmentIds'] ?? [], $baseId, $week);

            if ($services['refused'] !== [] || $equipment['refused'] !== []) {
                return Answer::refused(
                    400,
                    'extra_not_offered',
                    'One of the extras is not available for this boat and week. Reload the page and choose again.',
                    'refused extras: ' . implode(', ', array_merge($services['refused'], $equipment['refused'])),
                );
            }

            // Availability that stops the flow. In the original it was fetched,
            // and used for one thing only: to decide a flag on the option. A
            // boat that was not free simply did not appear in the answer, the
            // status stayed UNKNOWN, and the booking went ahead anyway.
            $free = $this->availability([$id], $week)[(string) $id] ?? null;

            if (($free['status'] ?? 'UNKNOWN') !== 'FREE') {
                return Answer::refused(409, 'not_free', 'That week has just been taken on this boat.');
            }

            $body = [
                'client' => $customer->forManager(),
                'periodFrom' => $week->from,
                'periodTo' => $week->to,
                'yachtID' => $id,
            ];

            if ($services['lines'] !== []) {
                $body['services'] = $services['lines'];
            }

            if ($equipment['lines'] !== []) {
                $body['equipment'] = $equipment['lines'];
            }

            $promo = trim((string) ($input['promoCode'] ?? ''));

            if ($promo !== '') {
                $body['promoCode'] = $promo;
            }

            try {
                $info = $this->manager->booking('/booking/v6/createInfo/', $body);
            } catch (ManagerSaidNo $no) {
                if ($promo !== '' && stripos($no->getMessage(), 'promo') !== false) {
                    return Answer::refused(400, 'invalid_promo', 'That promo code is not valid.', $no->getMessage());
                }

                throw $no;
            }

            $option = $this->manager->booking('/booking/v6/createOption', [
                'id' => $info['id'] ?? null,
                'uuid' => $info['uuid'] ?? null,
                'createWaitingOption' => 'false',
            ]);

            if (($option['reservationStatus'] ?? null) !== 'OPTION') {
                return Answer::refused(
                    409,
                    'no_option',
                    'The boat could not be held. Nothing has been booked.',
                    'createOption answered ' . (string) ($option['reservationStatus'] ?? 'nothing'),
                );
            }

            $booking = $this->manager->booking('/booking/v6/createBooking', [
                'id' => $option['id'] ?? null,
                'uuid' => $option['uuid'] ?? null,
            ]);

            $reservation = (string) ($booking['id'] ?? '');
            $noteKept = false;

            if ($customer->message !== '' && $this->notes !== null) {
                $this->notes->keep($reservation, $customer, $customer->message);
                $noteKept = true;
            }

            return Answer::ok([
                'reservation' => [
                    'id' => $booking['id'] ?? null,
                    'reservationStatus' => $booking['reservationStatus'] ?? null,
                    'price' => $booking['clientPrice'] ?? null,
                ],
                'messageKept' => $noteKept,
            ]);
        });
    }

    public function yachtPromo(array $input, Caller $who): Answer
    {
        // Five an hour, from one address.
        //
        // Trying a promo code is not a question the manager can be asked: each
        // try leaves a real record in the operator's system. The original tried
        // twice per attempt — once without the code and once with it — and
        // compared the prices. Five hundred words out of a dictionary left a
        // thousand rows, and told whoever ran it which codes exist and what
        // each one is worth.
        $shut = $this->guard->admit('promo', $who, 5, 3600);

        if ($shut !== null) {
            return $shut;
        }

        return $this->attempt(function () use ($input): Answer {
            $id = (int) ($input['yachtId'] ?? 0);
            $boat = $id > 0 ? $this->catalogue->yacht($id) : null;

            if ($boat === null) {
                return Answer::refused(404, 'no_such_yacht', 'That boat is not in the fleet.');
            }

            try {
                $week = Dates::period($input['periodFrom'] ?? null, $input['periodTo'] ?? null, $this->clock->now());
            } catch (BadPeriod $why) {
                return Answer::refused(400, 'bad_period', $why->getMessage());
            }

            $promo = trim((string) ($input['promoCode'] ?? ''));

            if ($promo === '') {
                return Answer::refused(400, 'missing_promo', 'No code was entered.');
            }

            $countries = array_keys($this->catalogue->names('countries'));
            $nobody = Customer::nobody((int) ($countries[0] ?? 0));

            $quote = [
                'client' => $nobody,
                'periodFrom' => $week->from,
                'periodTo' => $week->to,
                'yachtID' => $id,
                'proposal' => true,
            ];

            // The price without the code is the same for everybody who asks
            // about this boat and this week, so it is asked once and kept for a
            // quarter of an hour. That halves what a try costs, and the cap
            // above bounds what a thousand tries cost.
            $was = $this->kept('quote:' . $id . ':' . $week->from . ':' . $week->to, 900, function () use ($quote): array {
                $said = $this->manager->booking('/booking/v6/createInfo/', $quote);

                return ['price' => (float) ($said['clientPrice'] ?? 0)];
            });

            try {
                $with = $this->manager->booking('/booking/v6/createInfo/', $quote + ['promoCode' => $promo]);
            } catch (ManagerSaidNo $no) {
                return Answer::refused(400, 'invalid_promo', 'That code is not valid, or has expired.', $no->getMessage());
            }

            $now = (float) ($with['clientPrice'] ?? 0);

            if ($now >= $was['price'] - 0.01) {
                return Answer::refused(400, 'invalid_promo', 'That code is not valid, or has expired.');
            }

            return Answer::ok([
                'price' => $with['clientPrice'] ?? null,
                'priceListPrice' => $with['priceListPrice'] ?? null,
                'currency' => $with['paymentCurrency'] ?? 'EUR',
                'discounts' => $with['discounts'] ?? [],
            ]);
        });
    }

    public function trackSearch(array $input, Caller $who): Answer
    {
        // Open, because it is telemetry from a public page, but counted — and
        // what it counts has to be a boat. The original took an array of any
        // length, of any integers, from anybody, and wrote all of it into one
        // row of the options table that it read and rewrote whole on every
        // call. Two hundred thousand made-up ids in one request was a row of
        // 2.4 MB, and every later request paid for it.
        $shut = $this->guard->rate('track', $who, 60, 3600);

        if ($shut !== null) {
            return $shut;
        }

        return $this->attempt(function () use ($input): Answer {
            $fleet = $this->catalogue->fleet($this->operator);

            $ids = array_slice(array_unique(array_map('intval', (array) ($input['ids'] ?? []))), 0, 30);
            $counts = $this->cache->get('searched') ?? [];
            $counted = 0;

            foreach ($ids as $id) {
                if (!isset($fleet[(string) $id])) {
                    continue;
                }

                $counts[(string) $id] = (int) ($counts[(string) $id] ?? 0) + 1;
                $counted++;
            }

            $this->cache->put('searched', $counts, 30 * 86400);

            return Answer::ok(['counted' => $counted]);
        });
    }

    public function yachtsMostSearched(array $input, Caller $who): Answer
    {
        return $this->attempt(function () use ($input): Answer {
            $week = $this->weekFrom($input);
            $take = max(1, min(30, (int) ($input['take'] ?? 10)));

            $counts = $this->cache->get('searched') ?? [];
            arsort($counts);

            $fleet = $this->catalogue->fleet($this->operator);
            $wanted = array_slice(array_keys($counts), 0, $take);
            $free = $this->availability(array_map('intval', $wanted), $week);

            $items = [];

            foreach ($wanted as $id) {
                $boat = $fleet[(string) $id] ?? null;

                if ($boat !== null) {
                    $items[] = $this->card($boat, $free[(string) $id] ?? null, $week) + ['searches' => $counts[$id]];
                }
            }

            return Answer::ok(['count' => count($items), 'items' => $items]);
        });
    }

    // ------------------------------------------------------------------ parts

    /**
     * Everything a route can throw, turned into a refusal with two texts: one
     * for the visitor, one for the log.
     */
    private function attempt(callable $route): Answer
    {
        try {
            return $route();
        } catch (ManagerSaidNo $no) {
            return Answer::refused(
                502,
                'manager_refused',
                'The booking system could not answer just now. Please try again shortly.',
                $no->getMessage(),
            );
        } catch (Unreachable $missed) {
            return Answer::refused(
                502,
                'manager_unreachable',
                'The booking system could not be reached. Please try again shortly.',
                $missed->getMessage(),
            );
        }
    }

    private function weekFrom(array $input): Week
    {
        try {
            return Dates::period($input['periodFrom'] ?? null, $input['periodTo'] ?? null, $this->clock->now());
        } catch (BadPeriod) {
            // A shelf with no dates on it, or with dates that make no sense, is
            // shown for the next available week rather than refused: nobody
            // asked it a question about a period.
            return Dates::nextWeek($this->clock->now());
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string,array<string,mixed>>
     */
    private function availability(array $ids, Week $week): array
    {
        if ($ids === []) {
            return [];
        }

        $said = $this->manager->booking('/yachtReservation/v6/freeYachts', [
            'periodFrom' => $week->from,
            'periodTo' => $week->to,
            'yachts' => $ids,
        ]);

        $free = [];

        foreach ((array) ($said['freeYachts'] ?? []) as $one) {
            $free[(string) ($one['yachtId'] ?? '')] = $one;
        }

        return $free;
    }

    /**
     * @param array<string,mixed> $boat
     * @param array<string,mixed>|null $free
     *
     * @return array<string,mixed>
     */
    private function card(array $boat, ?array $free, Week $week): array
    {
        $models = $this->catalogue->map('models');
        $bases = $this->catalogue->names('bases');
        $locations = $this->catalogue->names('locations');

        $model = $models[(string) ($boat['yachtModelId'] ?? '')] ?? [];
        $discount = null;

        foreach ((array) ($free['price']['discounts'] ?? []) as $one) {
            if (($one['type'] ?? '') === 'PERCENTAGE') {
                $discount = (float) $one['amount'];

                break;
            }
        }

        $baseId = isset($boat['baseId']) ? (int) $boat['baseId'] : null;
        $locationId = isset($boat['locationId']) ? (int) $boat['locationId'] : null;

        return [
            'id' => (int) $boat['id'],
            'name' => $boat['name'] ?? null,
            'buildYear' => self::whole($boat['buildYear'] ?? null),
            'guests' => self::whole($boat['berthsTotal'] ?? null),
            'cabins' => self::whole($boat['cabins'] ?? null),
            'bathrooms' => self::whole($boat['wc'] ?? null),
            'lengthMeters' => self::number($model['loa'] ?? null),
            'lengthFeet' => self::number($model['virtualLength'] ?? null),
            'baseName' => $baseId === null ? null : ($bases[(string) $baseId] ?? null),
            'locationName' => $locationId === null ? null : ($locations[(string) $locationId] ?? null),
            'image' => $this->pictures($boat)[0] ?? null,
            'periodFrom' => $week->from,
            'periodTo' => $week->to,
            'availability' => $free['status'] ?? 'UNKNOWN',
            'price' => $free['price']['clientPrice'] ?? null,
            'originalPrice' => $free['price']['priceListPrice'] ?? null,
            'currency' => $free['price']['currency'] ?? 'EUR',
            'discountPercent' => $discount,
        ];
    }

    /**
     * @param array<string,mixed> $boat
     *
     * @return list<string>
     */
    private function pictures(array $boat): array
    {
        $pictures = [];

        foreach ((array) ($boat['picturesURL'] ?? []) as $one) {
            if (is_string($one) && trim($one) !== '') {
                $pictures[] = $one;
            }
        }

        // `?:` and not `??`. The original used `??`, which only steps in for
        // null, so an empty string from the manager became the `src` of a
        // broken image while a perfectly good fallback sat unused. Three routes
        // had it, which is every grid and every carousel on the site.
        $main = trim((string) ($boat['mainPictureUrl'] ?? ''));

        if ($pictures === [] && $main !== '') {
            $pictures[] = $main;
        }

        return array_values(array_unique($pictures));
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function sorted(string $shelf): array
    {
        $items = [];

        foreach ($this->catalogue->names($shelf) as $id => $name) {
            if ($name !== '') {
                $items[] = ['id' => (int) $id, 'name' => $name];
            }
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * @param callable():array<mixed> $work
     *
     * @return array<mixed>
     */
    private function kept(string $key, int $seconds, callable $work): array
    {
        $held = $this->cache->get($key);

        if ($held !== null) {
            return $held;
        }

        $fresh = $work();

        $this->cache->put($key, $fresh, $seconds);

        return $fresh;
    }

    /**
     * Numbers from the manager, made numbers.
     *
     * Four fields came through untouched — build year, cabins, berths, heads —
     * and every template on the site put them into `innerHTML` without escaping
     * them, because they were numbers. Everything around them was escaped. It
     * is not a visitor's hole to use, but it is third-party data trusted for
     * being the right sort of thing, and the fix is a cast.
     */
    private static function whole(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
