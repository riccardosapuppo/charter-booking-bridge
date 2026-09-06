<?php

declare(strict_types=1);

namespace Charter;

/**
 * A booking system that does not exist, answering in the shape a real one does.
 *
 * It is the whole reason the suite can be honest. Every claim in the README is
 * counted against this object: how many times it was rung up to draw one page,
 * how many records a thousand anonymous posts left in it, what the total on a
 * booking came to against what the page had shown. None of it needs a network,
 * an account, or anybody's permission.
 *
 * It checks the account it is given, in both the shapes the two families of
 * endpoints use, which is what lets a check rotate the password and count how
 * many routes noticed. And it has two knobs a real one does not: it can be told
 * to be unreachable for the next few calls, and it can be told to answer a
 * catalogue endpoint with an empty list. Both are states a live system reaches
 * on its own and neither can be waited for, so they are asked for.
 */
final class InventedManager implements Transport
{
    public const USERNAME = 'demo-account@invented';
    public const PASSWORD = 'demo-password';

    /** @var list<array{path:string,body:array<string,mixed>|null}> */
    private array $calls = [];

    /** @var array<int,array<string,mixed>> */
    private array $reservations = [];

    private int $nextId = 800001;

    /** @var array<string,string> */
    private array $account;

    /** Set to a number to make the next N calls fail as if the network dropped. */
    public int $unreachableFor = 0;

    /**
     * Catalogue paths this manager answers with a list that has nothing in it.
     *
     * A perfectly ordinary state: an operator who has not filled a shelf in.
     * "There, and empty" and "never asked" are two different facts, and this is
     * how a check gets to see the first one.
     *
     * @var list<string>
     */
    public array $emptyLists = [];

    public function __construct(string $username = self::USERNAME, string $password = self::PASSWORD)
    {
        $this->account = ['username' => $username, 'password' => $password];
    }

    public function send(string $method, string $path, ?array $body): array
    {
        $this->calls[] = ['path' => $path, 'body' => $body];

        if ($this->unreachableFor > 0) {
            $this->unreachableFor--;

            throw new Unreachable('the invented manager is pretending the network dropped');
        }

        if (!$this->accountIsRight($body)) {
            return ['status' => 'ERROR', 'errorCode' => 3, 'errorMessage' => 'wrong username or password'];
        }

        return $this->answer($path, $body ?? []);
    }

    // ---------------------------------------------------------------- reading

    /** @return list<array{path:string,body:array<string,mixed>|null}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function howManyCalls(string $pathStartsWith = ''): int
    {
        if ($pathStartsWith === '') {
            return count($this->calls);
        }

        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => str_starts_with($call['path'], $pathStartsWith),
        ));
    }

    public function forgetCalls(): void
    {
        $this->calls = [];
    }

    /**
     * Everything that is now sitting in the operator's system.
     *
     * @param string|null $ofStatus INFO, OPTION or RESERVATION; null for all
     *
     * @return list<array<string,mixed>>
     */
    public function reservations(?string $ofStatus = null): array
    {
        return array_values(array_filter(
            $this->reservations,
            static fn (array $one): bool => $ofStatus === null || $one['reservationStatus'] === $ofStatus,
        ));
    }

    // ---------------------------------------------------------------- answering

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function answer(string $path, array $body): array
    {
        $lists = [
            '/catalogue/v6/equipment' => ['equipment', Fleet::equipment(...)],
            '/catalogue/v6/equipmentCategories' => ['equipmentCategories', Fleet::equipmentCategories(...)],
            '/catalogue/v6/services' => ['services', Fleet::services(...)],
            '/catalogue/v6/charterBases' => ['bases', Fleet::bases(...)],
            '/catalogue/v6/locations' => ['locations', Fleet::locations(...)],
            '/catalogue/v6/yachtBuilders' => ['builders', Fleet::builders(...)],
            '/catalogue/v6/yachtCategories' => ['categories', Fleet::yachtCategories(...)],
            '/catalogue/v6/seasons' => ['seasons', Fleet::seasons(...)],
            '/catalogue/v6/yachtModels' => ['models', Fleet::models(...)],
            '/catalogue/v6/countries' => ['countries', Fleet::countries(...)],
        ];

        if (isset($lists[$path])) {
            [$key, $items] = $lists[$path];

            return ['status' => 'OK', $key => in_array($path, $this->emptyLists, true) ? [] : $items()];
        }

        if (str_starts_with($path, '/catalogue/v6/yachts/')) {
            $operator = (int) substr($path, strlen('/catalogue/v6/yachts/'));

            return [
                'status' => 'OK',
                'yachts' => $operator === Fleet::OPERATOR ? Fleet::yachts() : [],
            ];
        }

        if (str_starts_with($path, '/catalogue/v6/yacht/')) {
            $id = (int) substr($path, strlen('/catalogue/v6/yacht/'));
            $one = $this->boat($id);

            return ['status' => 'OK', 'yachts' => $one === null ? [] : [$one]];
        }

        return match ($path) {
            '/yachtReservation/v6/freeYachts' => $this->freeYachts($body),
            '/yachtReservation/v6/freeYachtsSearch' => $this->freeYachtsSearch($body),
            '/booking/v6/createInfo/' => $this->createInfo($body),
            '/booking/v6/createOption' => $this->moveTo($body, 'OPTION'),
            '/booking/v6/createBooking' => $this->moveTo($body, 'RESERVATION'),
            default => ['status' => 'ERROR', 'errorCode' => 404, 'errorMessage' => 'no such endpoint: ' . $path],
        };
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function freeYachts(array $body): array
    {
        $wanted = array_map('intval', (array) ($body['yachts'] ?? []));
        $free = [];

        foreach ($wanted as $id) {
            if ($this->boat($id) === null) {
                continue;
            }

            $free[] = $this->availability($id, (string) ($body['periodFrom'] ?? ''), (string) ($body['periodTo'] ?? ''));
        }

        return ['status' => 'OK', 'freeYachts' => $free];
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function freeYachtsSearch(array $body): array
    {
        $free = [];

        foreach (Fleet::yachts() as $boat) {
            $free[] = $this->availability(
                (int) $boat['id'],
                (string) ($body['periodFrom'] ?? ''),
                (string) ($body['periodTo'] ?? ''),
            );
        }

        return ['status' => 'OK', 'freeYachts' => $free, 'totalCount' => count($free)];
    }

    /**
     * @return array<string,mixed>
     */
    private function availability(int $id, string $from, string $to): array
    {
        $prices = Fleet::prices()[$id];
        $taken = $this->alreadyTaken($id, $from, $to);

        $discounts = $prices['discount'] === null
            ? []
            : [['discountItemId' => 1, 'type' => 'PERCENTAGE', 'amount' => $prices['discount']]];

        return [
            'yachtId' => $id,
            'status' => $taken ? 'UNDER_OPTION' : 'FREE',
            'optionValidTill' => '01.01.2027 12:00',
            'price' => [
                'clientPrice' => number_format($prices['price'], 2, '.', ''),
                'priceListPrice' => number_format($prices['list'], 2, '.', ''),
                'currency' => 'EUR',
                'discounts' => $discounts,
            ],
        ];
    }

    private function alreadyTaken(int $id, string $from, string $to): bool
    {
        foreach ($this->reservations as $one) {
            if ((int) $one['yachtId'] !== $id) {
                continue;
            }

            if (!in_array($one['reservationStatus'], ['OPTION', 'RESERVATION'], true)) {
                continue;
            }

            if ($one['periodFrom'] === $from && $one['periodTo'] === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * A quote, and a record of it in the operator's system. This is the call
     * that is not a question: an INFO row exists afterwards, and somebody has to
     * live with it.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function createInfo(array $body): array
    {
        $id = (int) ($body['yachtID'] ?? 0);
        $boat = $this->boat($id);

        if ($boat === null) {
            return ['status' => 'ERROR', 'errorCode' => 12, 'errorMessage' => 'no such yacht'];
        }

        $base = Fleet::prices()[$id]['price'];
        $promo = (string) ($body['promoCode'] ?? '');

        if ($promo !== '' && $promo !== Fleet::PROMO) {
            return ['status' => 'ERROR', 'errorCode' => 71, 'errorMessage' => 'promoCode not valid'];
        }

        $off = $promo === Fleet::PROMO ? $base * 0.10 : 0.0;

        $extras = $this->extras($boat, $body);
        $total = $base - $off + $extras['total'];

        $number = $this->nextId++;

        $this->reservations[$number] = [
            'id' => $number,
            'uuid' => sprintf('invented-%06d', $number),
            'reservationStatus' => 'INFO',
            'yachtId' => $id,
            'periodFrom' => (string) ($body['periodFrom'] ?? ''),
            'periodTo' => (string) ($body['periodTo'] ?? ''),
            'client' => (array) ($body['client'] ?? []),
            'promoCode' => $promo,
            'services' => $extras['services'],
            'additionalEquipment' => $extras['equipment'],
            'clientPrice' => $total,
        ];

        return [
            'status' => 'OK',
            'id' => $number,
            'uuid' => $this->reservations[$number]['uuid'],
            'reservationStatus' => 'INFO',
            'yachtId' => $id,
            'client' => (array) ($body['client'] ?? []),
            'services' => $extras['services'],
            'additionalEquipment' => $extras['equipment'],
            'clientPrice' => number_format($total, 2, '.', ''),
            'priceListPrice' => number_format(Fleet::prices()[$id]['list'] + $extras['total'], 2, '.', ''),
            'paymentCurrency' => 'EUR',
            'discounts' => $off > 0.0 ? [['discountItemId' => 9, 'type' => 'PERCENTAGE', 'amount' => 10.0]] : [],
        ];
    }

    /**
     * What the extras on this quote come to.
     *
     * A line carries a quantity and is charged for that many; a bare id is one
     * of them, which is what a real manager does with a number where it expects
     * a line. So what the operator charges depends on what the bridge sends,
     * and a check can hold the two figures up against each other.
     *
     * @param array<string,mixed> $boat
     * @param array<string,mixed> $body
     *
     * @return array{total:float,services:list<array<string,mixed>>,equipment:list<array<string,mixed>>}
     */
    private function extras(array $boat, array $body): array
    {
        $season = $boat['seasonSpecificData'][0] ?? [];
        $total = 0.0;
        $lines = ['services' => [], 'equipment' => []];

        $families = [
            'services' => ['from' => (array) ($season['services'] ?? []), 'idKey' => 'serviceId'],
            'equipment' => ['from' => (array) ($season['additionalYachtEquipment'] ?? []), 'idKey' => 'equipmentId'],
        ];

        foreach ($families as $family => $of) {
            foreach ((array) ($body[$family] ?? []) as $asked) {
                $id = is_array($asked) ? (int) ($asked[$of['idKey']] ?? 0) : (int) $asked;
                $quantity = is_array($asked) ? (float) ($asked['quantity'] ?? 1) : 1.0;

                foreach ($of['from'] as $offered) {
                    if ((int) ($offered[$of['idKey']] ?? 0) !== $id) {
                        continue;
                    }

                    $total += (float) $offered['price'] * $quantity;

                    $lines[$family][] = [
                        'id' => $offered['id'],
                        $of['idKey'] => $id,
                        'quantity' => number_format($quantity, 2, '.', ''),
                        'listPrice' => $offered['price'],
                    ];
                }
            }
        }

        return ['total' => $total, 'services' => $lines['services'], 'equipment' => $lines['equipment']];
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function moveTo(array $body, string $status): array
    {
        $id = (int) ($body['id'] ?? 0);
        $one = $this->reservations[$id] ?? null;

        if ($one === null || $one['uuid'] !== (string) ($body['uuid'] ?? '')) {
            return ['status' => 'ERROR', 'errorCode' => 21, 'errorMessage' => 'no such reservation'];
        }

        $this->reservations[$id]['reservationStatus'] = $status;

        return [
            'status' => 'OK',
            'id' => $id,
            'uuid' => $one['uuid'],
            'reservationStatus' => $status,
            'yachtId' => $one['yachtId'],
            'clientPrice' => number_format((float) $one['clientPrice'], 2, '.', ''),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function boat(int $id): ?array
    {
        foreach (Fleet::yachts() as $boat) {
            if ((int) $boat['id'] === $id) {
                return $boat;
            }
        }

        return null;
    }

    /**
     * The account arrives in one of two shapes depending on the endpoint, and
     * both are checked, because a bridge that sends the catalogue shape to the
     * booking side gets nothing back and the reason is not obvious.
     *
     * @param array<string,mixed>|null $body
     */
    private function accountIsRight(?array $body): bool
    {
        $given = null;

        if (is_array($body)) {
            if (isset($body['credentials']) && is_array($body['credentials'])) {
                $given = $body['credentials'];
            } elseif (isset($body['username'])) {
                $given = $body;
            }
        }

        return is_array($given)
            && ($given['username'] ?? null) === $this->account['username']
            && ($given['password'] ?? null) === $this->account['password'];
    }
}
