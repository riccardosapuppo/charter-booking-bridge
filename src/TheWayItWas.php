<?php

declare(strict_types=1);

namespace Charter;

/**
 * The ten routes as they were, de-branded and kept runnable.
 *
 * Nothing here is a caricature. It is the original file with the client's name,
 * the operator's number, the supplier's host and the real account taken out,
 * the Italian identifiers translated, and everything else left exactly as it
 * behaved — including the parts that are perfectly good, of which there are
 * more than the list of faults suggests.
 *
 * It is kept because a repair nobody can see is a claim. Every check in this
 * repository is written against {@see Routes}, so `php bin/red.php` points the
 * same checks at this class and requires each of them to fail. What that
 * command prints is the evidence that the checks are about something.
 *
 * The account below is a placeholder and always was, here. In the file this was
 * taken from it was the operator's real one, in clear, nine times.
 */
final class TheWayItWas implements Routes
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Cache $cache,
        private readonly Clock $clock = new Clock(),
    ) {
    }

    // ------------------------------------------------------------- the client

    /**
     * One request, thirty seconds, no retry, and the upstream error body handed
     * straight back as the message.
     *
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>|Answer the answer, or the refusal it became
     */
    private function request(string $path, ?array $body): array|Answer
    {
        try {
            $said = $this->transport->send('POST', $path, $body);
        } catch (Unreachable $missed) {
            return Answer::refused(500, 'manager_http', $missed->getMessage());
        }

        if (($said['status'] ?? 'OK') !== 'OK') {
            // The whole of the manager's answer, re-encoded, as the message.
            // WordPress renders that into the REST response and the page prints
            // it into a div on the screen of whoever happened to trigger it.
            return Answer::refused(502, 'manager_api_error', (string) json_encode($said));
        }

        return $said;
    }

    // ---------------------------------------------------------- the catalogue

    /**
     * @param array<string,string> $account
     *
     * @return array<string,mixed>|Answer
     */
    private function map(array $account, string $key, string $path, string $under, int $hours): array|Answer
    {
        $cached = $this->cache->get($key);

        // `!empty`, so a list that legitimately came back empty is
        // indistinguishable from one that was never fetched. It is stored, it
        // is never accepted, and the manager is asked again on every request
        // for as long as the site is up.
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $said = $this->request($path, $account);

        if ($said instanceof Answer) {
            return $said;
        }

        $map = [];

        foreach ((array) ($said[$under] ?? []) as $item) {
            if (isset($item['id'])) {
                $map[(string) $item['id']] = $item;
            }
        }

        $this->cache->put($key, $map, $hours * 3600);

        return $map;
    }

    /**
     * @param array<string,string> $account
     *
     * @return array<string,string>|Answer
     */
    private function names(array $account, string $key, string $path, string $under, int $hours): array|Answer
    {
        $map = $this->map($account, $key, $path, $under, $hours);

        if ($map instanceof Answer) {
            return $map;
        }

        return array_map(static fn (array $item): string => self::text($item['name'] ?? ''), $map);
    }

    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            return $value['textIT'] ?? $value['textEN'] ?? $value['textHR'] ?? $value['textDE'] ?? $value['textSI'] ?? '';
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @return array{from:string,to:string}
     */
    private function defaultPeriod(): array
    {
        $now = $this->clock->now();
        $day = (int) gmdate('w', $now);
        $ahead = (6 - $day + 7) % 7;

        if ($ahead === 0) {
            $ahead = 7;
        }

        $from = (int) strtotime('+' . $ahead . ' days', $now);

        return ['from' => gmdate('d.m.Y', $from), 'to' => gmdate('d.m.Y', $from + 7 * 86400)];
    }

    /**
     * Converts, and validates nothing. Two shapes are recognised; everything
     * else is returned exactly as it arrived, and travels on into the manager.
     */
    private static function toDmy(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $said) === 1) {
            return $said[3] . '.' . $said[2] . '.' . $said[1];
        }

        return $value;
    }

    // -------------------------------------------------------------- the ten

    public function yachtsCarousel(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        $account = ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS];

        $operator = (int) ($input['charterCompanyId'] ?? Fleet::OPERATOR);
        $take = max(1, min(30, (int) ($input['take'] ?? 10)));
        $default = $this->defaultPeriod();
        $from = self::toDmy($input['periodFrom'] ?? '') ?: $default['from'];
        $to = self::toDmy($input['periodTo'] ?? '') ?: $default['to'];

        // No cache here, although the cached fleet reader exists two functions
        // away and the boat page uses it. The heaviest call on the busiest
        // route, made again on every page load.
        $said = $this->request('/catalogue/v6/yachts/' . $operator, $account);

        if ($said instanceof Answer) {
            return $said;
        }

        $yachts = (array) ($said['yachts'] ?? []);

        usort($yachts, static fn (array $a, array $b): int => ($b['buildYear'] ?? 0) <=> ($a['buildYear'] ?? 0));

        // Cut to the newest, and only then, at the very end of the function,
        // filtered down to the discounted ones.
        $yachts = array_slice($yachts, 0, $take);
        $ids = array_map(static fn (array $one): int => (int) $one['id'], $yachts);

        $free = $this->request('/yachtReservation/v6/freeYachts', [
            'credentials' => $account,
            'periodFrom' => $from,
            'periodTo' => $to,
            'yachts' => $ids,
        ]);

        if ($free instanceof Answer) {
            return $free;
        }

        $byId = [];

        foreach ((array) ($free['freeYachts'] ?? []) as $one) {
            $byId[(string) ($one['yachtId'] ?? '')] = $one;
        }

        $models = $this->map($account, 'models_v6', '/catalogue/v6/yachtModels', 'models', 12);

        if ($models instanceof Answer) {
            return $models;
        }

        $bases = $this->names($account, 'bases_v6', '/catalogue/v6/charterBases', 'bases', 12);

        if ($bases instanceof Answer) {
            return $bases;
        }

        $locations = $this->names($account, 'locations_v6', '/catalogue/v6/locations', 'locations', 12);

        if ($locations instanceof Answer) {
            return $locations;
        }

        $items = [];

        foreach ($yachts as $boat) {
            $id = (int) ($boat['id'] ?? 0);
            $one = $byId[(string) $id] ?? null;
            $model = $models[(string) ($boat['yachtModelId'] ?? '')] ?? null;

            $discount = null;

            foreach ((array) ($one['price']['discounts'] ?? []) as $said2) {
                if (($said2['type'] ?? '') === 'PERCENTAGE') {
                    $discount = $said2['amount'] ?? null;

                    break;
                }
            }

            $items[] = [
                'id' => $id,
                'name' => $boat['name'] ?? null,
                'buildYear' => $boat['buildYear'] ?? null,
                'guests' => $boat['berthsTotal'] ?? null,
                'cabins' => $boat['cabins'] ?? null,
                'bathrooms' => $boat['wc'] ?? null,
                'lengthMeters' => isset($model['loa']) && is_numeric($model['loa']) ? (float) $model['loa'] : null,
                'lengthFeet' => isset($model['virtualLength']) && is_numeric($model['virtualLength']) ? (float) $model['virtualLength'] : null,
                'baseName' => isset($boat['baseId']) ? ($bases[(string) $boat['baseId']] ?? null) : null,
                'locationName' => isset($boat['locationId']) ? ($locations[(string) $boat['locationId']] ?? null) : null,
                // `??`, which only steps in for null: an empty string from the
                // manager becomes the src of a broken image.
                'image' => $boat['mainPictureUrl'] ?? ('https://pictures.example/fallback/' . $id . '.jpg'),
                'periodFrom' => $from,
                'periodTo' => $to,
                'availability' => $one['status'] ?? 'UNKNOWN',
                'price' => $one['price']['clientPrice'] ?? null,
                'originalPrice' => $one['price']['priceListPrice'] ?? null,
                'currency' => $one['price']['currency'] ?? 'EUR',
                'discountPercent' => $discount,
            ];
        }

        if ((int) ($input['promoOnly'] ?? 0) !== 0) {
            $items = array_values(array_filter(
                $items,
                static fn (array $one): bool => isset($one['discountPercent']) && (float) $one['discountPercent'] > 0,
            ));
        }

        return Answer::ok([
            'periodFrom' => $from,
            'periodTo' => $to,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function yachtDetail(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        $account = ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS];

        $id = (int) ($input['yachtId'] ?? 0);

        if ($id === 0) {
            return Answer::refused(400, 'missing_yacht_id', 'Missing yachtId');
        }

        $operator = (int) ($input['charterCompanyId'] ?? Fleet::OPERATOR);
        $default = $this->defaultPeriod();
        $from = self::toDmy($input['periodFrom'] ?? '') ?: $default['from'];
        $to = self::toDmy($input['periodTo'] ?? '') ?: $default['to'];

        // Eleven calls, in a line, thirty seconds of patience each.
        $said = $this->request('/catalogue/v6/yacht/' . $id, $account);

        if ($said instanceof Answer) {
            return $said;
        }

        $boat = $said['yachts'][0] ?? null;

        if (!is_array($boat)) {
            return Answer::refused(404, 'yacht_not_found', 'Yacht not found');
        }

        $free = $this->request('/yachtReservation/v6/freeYachts', [
            'credentials' => $account,
            'periodFrom' => $from,
            'periodTo' => $to,
            'yachts' => [$id],
        ]);

        if ($free instanceof Answer) {
            return $free;
        }

        $one = null;

        foreach ((array) ($free['freeYachts'] ?? []) as $candidate) {
            if ((int) ($candidate['yachtId'] ?? 0) === $id) {
                $one = $candidate;

                break;
            }
        }

        $shelves = [
            'models' => ['models_v6', '/catalogue/v6/yachtModels', 'models', 12],
            'equipment' => ['equipment_v6', '/catalogue/v6/equipment', 'equipment', 12],
            // The wrong key. The answer holds the list under
            // `equipmentCategories`; `categories` is what the *yacht* category
            // endpoint uses, and it is right there in this same table.
            'kitCategories' => ['equipment_categories_v6', '/catalogue/v6/equipmentCategories', 'categories', 12],
            'services' => ['services_v6', '/catalogue/v6/services', 'services', 12],
            'bases' => ['bases_v6', '/catalogue/v6/charterBases', 'bases', 12],
            'locations' => ['locations_v6', '/catalogue/v6/locations', 'locations', 12],
            'builders' => ['builders_v6', '/catalogue/v6/yachtBuilders', 'builders', 12],
            'categories' => ['yacht_categories_v6', '/catalogue/v6/yachtCategories', 'categories', 12],
        ];

        $of = [];

        foreach ($shelves as $name => [$key, $path, $under, $hours]) {
            $shelf = in_array($name, ['models', 'equipment'], true)
                ? $this->map($account, $key, $path, $under, $hours)
                : $this->names($account, $key, $path, $under, $hours);

            if ($shelf instanceof Answer) {
                return $shelf;
            }

            $of[$name] = $shelf;
        }

        // The eleventh call: the operator's entire fleet, downloaded to read
        // one field off it — the build year, which the single-boat answer above
        // already carries.
        $fleet = $this->map($account, 'yachts_v6_' . $operator, '/catalogue/v6/yachts/' . $operator, 'yachts', 6);

        if ($fleet instanceof Answer) {
            return $fleet;
        }

        $model = $of['models'][(string) ($boat['yachtModelId'] ?? '')] ?? null;
        $categoryId = $model['yachtCategoryId'] ?? null;

        $standard = [];
        $grouped = [];

        foreach ((array) ($boat['standardYachtEquipment'] ?? []) as $fitted) {
            $fittedId = $fitted['equipmentId'] ?? null;

            if ($fittedId === null) {
                continue;
            }

            $entry = $of['equipment'][(string) $fittedId] ?? null;
            $name = '';
            // The same name as the boat's category, eighteen lines after it was
            // set. When this loop ends, $categoryId is the category of the last
            // fitting on the boat.
            $categoryId = null;

            if (is_array($entry)) {
                $name = self::text($entry['name'] ?? '');
                $categoryId = $entry['categoryId'] ?? null;
            } elseif (is_string($entry)) {
                $name = $entry;
            }

            if ($name === '') {
                continue;
            }

            $standard[] = $name;
            $under = $categoryId !== null ? ($of['kitCategories'][(string) $categoryId] ?? 'Other') : 'Other';
            $grouped[$under][] = $name;
        }

        $season = $boat['seasonSpecificData'][0] ?? [];

        $additional = [];

        foreach ((array) ($season['additionalYachtEquipment'] ?? []) as $offer) {
            $additional[] = [
                'equipmentId' => (int) ($offer['equipmentId'] ?? 0),
                // The whole map entry where a name is promised.
                'name' => $of['equipment'][(string) ($offer['equipmentId'] ?? '')] ?? '',
                'price' => $offer['price'] ?? null,
                'currency' => $offer['currency'] ?? null,
                'calculationType' => $offer['calculationType'] ?? null,
                'amount' => $offer['amount'] ?? null,
            ];
        }

        $services = [];

        foreach ((array) ($season['services'] ?? []) as $offer) {
            $services[] = [
                'serviceId' => (int) ($offer['serviceId'] ?? 0),
                'name' => $of['services'][(string) ($offer['serviceId'] ?? '')] ?? '',
                'price' => $offer['price'] ?? null,
                'currency' => $offer['currency'] ?? null,
                'calculationType' => $offer['calculationType'] ?? null,
                'amount' => $offer['amount'] ?? null,
                'obligatory' => (bool) ($offer['obligatory'] ?? false),
            ];
        }

        $listed = $fleet[(string) $id] ?? [];

        return Answer::ok([
            'yacht' => [
                'id' => $id,
                'name' => $boat['name'] ?? null,
                'buildYear' => $listed['buildYear'] ?? null,
                'yachtModelName' => isset($model['name']) ? self::text($model['name']) : null,
                'yachtBuilderName' => isset($model['yachtBuilderId'])
                    ? ($of['builders'][(string) $model['yachtBuilderId']] ?? null)
                    : null,
                'yachtCategoryId' => $categoryId,
                'yachtCategoryName' => $categoryId ? ($of['categories'][(string) $categoryId] ?? null) : null,
                'baseName' => isset($boat['baseId']) ? ($of['bases'][(string) $boat['baseId']] ?? null) : null,
                'locationName' => isset($boat['locationId']) ? ($of['locations'][(string) $boat['locationId']] ?? null) : null,
                'cabins' => $boat['cabins'] ?? null,
                'bathrooms' => $boat['wc'] ?? null,
                'guests' => $boat['berthsTotal'] ?? null,
                'lengthMeters' => isset($model['loa']) && is_numeric($model['loa']) ? (float) $model['loa'] : null,
                'lengthFeet' => isset($model['virtualLength']) && is_numeric($model['virtualLength']) ? (float) $model['virtualLength'] : null,
            ],
            'periodFrom' => $from,
            'periodTo' => $to,
            'pictures' => [$boat['mainPictureUrl'] ?? ('https://pictures.example/fallback/' . $id . '.jpg')],
            'standardEquipment' => array_values(array_unique($standard)),
            'equipmentGrouped' => array_map(
                static fn (string $under, array $items): array => ['category' => $under, 'items' => array_values(array_unique($items))],
                array_keys($grouped),
                array_values($grouped),
            ),
            'additionalEquipment' => $additional,
            'services' => $services,
            'availability' => $one['status'] ?? 'UNKNOWN',
            'price' => $one['price']['clientPrice'] ?? null,
            'priceListPrice' => $one['price']['priceListPrice'] ?? null,
            'currency' => $one['price']['currency'] ?? 'EUR',
        ]);
    }

    public function yachtRequest(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        $account = ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS];

        $id = (int) ($input['yachtId'] ?? 0);
        // Not even converted, let alone checked. The two routes that only read
        // convert; this one, the one that books, takes the strings as they came.
        $from = (string) ($input['periodFrom'] ?? '');
        $to = (string) ($input['periodTo'] ?? '');

        $name = trim((string) ($input['name'] ?? ''));
        $surname = trim((string) ($input['surname'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $zip = trim((string) ($input['zip'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $countryId = (int) ($input['countryId'] ?? 1);
        $promo = trim((string) ($input['promoCode'] ?? ''));

        $serviceIds = [];

        foreach ((array) ($input['serviceIds'] ?? []) as $item) {
            $serviceId = is_array($item) ? (int) ($item['serviceId'] ?? $item['id'] ?? 0) : (int) $item;

            // The id survives. The quantity beside it does not.
            if ($serviceId > 0) {
                $serviceIds[] = $serviceId;
            }
        }

        $equipmentIds = [];

        foreach ((array) ($input['equipmentIds'] ?? []) as $item) {
            $equipmentId = is_array($item) ? (int) ($item['equipmentId'] ?? $item['id'] ?? 0) : (int) $item;

            if ($equipmentId > 0) {
                $equipmentIds[] = $equipmentId;
            }
        }

        if ($id === 0 || $from === '' || $to === '') {
            return Answer::refused(400, 'missing_params', 'Missing yachtId or dates');
        }

        // Fourteen lines of thought-out fallbacks, assigned to a variable that
        // is never read again.
        $client = [
            'name' => $name !== '' ? $name : 'Promo',
            'surname' => $surname !== '' ? $surname : 'Code',
            'company' => false,
            'vatNr' => '',
            'address' => $address !== '' ? $address : 'N/A',
            'zip' => $zip !== '' ? $zip : '00000',
            'city' => $city !== '' ? $city : 'N/A',
            'countryId' => (string) ($countryId ?: 1),
            'email' => $email !== '' ? $email : 'promo@example.invalid',
            'phone' => $phone !== '' ? $phone : '0000000000',
            'mobile' => '',
            'skype' => '',
        ];

        $free = $this->request('/yachtReservation/v6/freeYachts', [
            'credentials' => $account,
            'periodFrom' => $from,
            'periodTo' => $to,
            'yachts' => [$id],
        ]);

        if ($free instanceof Answer) {
            return $free;
        }

        $availability = 'UNKNOWN';

        foreach ((array) ($free['freeYachts'] ?? []) as $candidate) {
            if ((int) ($candidate['yachtId'] ?? 0) === $id) {
                $availability = $candidate['status'] ?? 'UNKNOWN';

                break;
            }
        }

        $body = [
            'credentials' => $account,
            // A second customer, built inline out of the raw values, with no
            // fallback at all. This is the one that is sent.
            'client' => [
                'name' => $name,
                'surname' => $surname,
                'company' => false,
                'vatNr' => '',
                'address' => $address,
                'zip' => $zip,
                'city' => $city,
                'countryId' => (string) $countryId,
                'email' => $email,
                'phone' => $phone,
                'mobile' => '',
                'skype' => '',
            ],
            'periodFrom' => $from,
            'periodTo' => $to,
            'yachtID' => $id,
        ];

        if ($serviceIds !== []) {
            $body['services'] = $serviceIds;
        }

        if ($equipmentIds !== []) {
            $body['equipment'] = $equipmentIds;
        }

        if ($promo !== '') {
            $body['promoCode'] = $promo;
        }

        $info = $this->request('/booking/v6/createInfo/', $body);

        if ($info instanceof Answer) {
            return $info;
        }

        $infoId = $info['id'] ?? null;
        $infoUuid = $info['uuid'] ?? null;

        if ($infoId === null || $infoUuid === null) {
            return Answer::refused(502, 'missing_info', 'Missing reservation info');
        }

        // The only use availability is put to: a flag. Nothing stops.
        $waiting = !in_array(strtoupper((string) $availability), ['FREE', 'AVAILABLE'], true);

        $option = $this->request('/booking/v6/createOption', [
            'credentials' => $account,
            'id' => $infoId,
            'uuid' => $infoUuid,
            'createWaitingOption' => $waiting ? 'true' : 'false',
        ]);

        if ($option instanceof Answer) {
            return $option;
        }

        if (($option['reservationStatus'] ?? null) !== 'OPTION') {
            return Answer::ok([
                'reservation' => [
                    'id' => $option['id'] ?? $infoId,
                    'reservationStatus' => $option['reservationStatus'] ?? null,
                ],
                'message' => 'Option created, booking not confirmed',
            ]);
        }

        $booking = $this->request('/booking/v6/createBooking', [
            'credentials' => $account,
            'id' => $option['id'] ?? $infoId,
            'uuid' => $option['uuid'] ?? $infoUuid,
        ]);

        if ($booking instanceof Answer) {
            return $booking;
        }

        return Answer::ok([
            'reservation' => [
                'id' => $booking['id'] ?? null,
                'reservationStatus' => $booking['reservationStatus'] ?? null,
                'price' => $booking['clientPrice'] ?? null,
            ],
            'message' => 'Booking completed',
        ]);
    }

    public function yachtPromo(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        $account = ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS];

        $id = (int) ($input['yachtId'] ?? 0);
        $from = self::toDmy($input['periodFrom'] ?? '');
        $to = self::toDmy($input['periodTo'] ?? '');
        $promo = trim((string) ($input['promoCode'] ?? ''));

        if ($id === 0 || $from === '' || $to === '') {
            return Answer::refused(400, 'missing_params', 'Missing yachtId or dates');
        }

        if ($promo === '') {
            return Answer::refused(400, 'missing_promo', 'Missing promoCode');
        }

        $body = [
            'credentials' => $account,
            'client' => [
                'name' => 'Promo',
                'surname' => 'Code',
                'company' => false,
                'vatNr' => '',
                'address' => 'N/A',
                'zip' => '00000',
                'city' => 'N/A',
                'countryId' => '1',
                'email' => 'promo@example.invalid',
                'phone' => '0000000000',
                'mobile' => '',
                'skype' => '',
            ],
            'periodFrom' => $from,
            'periodTo' => $to,
            'yachtID' => $id,
            'proposal' => true,
        ];

        // Two reservations in the operator's system for every code anybody
        // types: one to find out the price without it, one to find out the
        // price with it. No cap, no cleaning up, no record of who asked.
        $was = $this->request('/booking/v6/createInfo/', $body);

        if ($was instanceof Answer) {
            return $was;
        }

        $body['promoCode'] = $promo;

        $with = $this->request('/booking/v6/createInfo/', $body);

        if ($with instanceof Answer) {
            if (stripos($with->said, 'promo') !== false) {
                return Answer::refused(400, 'invalid_promo', 'Codice promo non valido o scaduto');
            }

            return $with;
        }

        $before = (float) ($was['clientPrice'] ?? 0);
        $after = (float) ($with['clientPrice'] ?? 0);

        if ($after >= $before - 0.01) {
            return Answer::refused(400, 'invalid_promo', 'Codice promo non valido o scaduto');
        }

        return Answer::ok([
            'price' => $with['clientPrice'] ?? null,
            'priceListPrice' => $with['priceListPrice'] ?? null,
            'currency' => $with['paymentCurrency'] ?? 'EUR',
            'discounts' => $with['discounts'] ?? [],
        ]);
    }

    public function countries(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        return $this->list(
            ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS],
            'countries_v6',
            '/catalogue/v6/countries',
            'countries',
            24,
        );
    }

    public function locations(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        return $this->list(
            ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS],
            'locations_v6',
            '/catalogue/v6/locations',
            'locations',
            12,
        );
    }

    public function yachtCategories(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        return $this->list(
            ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS],
            'yacht_categories_v6',
            '/catalogue/v6/yachtCategories',
            'categories',
            12,
        );
    }

    /**
     * @param array<string,string> $account
     */
    private function list(array $account, string $key, string $path, string $under, int $hours): Answer
    {
        $names = $this->names($account, $key, $path, $under, $hours);

        if ($names instanceof Answer) {
            return $names;
        }

        $items = [];

        foreach ($names as $id => $name) {
            if ($name !== '') {
                $items[] = ['id' => (int) $id, 'name' => $name];
            }
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return Answer::ok(['items' => $items]);
    }

    public function freeYachtsSearch(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        $account = ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS];

        $operator = (int) ($input['charterCompanyId'] ?? Fleet::OPERATOR);
        $default = $this->defaultPeriod();
        $from = self::toDmy($input['periodFrom'] ?? '') ?: $default['from'];
        $to = self::toDmy($input['periodTo'] ?? '') ?: $default['to'];

        // A floor and no ceiling, on the most expensive read there is.
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($input['perPage'] ?? 12)));

        $said = $this->request('/yachtReservation/v6/freeYachtsSearch', [
            'credentials' => $account,
            'periodFrom' => $from,
            'periodTo' => $to,
            'charterCompanies' => [$operator],
        ]);

        if ($said instanceof Answer) {
            return $said;
        }

        $fleet = $this->map($account, 'yachts_v6_' . $operator, '/catalogue/v6/yachts/' . $operator, 'yachts', 6);

        if ($fleet instanceof Answer) {
            return $fleet;
        }

        $items = [];

        foreach ((array) ($said['freeYachts'] ?? []) as $one) {
            $boat = $fleet[(string) ($one['yachtId'] ?? '')] ?? null;

            // Dropped in silence, while the count below still counts them.
            if ($boat === null) {
                continue;
            }

            $items[] = [
                'id' => (int) $boat['id'],
                'name' => $boat['name'] ?? null,
                'buildYear' => $boat['buildYear'] ?? null,
                'guests' => $boat['berthsTotal'] ?? null,
                'cabins' => $boat['cabins'] ?? null,
                'bathrooms' => $boat['wc'] ?? null,
                'image' => $boat['mainPictureUrl'] ?? ('https://pictures.example/fallback/' . (int) $boat['id'] . '.jpg'),
                'periodFrom' => $from,
                'periodTo' => $to,
                'availability' => $one['status'] ?? 'UNKNOWN',
                'price' => $one['price']['clientPrice'] ?? null,
                'originalPrice' => $one['price']['priceListPrice'] ?? null,
                'currency' => $one['price']['currency'] ?? 'EUR',
            ];
        }

        $shown = array_slice($items, ($page - 1) * $perPage, $perPage);

        return Answer::ok([
            'periodFrom' => $from,
            'periodTo' => $to,
            'totalCount' => (int) ($said['totalCount'] ?? count($items)),
            'count' => count($shown),
            'items' => $shown,
        ]);
    }

    public function trackSearch(array $input, Caller $who): Answer
    {
        // No account here: the only route that never speaks to the manager.
        // Also no limit, no de-duplication, and no check that the numbers it is
        // given are boats.
        $counts = $this->cache->get('searched') ?? [];

        foreach ((array) ($input['ids'] ?? []) as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $counts[(string) $id] = (int) ($counts[(string) $id] ?? 0) + 1;
            }
        }

        $this->cache->put('searched', $counts, 30 * 86400);

        return Answer::ok(['counted' => count($counts)]);
    }

    public function yachtsMostSearched(array $input, Caller $who): Answer
    {
        // =========================
        // CONFIG (IN CLEAR)
        // =========================
        $MANAGER_USER = 'demo-account@invented';
        $MANAGER_PASS = 'demo-password';

        $account = ['username' => $MANAGER_USER, 'password' => $MANAGER_PASS];

        $operator = (int) ($input['charterCompanyId'] ?? Fleet::OPERATOR);
        $take = max(1, min(30, (int) ($input['take'] ?? 10)));

        $counts = $this->cache->get('searched') ?? [];
        arsort($counts);

        $fleet = $this->map($account, 'yachts_v6_' . $operator, '/catalogue/v6/yachts/' . $operator, 'yachts', 6);

        if ($fleet instanceof Answer) {
            return $fleet;
        }

        $items = [];

        foreach (array_slice(array_keys($counts), 0, $take) as $id) {
            $boat = $fleet[(string) $id] ?? null;

            if ($boat !== null) {
                $items[] = [
                    'id' => (int) $boat['id'],
                    'name' => $boat['name'] ?? null,
                    'searches' => $counts[$id],
                ];
            }
        }

        return Answer::ok(['count' => count($items), 'items' => $items]);
    }
}
