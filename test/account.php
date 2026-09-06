<?php

declare(strict_types=1);

use Charter\Bench;
use Charter\Caller;
use Charter\Fleet;
use Charter\InventedManager;
use Charter\Secrets;

/**
 * The account the bridge reaches the operator with.
 *
 * One place, and that place is the environment. The arithmetic is what makes it
 * worth asserting: a rotation should be one edit, and the way to know it is one
 * edit is to make it and then count who noticed.
 */
return [
    'changing the account in one place changes it everywhere' => static function (): void {
        // The operator issues a new password. It is set in the one place the
        // site reads it from, and nothing else is touched.
        $rotated = new InventedManager('rotated-account@invented', 'rotated-password');
        $bench = Bench::of($rotated);

        putenv(Secrets::USERNAME . '=rotated-account@invented');
        putenv(Secrets::PASSWORD . '=rotated-password');

        try {
            $week = $bench->week();

            $routes = [
                'the carousel' => static fn (): bool => $bench->routes->yachtsCarousel($week, Caller::anonymous())->ok,
                'a boat\'s page' => static fn (): bool => $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous())->ok,
                'the search' => static fn (): bool => $bench->routes->freeYachtsSearch($week, Caller::anonymous())->ok,
                'the country list' => static fn (): bool => $bench->routes->countries([], Caller::anonymous())->ok,
                'the location list' => static fn (): bool => $bench->routes->locations([], Caller::anonymous())->ok,
                'the category list' => static fn (): bool => $bench->routes->yachtCategories([], Caller::anonymous())->ok,
                'the most searched shelf' => static fn (): bool => $bench->routes->yachtsMostSearched($week, Caller::anonymous())->ok,
                'the promo check' => static fn (): bool => $bench->routes->yachtPromo(['yachtId' => 5001, 'promoCode' => Fleet::PROMO] + $week, Caller::fromOurPages())->ok,
                'the booking' => static fn (): bool => $bench->routes->yachtRequest(['yachtId' => 5001] + $week + Bench::someone(), Caller::fromOurPages())->ok,
            ];

            $broken = [];

            foreach ($routes as $name => $call) {
                if (!$call()) {
                    $broken[] = $name;
                }
            }

            check_same(
                [],
                $broken,
                'after one password change, ' . count($broken) . ' of ' . count($routes)
                    . ' routes are talking to the operator with the old account: ' . implode(', ', $broken),
            );

            check_same(9, count($routes), 'this check no longer calls the nine routes that need an account');
        } finally {
            putenv(Secrets::USERNAME . '=' . InventedManager::USERNAME);
            putenv(Secrets::PASSWORD . '=' . InventedManager::PASSWORD);
        }
    },

    'with nothing in the environment, nothing reaches the operator' => static function (): void {
        // The other half, and the one that says "from one place" rather than
        // "from one place and also from wherever else somebody left a copy". A
        // route that still worked here would be reading an account that this
        // repository says it does not have.
        $bench = Bench::of();

        putenv(Secrets::USERNAME);
        putenv(Secrets::PASSWORD);

        try {
            $week = $bench->week();

            $routes = [
                'the carousel' => static fn (): bool => $bench->routes->yachtsCarousel($week, Caller::anonymous())->ok,
                'a boat\'s page' => static fn (): bool => $bench->routes->yachtDetail(['yachtId' => 5001] + $week, Caller::anonymous())->ok,
                'the search' => static fn (): bool => $bench->routes->freeYachtsSearch($week, Caller::anonymous())->ok,
                'the country list' => static fn (): bool => $bench->routes->countries([], Caller::anonymous())->ok,
                'the location list' => static fn (): bool => $bench->routes->locations([], Caller::anonymous())->ok,
                'the category list' => static fn (): bool => $bench->routes->yachtCategories([], Caller::anonymous())->ok,
                'the most searched shelf' => static fn (): bool => $bench->routes->yachtsMostSearched($week, Caller::anonymous())->ok,
                'the promo check' => static fn (): bool => $bench->routes->yachtPromo(['yachtId' => 5001, 'promoCode' => Fleet::PROMO] + $week, Caller::fromOurPages())->ok,
                'the booking' => static fn (): bool => $bench->routes->yachtRequest(['yachtId' => 5001] + $week + Bench::someone(), Caller::fromOurPages())->ok,
            ];

            $stillWorking = [];
            $said = '';

            foreach ($routes as $name => $call) {
                try {
                    if ($call()) {
                        $stillWorking[] = $name;
                    }
                } catch (Throwable $threw) {
                    // Refusing to run without an account is the answer this is
                    // looking for, and the message is the one a reader gets.
                    $said = $threw->getMessage();
                }
            }

            check_same(
                [],
                $stillWorking,
                'with no account in the environment, ' . count($stillWorking) . ' routes still reached the operator: '
                    . implode(', ', $stillWorking),
            );

            check_true(
                str_contains($said, Secrets::USERNAME),
                'nothing said which variable was missing, so whoever hits this has nothing to go on: ' . check_show($said),
            );
        } finally {
            putenv(Secrets::USERNAME . '=' . InventedManager::USERNAME);
            putenv(Secrets::PASSWORD . '=' . InventedManager::PASSWORD);
        }
    },

    'the account is read from the environment, in one place' => static function (): void {
        // A check about the shape of the code rather than about what it does,
        // because "one place" is a claim about the file and not about a run.
        $root = check_root();
        $reads = [];
        $literal = [];
        $looked = 0;

        foreach (glob($root . '/src/*.php') ?: [] as $file) {
            // The invented operator holds the account it checks against, which
            // is the one account this repository is allowed to contain: it
            // belongs to a booking system that exists nowhere.
            if (basename($file) === 'InventedManager.php') {
                continue;
            }

            $text = (string) file_get_contents($file);
            $looked++;

            if (preg_match_all('/getenv\s*\(/', $text) > 0) {
                $reads[] = basename($file);
            }

            // A credential assigned a literal, anywhere, under any of the names
            // one goes by. The two constants in Secrets hold the NAMES of the
            // environment variables, which is a different thing and the only
            // thing allowed through: a value in SHOUTING_CASE is a variable
            // name, not a password.
            if (preg_match_all('/(?:username|password|user|pass)\w*\s*(?:=>|=)\s*\'([^\']{4,})\'/i', $text, $said) > 0) {
                foreach ($said[1] as $number => $value) {
                    if (preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1) {
                        continue;
                    }

                    $literal[] = basename($file) . ': ' . $said[0][$number];
                }
            }
        }

        check_true($looked >= 20, 'only ' . $looked . ' files were read, so this check looked almost nowhere');

        check_same(
            ['Secrets.php'],
            $reads,
            'the account is read from the environment in ' . count($reads) . ' files: ' . implode(', ', $reads),
        );

        check_same(
            1,
            preg_match_all('/getenv\s*\(/', (string) file_get_contents($root . '/src/Secrets.php')),
            'Secrets.php reads the environment in a number of places other than one',
        );

        check_same([], $literal, 'an account is written into the code: ' . implode(' | ', $literal));

        // And .env.example gives the names and no values, which is the part of
        // an account that belongs in a public repository.
        $example = (string) file_get_contents($root . '/.env.example');

        foreach ([Secrets::USERNAME, Secrets::PASSWORD] as $name) {
            check_true(
                str_contains($example, $name . '='),
                '.env.example does not name ' . $name . ', so nobody is told what to set',
            );

            check_same(
                1,
                preg_match('/^' . preg_quote($name, '/') . '=\s*$/m', $example),
                '.env.example gives ' . $name . ' a value, and it is supposed to give only the name',
            );
        }
    },
];
