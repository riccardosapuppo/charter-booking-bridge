<?php

declare(strict_types=1);

namespace Charter;

/**
 * The ten routes.
 *
 * What this file guarantees, in the order the checks in test/ assert it:
 *
 *  - The two routes that write go through {@see Guard}: a booking has to have
 *    been composed on a page we served, and one address gets five an hour.
 *  - Dates become a {@see Week} before anything downstream sees them, so
 *    nothing downstream can be handed two strings.
 *  - There is one {@see Customer}, it is checked, and it is the one that is
 *    sent to the operator.
 *  - Extras are looked up on the boat, for this base and this week, and the
 *    quantity on each line comes from the boat's own offer.
 *  - The operator decides the total. Nothing here reads a price, an amount or
 *    a total out of a request.
 *  - Catalogue lists are read by {@see Catalogue}, once, against one table of
 *    keys, and a list that came back empty is remembered as empty.
 *  - The account comes from {@see Secrets}, which reads the environment.
 *  - The operator's own error text is logged and never shown.
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

            // The operator is fixed at construction and is not a query
            // parameter: whose catalogue this site fetches, with this site's
            // account, is not a decision a visitor gets to make.
            $free = $this->availability(array_map('intval', array_keys($fleet)), $week);

            $items = [];

            foreach ($fleet as $boat) {
                $items[] = $this->card($boat, $free[(string) $boat['id']] ?? null, $week);
            }

            usort($items, static fn (array $a, array $b): int => ($b['buildYear'] ?? 0) <=> ($a['buildYear'] ?? 0));

            // Filter, then cut. Discounting is done on the unsold end of a
            // fleet — the older boats — which the sort above has just pushed
            // to the bottom, so cutting first and filtering after hands back an
            // empty shelf on a fleet that plainly has offers.
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

            // The boat's own kind, in a variable of its own, which the loop
            // over its fittings below does not touch: a fitting's category and
            // a boat's category are two different tables under two names that
            // want to be the same name.
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
                    // A string, because the contract says a string. The map
                    // holds an entry with a name and a category in it; what
                    // goes out here is the name.
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

            // Clamped at both ends. A page number with a floor and no ceiling,
            // on the most expensive read there is, is the one an idle script
            // walks.
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
                // operator said before anything was dropped from it: a page
                // that says "120 found" and shows three leaves "load more" lit
                // for ever.
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

            // Availability that stops the flow. A boat that is not free does
            // not come back in the answer at all, so an unknown status is not a
            // maybe: it is a no, and the booking does not go on.
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
        // Trying a promo code is not a question the manager can be asked: the
        // only way to learn whether a code is any good is to ask for a quote
        // carrying it, and a quote is a record in the operator's system that
        // somebody has to live with. So the tries are counted per address, and
        // the half of the comparison that does not depend on the code is asked
        // once and kept.
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
        // what it counts has to be a boat. This row is read and rewritten whole
        // on every call, so whatever gets into it the site pays for on every
        // later request: thirty ids at a time, de-duplicated, and only ids that
        // are in the fleet.
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
            // A shelf with no dates on it, or with dates that make no sense,
            // is shown for the next available week rather than refused: nobody
            // asked it a question about a period. The route that books does not
            // do this. See yachtRequest, which refuses.
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

        // An empty string is not a picture. `??` steps in only for null and
        // would let one through as the `src` of a broken image, so what is
        // tested here is the emptiness and not the nullness.
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
     * Build year, cabins, berths and heads go into templates that put them in
     * front of a reader without escaping them, because they are numbers. They
     * are numbers because of this cast, and not because of where they came
     * from.
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
