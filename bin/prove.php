<?php

declare(strict_types=1);

/**
 * The checks.
 *
 *   php bin/prove.php
 *
 * One guarantee to a check, each one named as the sentence it holds the bridge
 * to. Exits non-zero when anything fails.
 *
 * Every one of these has been watched to fail: the way to read a check here is
 * to take the guarantee out of src/ and run this, which is what the README's
 * last table records having been done.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../test/harness.php';

foreach (array_slice($argv, 1) as $said) {
    fwrite(STDERR, 'Unknown argument: ' . $said . PHP_EOL);

    exit(2);
}

$files = glob(__DIR__ . '/../test/*.php') ?: [];
sort($files);

$checks = [];

foreach ($files as $file) {
    if (basename($file) === 'harness.php') {
        continue;
    }

    $found = require $file;

    if (!is_array($found)) {
        fwrite(STDERR, $file . ' did not return an array of checks.' . PHP_EOL);

        exit(2);
    }

    foreach ($found as $name => $check) {
        if (isset($checks[$name])) {
            fwrite(STDERR, 'Two checks are called "' . $name . '".' . PHP_EOL);

            exit(2);
        }

        $checks[$name] = $check;
    }
}

if ($checks === []) {
    fwrite(STDERR, 'No checks were found, so nothing was run.' . PHP_EOL);

    exit(2);
}

$failures = [];
$began = microtime(true);

echo PHP_EOL, 'What the bridge guarantees, and whether it still does.', PHP_EOL, PHP_EOL;

foreach ($checks as $name => $check) {
    try {
        $check();
        $said = null;
    } catch (Failed $failed) {
        $said = $failed->getMessage();
    } catch (Throwable $threw) {
        $said = get_class($threw) . ': ' . $threw->getMessage()
            . ' (' . basename($threw->getFile()) . ':' . $threw->getLine() . ')';
    }

    if ($said !== null) {
        $failures[$name] = $said;
    }

    echo $said === null ? '  ok    ' : '  FAIL  ', $name, PHP_EOL;

    if ($said !== null) {
        echo '        ', $said, PHP_EOL;
    }
}

$took = round((microtime(true) - $began) * 1000);

echo PHP_EOL;
echo count($checks), ' checks, ', count($failures), ' failed, ', $took, ' ms', PHP_EOL;
echo PHP_EOL;

exit($failures === [] ? 0 : 1);
