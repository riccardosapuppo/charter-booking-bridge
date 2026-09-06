<?php

declare(strict_types=1);

/**
 * The checks.
 *
 *   php bin/prove.php                      the bridge
 *   php bin/prove.php --bridge=as-it-was   the code as it was
 *   php bin/prove.php --json               for bin/red.php
 *
 * Exits non-zero when anything fails, which is the ordinary way round; the
 * other way round is bin/red.php, which runs this against the original and
 * requires the demonstrations to fail.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../test/harness.php';

$which = 'repaired';
$asJson = false;

foreach (array_slice($argv, 1) as $said) {
    if (str_starts_with($said, '--bridge=')) {
        $which = substr($said, strlen('--bridge='));
    } elseif ($said === '--json') {
        $asJson = true;
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $said . PHP_EOL);

        exit(2);
    }
}

if (!in_array($which, Charter\Bench::both(), true)) {
    fwrite(STDERR, '--bridge must be one of: ' . implode(', ', Charter\Bench::both()) . PHP_EOL);

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

if (!$asJson) {
    echo PHP_EOL, 'Checking the bridge: ', $which, PHP_EOL, PHP_EOL;
}

foreach ($checks as $name => $check) {
    try {
        $check($which);
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

    if (!$asJson) {
        echo $said === null ? '  ok    ' : '  FAIL  ', $name, PHP_EOL;

        if ($said !== null) {
            echo '        ', $said, PHP_EOL;
        }
    }
}

$took = round((microtime(true) - $began) * 1000);

if ($asJson) {
    echo json_encode([
        'bridge' => $which,
        'checks' => array_keys($checks),
        'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit($failures === [] ? 0 : 1);
}

echo PHP_EOL;
echo count($checks), ' checks, ', count($failures), ' failed, ', $took, ' ms', PHP_EOL;
echo PHP_EOL;

exit($failures === [] ? 0 : 1);
