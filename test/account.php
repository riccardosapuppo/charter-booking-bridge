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
 * Everybody's first note about this file is that the password is in clear, and
 * everybody is right, and it is the least interesting thing about it. What
 * matters is the arithmetic: it was written out nine times, so changing it is
 * nine coordinated edits, and the ninth is the one that gets missed. Half the
 * site then keeps working.
 */
return [
    'changing the account in one place changes it everywhere' => static function (string $which): void {
        // The operator issues a new password. It is set in the one place the
        // site reads it from, and nothing else is touched.
        $rotated = new InventedManager('rotated-account@invented', 'rotated-password');
        $bench = Bench::of($which, $rotated);

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

            $stillWorking = [];
            $broken = [];

            foreach ($routes as $name => $call) {
                if ($call()) {
                    $stillWorking[] = $name;
                } else {
                    $broken[] = $name;
                }
            }

            check_same(
                count($routes),
                count($stillWorking),
                'after one password change, ' . count($broken) . ' of ' . count($routes)
                    . ' routes are talking to the operator with the old account: ' . implode(', ', $broken),
            );
        } finally {
            putenv(Secrets::USERNAME . '=' . InventedManager::USERNAME);
            putenv(Secrets::PASSWORD . '=' . InventedManager::PASSWORD);
        }
    },

    'the bridge holds no account of its own' => static function (string $which): void {
        // A check about the shape of the code rather than what it does, so it
        // says the same thing whichever bridge is being run: the repaired one
        // has no literal account anywhere, and the one it replaced has nine.
        $root = check_root();

        $asItWas = file_get_contents($root . '/src/TheWayItWas.php');

        check_true(is_string($asItWas), 'TheWayItWas.php could not be read, so nothing was counted');

        check_same(
            9,
            preg_match_all('/\$MANAGER_USER\s*=\s*\'/', (string) $asItWas),
            'the original is on record as having nine copies of the account, and this file has a different number',
        );

        foreach (['src/Bridge.php', 'src/Manager.php', 'src/Secrets.php', 'src/Catalogue.php'] as $file) {
            $text = (string) file_get_contents($root . '/' . $file);

            check_same(
                0,
                preg_match_all('/\$MANAGER_(USER|PASS)\s*=\s*\'/', $text),
                $file . ' has an account written into it',
            );
        }

        // And the one place that does read it, reads it from the environment.
        $secrets = (string) file_get_contents($root . '/src/Secrets.php');

        check_same(1, preg_match_all('/getenv\(/', $secrets), 'the account is read from the environment in a number of places other than one');
    },
];
