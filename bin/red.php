<?php

declare(strict_types=1);

/**
 * Runs the same checks against the code as it was, and requires them to fail.
 *
 * This is the part of the repository that is worth reading first. A check that
 * has never been seen to fail is a claim about a check, and a suite of green
 * ticks says nothing at all about whether anything was ever wrong. So the
 * original is kept runnable, the checks are written against an interface both
 * sides implement, and this command points them at the wrong side and insists.
 *
 * The table below is the record: which check goes red, and a phrase out of what
 * it says when it does. If a repair stops being a repair, its check goes green
 * here and this command fails.
 */

require __DIR__ . '/../src/autoload.php';

/**
 * check => a phrase its failure message must contain.
 */
const MUST_FAIL = [
    'the route that books has a door on it'
        => 'a POST from nowhere, with no nonce and no session, was accepted',
    'dates that are not dates do not reach the operator'
        => 'was accepted',
    'a week that has gone is not sold twice'
        => 'the same week on the same boat was booked twice',
    'the customer the operator receives is the one who filled the form'
        => 'a booking with no customer in it was accepted',
    'what the customer wrote is not thrown away'
        => 'went nowhere at all',
    'a country nobody has heard of is not accepted'
        => 'a country the operator does not have',
    'guessing promo codes does not fill the operator\'s system'
        => 'calls to the endpoint that opens a reservation',
    'the total on the screen is the total that gets booked'
        => 'the page showed',
    'an extra the boat does not offer is refused'
        => 'an extra that this boat does not have',
    'changing the account in one place changes it everywhere'
        => 'talking to the operator with the old account',
    'a boat\'s fittings are grouped under the categories they belong to'
        => 'instead of the three categories they are in',
    'a catalogue that has answered is not asked again'
        => 'an empty list cannot be told from an empty cache',
    'a boat keeps its own kind'
        => 'the variable holding its kind was reused inside the loop',
    'a boat\'s page does not cost eleven calls, and the second one costs one'
        => 'calls to the operator, one after another',
    'the extras on a boat are named, not described'
        => 'where its name should be',
    'the discounted shelf shows the discounted boats'
        => 'discounted boats has 0 on it',
    'a blip on the way to the operator is not a broken page'
        => 'one blip on the way to the operator lost the whole page',
];

$command = escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(__DIR__ . '/prove.php') . ' --bridge=as-it-was --json';

$printed = (string) shell_exec($command . ' 2>&1');
$said = json_decode($printed, true);

if (!is_array($said) || !isset($said['failures'], $said['checks'])) {
    fwrite(STDERR, 'The suite did not answer with a result:' . PHP_EOL . $printed . PHP_EOL);

    exit(2);
}

$failures = $said['failures'];
$wrong = 0;

echo PHP_EOL, 'The same checks, run against the code as it was.', PHP_EOL, PHP_EOL;

foreach (MUST_FAIL as $check => $phrase) {
    if (!in_array($check, $said['checks'], true)) {
        echo '  GONE  ', $check, PHP_EOL;
        echo '        there is no such check any more, so nothing was demonstrated', PHP_EOL;
        $wrong++;

        continue;
    }

    if (!isset($failures[$check])) {
        echo '  GREEN ', $check, PHP_EOL;
        echo '        passed against the original, so it is not about the repair', PHP_EOL;
        $wrong++;

        continue;
    }

    if (!str_contains($failures[$check], $phrase)) {
        echo '  OTHER ', $check, PHP_EOL;
        echo '        failed, but for another reason: ', $failures[$check], PHP_EOL;
        $wrong++;

        continue;
    }

    echo '  red   ', $check, PHP_EOL;
    echo '        ', $failures[$check], PHP_EOL;
}

$unexpected = array_diff(array_keys($failures), array_keys(MUST_FAIL));

foreach ($unexpected as $check) {
    echo '  ?     ', $check, PHP_EOL;
    echo '        failed against the original and is not in the table: ', $failures[$check], PHP_EOL;
    $wrong++;
}

echo PHP_EOL;
echo count(MUST_FAIL), ' checks are meant to go red here, and ', count($failures), ' did.', PHP_EOL;

if ($wrong === 0) {
    echo 'Every repair in this repository is one somebody can watch fail.', PHP_EOL, PHP_EOL;

    exit(0);
}

echo $wrong, ' did not behave as the table says.', PHP_EOL, PHP_EOL;

exit(1);
