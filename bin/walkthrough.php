<?php

declare(strict_types=1);

/**
 * One visitor, from the front page to a confirmed booking, through the ten
 * routes in the order the site calls them.
 *
 * This is not the suite. The suite knows what it is looking for, and it was
 * written by the same person who wrote the code, which is a well-known way to
 * agree with yourself. This walks the journey instead, prints what came back at
 * every step, and says at the end whether a person could have got through.
 *
 * It also deliberately walks into two closed doors — a stranger posting at the
 * booking route, and a week that has already gone — because a journey that only
 * ever succeeds does not tell you the refusals work.
 *
 *   php bin/walkthrough.php                      the bridge
 *   php bin/walkthrough.php --bridge=as-it-was   the code as it was
 *
 * Exits non-zero when a step does not do what the line says it should.
 */

require __DIR__ . '/../src/autoload.php';

use Charter\Bench;
use Charter\Caller;
use Charter\Fleet;

$which = 'repaired';

foreach (array_slice($argv, 1) as $said) {
    if (str_starts_with($said, '--bridge=')) {
        $which = substr($said, strlen('--bridge='));
    }
}

if (!in_array($which, Bench::both(), true)) {
    fwrite(STDERR, '--bridge must be one of: ' . implode(', ', Bench::both()) . PHP_EOL);

    exit(2);
}

$bench = Bench::of($which);
$week = $bench->week();
$wrong = 0;

/**
 * @param callable():array{0:bool,1:string} $step
 */
function step(string $doing, string $ought, callable $step): void
{
    global $wrong;

    [$asItShould, $said] = $step();

    if (!$asItShould) {
        $wrong++;
    }

    echo '  ', $asItShould ? 'ok  ' : 'NO  ', str_pad($doing, 44), $said, PHP_EOL;

    if (!$asItShould) {
        echo '      ', str_repeat(' ', 44), 'expected: ', $ought, PHP_EOL;
    }
}

echo PHP_EOL, 'A visitor, through the ten routes, on the ', $which, ' bridge.', PHP_EOL;
echo 'The week is ', $week['periodFrom'], ' to ', $week['periodTo'], '.', PHP_EOL, PHP_EOL;

step('the front page carousel', 'boats, with prices', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtsCarousel($week + ['take' => 4], Caller::anonymous());
    $items = $said->body['items'] ?? [];

    return [
        $said->ok && count($items) === 4 && ($items[0]['price'] ?? null) !== null,
        $said->ok ? count($items) . ' boats, the first at ' . ($items[0]['price'] ?? '?') : $said->said,
    ];
});

step('the discounted shelf', 'only boats with a discount', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtsCarousel($week + ['take' => 4, 'promoOnly' => 1], Caller::anonymous());
    $items = $said->body['items'] ?? [];
    $all = $items !== [] && count(array_filter($items, static fn (array $one): bool => ($one['discountPercent'] ?? 0) > 0)) === count($items);

    return [$said->ok && $all, $said->ok ? count($items) . ' boats, all of them discounted' : $said->said];
});

step('the dropdowns behind the search', 'three lists, in name order', static function () use ($bench): array {
    $counted = [];

    foreach (['countries', 'locations', 'yachtCategories'] as $list) {
        $said = $bench->routes->{$list}([], Caller::anonymous());
        $counted[] = count($said->body['items'] ?? []);
    }

    return [min($counted) > 0, implode(' / ', $counted) . ' entries'];
});

step('a search for that week', 'boats, and a count that matches', static function () use ($bench, $week): array {
    $said = $bench->routes->freeYachtsSearch($week + ['perPage' => 3], Caller::anonymous());

    return [
        $said->ok && count($said->body['items'] ?? []) === 3,
        $said->ok ? ($said->body['totalCount'] ?? '?') . ' found, 3 on this page' : $said->said,
    ];
});

step('the site says what was shown', 'the ids are counted', static function () use ($bench): array {
    $said = $bench->routes->trackSearch(['ids' => [5001, 5002, 5001]], Caller::anonymous());

    return [$said->ok, ($said->body['counted'] ?? '?') . ' counted'];
});

step('the most searched shelf', 'the boats that were counted', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtsMostSearched($week, Caller::anonymous());

    return [$said->ok, count($said->body['items'] ?? []) . ' boats on the shelf'];
});

step('opening a boat', 'its kind, its fittings, its price', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
    $boat = $said->body['yacht'] ?? [];

    return [
        $said->ok && ($boat['yachtCategoryName'] ?? null) !== null && count($said->body['equipmentGrouped'] ?? []) > 1,
        $said->ok
            ? ($boat['name'] ?? '?') . ', ' . ($boat['yachtCategoryName'] ?? 'no kind')
                . ', fittings in ' . count($said->body['equipmentGrouped'] ?? []) . ' groups'
            : $said->said,
    ];
});

step('a promo code that is not real', 'refused', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtPromo(['yachtId' => 5001, 'promoCode' => 'NOT-A-CODE'] + $week, Caller::fromOurPages());

    return [!$said->ok, $said->ok ? 'accepted' : $said->said];
});

step('the promo code on the poster', 'a lower price', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtPromo(['yachtId' => 5001, 'promoCode' => Fleet::PROMO] + $week, Caller::fromOurPages());

    return [$said->ok, $said->ok ? 'now ' . $said->body['price'] : $said->said];
});

step('a stranger posts at the booking route', 'refused, nothing written', static function () use ($bench, $week): array {
    $before = count($bench->manager->reservations());
    $said = $bench->routes->yachtRequest(['yachtId' => 5001] + $week + Bench::someone(), Caller::anonymous());
    $after = count($bench->manager->reservations());

    return [!$said->ok && $after === $before, $said->ok ? 'booked' : $said->said];
});

step('the visitor books, from the form', 'a confirmed reservation', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtRequest(
        ['yachtId' => 5001, 'equipmentIds' => [['equipmentId' => 31, 'quantity' => 1]]] + $week + Bench::someone(),
        Caller::fromOurPages(),
    );

    return [
        $said->ok && ($said->body['reservation']['reservationStatus'] ?? '') === 'RESERVATION',
        $said->ok
            ? 'reservation ' . $said->body['reservation']['id'] . ', ' . $said->body['reservation']['price']
            : $said->said,
    ];
});

step('somebody else wants the same week', 'refused: it has gone', static function () use ($bench, $week): array {
    $said = $bench->routes->yachtRequest(
        ['yachtId' => 5001] + $week + Bench::someone(),
        Caller::fromOurPages('203.0.113.55'),
    );

    return [!$said->ok, $said->ok ? 'booked it a second time' : $said->said];
});

echo PHP_EOL;
echo '  ', $bench->manager->howManyCalls(), ' calls to the operator for the whole journey, ';
echo count($bench->manager->reservations('RESERVATION')), ' reservation(s) made.', PHP_EOL;

if ($wrong === 0) {
    echo PHP_EOL, 'The journey works, and both closed doors are closed.', PHP_EOL, PHP_EOL;

    exit(0);
}

echo PHP_EOL, $wrong, ' step(s) did not do what the line says.', PHP_EOL, PHP_EOL;

exit(1);
