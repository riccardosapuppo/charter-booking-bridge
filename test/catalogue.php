<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * Eleven near-identical catalogue readers, written by copying, and one of them
 * with the wrong key in it.
 */
return [
    'a boat\'s fittings are grouped under the categories they belong to' => static function (string $which): void {
        $bench = Bench::of($which);

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_same(true, $page->ok, 'the boat\'s page did not load: ' . $page->said);

        $groups = array_column($page->body['equipmentGrouped'], 'category');
        sort($groups);

        check_same(
            ['Navigazione', 'Sicurezza', 'Vele'],
            $groups,
            'the fittings are grouped under ' . check_show($groups) . ' instead of the three categories they are in',
        );
    },

    'a catalogue that has answered is not asked again' => static function (string $which): void {
        $bench = Bench::of($which);
        $week = $bench->week();

        for ($view = 1; $view <= 10; $view++) {
            $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        }

        check_same(
            1,
            $bench->manager->howManyCalls('/catalogue/v6/equipmentCategories'),
            'ten views of one boat fetched the equipment categories '
                . $bench->manager->howManyCalls('/catalogue/v6/equipmentCategories')
                . ' times, which is what happens when an empty list cannot be told from an empty cache',
        );
    },

    'a boat keeps its own kind' => static function (string $which): void {
        $bench = Bench::of($which);

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_same(true, $page->ok, 'the boat\'s page did not load: ' . $page->said);

        check_same(
            'Monoscafo a vela',
            $page->body['yacht']['yachtCategoryName'],
            'the boat came back as ' . check_show($page->body['yacht']['yachtCategoryName'])
                . ', because the variable holding its kind was reused inside the loop over its fittings',
        );
    },

    'a boat\'s page does not cost eleven calls, and the second one costs one' => static function (string $which): void {
        $bench = Bench::of($which);
        $week = $bench->week();

        $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        $cold = $bench->manager->howManyCalls();

        $bench->manager->forgetCalls();
        $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        $warm = $bench->manager->howManyCalls();

        check_same(10, $cold, 'the first view of a boat cost ' . $cold . ' calls to the operator, one after another');
        check_same(1, $warm, 'the second view of the same boat cost ' . $warm . ' calls, and only the week\'s price can have changed');
    },

    'a blip on the way to the operator is not a broken page' => static function (string $which): void {
        $bench = Bench::of($which);

        // The next two calls find nobody at the other end, and then the network
        // is back. In the original there was one attempt and no retry on any of
        // the eleven calls a boat's page makes, so a single dropped packet
        // anywhere in the chain returned a 502 to the visitor.
        $bench->manager->unreachableFor = 2;

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_same(
            true,
            $page->ok,
            'one blip on the way to the operator lost the whole page: ' . $page->said,
        );
    },

    'a shelf of boats does not read the catalogue once per boat' => static function (string $which): void {
        $bench = Bench::of($which);

        $said = $bench->routes->yachtsCarousel($bench->week() + ['take' => 6], Caller::anonymous());

        check_same(true, $said->ok, 'the shelf did not load: ' . $said->said);
        check_same(6, count($said->body['items'] ?? []), 'the shelf is not showing six boats, so this check is measuring the wrong thing');

        // Every one of these is a row out of the options table on a real site,
        // fetched and unserialised. Reading the model list once per boat costs
        // nothing here and is invisible in the code, which is exactly why this
        // is a check and not a comment: the first draft of the repaired shelf
        // did it nineteen times for six boats.
        check_true(
            $bench->cache->reads <= 8,
            'drawing six boats asked the cache ' . $bench->cache->reads . ' times',
        );

        check_true($bench->cache->reads > 0, 'the cache was never asked for anything, so nothing was measured');
    },

    'the extras on a boat are named, not described' => static function (string $which): void {
        $bench = Bench::of($which);

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        foreach ($page->body['additionalEquipment'] as $extra) {
            check_true(
                is_string($extra['name']) && $extra['name'] !== '',
                'an extra came back with ' . check_show($extra['name']) . ' where its name should be',
            );
        }
    },
];
