<?php

declare(strict_types=1);

/**
 * Runs the eight claims and prints what came out.
 *
 * Exits non-zero when one of them stops holding, so it is a check and not a
 * demonstration: the README quotes these figures, CI runs this and diffs the
 * output against the README, and a figure that stops being true stops the
 * build.
 */

require __DIR__ . '/../src/autoload.php';

use Charter\Claims;

echo PHP_EOL;
echo 'An invented operator, an invented fleet, and the ten routes measured against', PHP_EOL;
echo 'them: what the bridge guarantees, and the working for each figure.', PHP_EOL;

$claims = Claims::all();

foreach ($claims as $claim) {
    echo PHP_EOL;
    echo str_repeat('=', 78), PHP_EOL;
    echo $claim->holds ? 'HOLDS   ' : 'BROKEN  ', $claim->title, PHP_EOL;
    echo str_repeat('=', 78), PHP_EOL, PHP_EOL;

    foreach ($claim->lines as $block) {
        foreach (explode("\n", $block) as $line) {
            echo $line === '' ? '' : '  ' . $line, PHP_EOL;
        }
    }
}

echo PHP_EOL;

$broken = array_values(array_filter($claims, static fn ($claim): bool => !$claim->holds));

if ($broken === []) {
    echo 'All ', count($claims), ' claims hold.', PHP_EOL;

    exit(0);
}

foreach ($broken as $claim) {
    echo 'BROKEN: ', $claim->title, PHP_EOL;
}

exit(1);
