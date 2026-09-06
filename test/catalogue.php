<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;

/**
 * The catalogue: asked once, kept, and kept even when the answer was nothing.
 * And what happens to a page when the operator cannot be reached.
 */
return [
    'a boat\'s fittings are grouped under the categories they belong to' => static function (): void {
        $bench = Bench::of();

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_same(true, $page->ok, 'the boat\'s page did not load: ' . $page->said);

        $groups = array_column($page->body['equipmentGrouped'], 'category');
        sort($groups);

        check_same(
            ['Navigazione', 'Sicurezza', 'Vele'],
            $groups,
            'the fittings are grouped under ' . check_show($groups) . ' instead of the three categories they are in',
        );

        // Every fitting is somewhere, and nowhere is "Other": a page that has
        // lost the category map groups correctly into one heap.
        $counted = 0;

        foreach ($page->body['equipmentGrouped'] as $group) {
            $counted += count($group['items']);
        }

        check_same(
            count($page->body['standardEquipment']),
            $counted,
            'the boat has ' . count($page->body['standardEquipment']) . ' fittings and ' . $counted . ' of them are in a group',
        );
    },

    'a boat keeps its own kind' => static function (): void {
        $bench = Bench::of();

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_same(true, $page->ok, 'the boat\'s page did not load: ' . $page->said);

        check_same(
            'Monoscafo a vela',
            $page->body['yacht']['yachtCategoryName'],
            'the boat came back as ' . check_show($page->body['yacht']['yachtCategoryName'])
                . ', and a boat\'s kind comes from the boat and not from the last thing bolted to it',
        );

        // The catamaran, so that the answer is read out of the map rather than
        // being the same string for every boat.
        $other = $bench->routes->yachtDetail(['yachtId' => 5003] + $bench->week(), Caller::anonymous());

        check_same(
            'Catamarano',
            $other->body['yacht']['yachtCategoryName'],
            'a second boat of a different kind came back as ' . check_show($other->body['yacht']['yachtCategoryName'] ?? null),
        );
    },

    'a catalogue that has answered is not asked again' => static function (): void {
        $bench = Bench::of();
        $week = $bench->week();

        for ($view = 1; $view <= 10; $view++) {
            $bench->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        }

        check_same(
            1,
            $bench->manager->howManyCalls('/catalogue/v6/equipmentCategories'),
            'ten views of one boat fetched the equipment categories '
                . $bench->manager->howManyCalls('/catalogue/v6/equipmentCategories')
                . ' times, and a list that has been read once is a list that has been read',
        );
    },

    'a list that came back empty is not asked for again' => static function (): void {
        // The operator answers the equipment categories endpoint with a list
        // that has nothing in it. A perfectly ordinary state: somebody has not
        // filled that shelf in.
        //
        // "There, and empty" and "never asked" are two different facts. A cache
        // that cannot tell them apart re-fetches, on every request of the
        // site's life, something that will never have anything in it — and
        // nothing looks broken, because nothing is broken. It is only slow.
        $bench = Bench::of();
        $bench->manager->emptyLists[] = '/catalogue/v6/equipmentCategories';
        $week = $bench->week();

        $page = null;

        for ($view = 1; $view <= 10; $view++) {
            $page = $bench->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        }

        check_same(true, $page?->ok, 'the boat\'s page did not load at all: ' . $page?->said);

        $asked = $bench->manager->howManyCalls('/catalogue/v6/equipmentCategories');

        check_same(
            1,
            $asked,
            'ten views asked for an empty list ' . $asked . ' times, so an empty answer is not being told from an empty cache',
        );

        // And the check is looking at something: the list really did come back
        // empty, so every fitting fell into the fallback group.
        $groups = array_column($page->body['equipmentGrouped'], 'category');

        check_same(
            ['Other'],
            $groups,
            'the categories were supposed to be empty for this check and the page found ' . check_show($groups),
        );
    },

    'a boat\'s page costs ten calls cold and one warm' => static function (): void {
        $bench = Bench::of();
        $week = $bench->week();

        $bench->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        $cold = $bench->manager->howManyCalls();

        $bench->manager->forgetCalls();
        $bench->nextRequest()->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous());
        $warm = $bench->manager->howManyCalls();

        check_same(10, $cold, 'the first view of a boat cost ' . $cold . ' calls to the operator, one after another');
        check_same(1, $warm, 'the second view of the same boat cost ' . $warm . ' calls, and only the week\'s price can have changed');
    },

    'a shelf of boats does not read the catalogue once per boat' => static function (): void {
        $bench = Bench::of();

        $said = $bench->routes->yachtsCarousel($bench->week() + ['take' => 6], Caller::anonymous());

        check_same(true, $said->ok, 'the shelf did not load: ' . $said->said);
        check_same(6, count($said->body['items'] ?? []), 'the shelf is not showing six boats, so this check is measuring the wrong thing');

        // Every one of these is a row out of the options table on a real site,
        // fetched and unserialised. Reading the model list once per boat costs
        // nothing here and is invisible in the code, which is exactly why this
        // is a check and not a comment.
        check_true(
            $bench->cache->reads <= 8,
            'drawing six boats asked the cache ' . $bench->cache->reads . ' times',
        );

        check_true($bench->cache->reads > 0, 'the cache was never asked for anything, so nothing was measured');
    },

    'a blip on the way to the operator is not a broken page' => static function (): void {
        $bench = Bench::of();

        // The next two calls find nobody at the other end, and then the network
        // is back. A boat's page makes ten calls in a row, so one dropped
        // packet anywhere in that chain must not be a 502 for the visitor.
        $bench->manager->unreachableFor = 2;

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_same(
            true,
            $page->ok,
            'one blip on the way to the operator lost the whole page: ' . $page->said,
        );

        check_true(
            ($page->body['yacht']['name'] ?? '') !== '',
            'the page came back but with nothing on it, so surviving the blip means nothing',
        );
    },

    'the operator\'s own words are logged, not shown' => static function (): void {
        $bench = Bench::of();
        $bench->manager->unreachableFor = 30;

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_same(false, $page->ok, 'the operator was gone and the page loaded anyway, so nothing was tested');
        check_same(502, $page->status, 'an unreachable operator came back as ' . $page->status);

        check_not(
            $page->said,
            $page->logged,
            'the visitor is shown the same text the log gets',
        );

        check_true(
            !str_contains($page->said, 'invented manager') && !str_contains($page->said, 'attempts'),
            'what the visitor is shown carries the operator\'s own diagnostics: ' . $page->said,
        );

        check_true(
            str_contains($page->logged, 'attempts'),
            'the log was not told how the call failed, which is the half that has to be kept: ' . $page->logged,
        );
    },

    'the extras on a boat are named, not described' => static function (): void {
        $bench = Bench::of();

        $page = $bench->routes->yachtDetail(['yachtId' => 5001] + $bench->week(), Caller::anonymous());

        check_true(count($page->body['additionalEquipment']) > 0, 'the boat is offering no extras, so nothing was looked at');

        foreach ($page->body['additionalEquipment'] as $extra) {
            check_true(
                is_string($extra['name']) && $extra['name'] !== '',
                'an extra came back with ' . check_show($extra['name']) . ' where its name should be',
            );
        }
    },
];
