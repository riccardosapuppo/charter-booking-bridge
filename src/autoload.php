<?php

declare(strict_types=1);

/**
 * One class per file, under the Charter namespace, in this directory.
 *
 * There is no Composer here on purpose. Everything in src/ is plain PHP with
 * no library behind it, so the suite runs on a checkout with nothing
 * installed: `php bin/prove.php`, about a second, no network, no database.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Charter\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
