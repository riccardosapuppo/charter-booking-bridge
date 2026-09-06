<?php

declare(strict_types=1);

/**
 * The whole of the test framework, which is four functions and an exception.
 *
 * A check is a name and a closure. The closure is handed the name of the bridge
 * to build — 'repaired' or 'as-it-was' — and fails by throwing. That is enough
 * for a repository this size, and it means the suite runs on a checkout with
 * nothing installed, which is the point: `php bin/prove.php`, one second, no
 * network, no database, no WordPress.
 */

final class Failed extends RuntimeException
{
}

function check_true(mixed $said, string $why): void
{
    if ($said !== true) {
        throw new Failed($why);
    }
}

function check_same(mixed $ought, mixed $said, string $why): void
{
    if ($ought !== $said) {
        throw new Failed($why . ' — expected ' . check_show($ought) . ', got ' . check_show($said));
    }
}

function check_not(mixed $ought, mixed $said, string $why): void
{
    if ($ought === $said) {
        throw new Failed($why . ' — got ' . check_show($said) . ', which is what it must not be');
    }
}

function check_near(float $ought, float $said, string $why): void
{
    if (abs($ought - $said) > 0.005) {
        throw new Failed($why . ' — expected ' . number_format($ought, 2) . ', got ' . number_format($said, 2));
    }
}

function check_show(mixed $value): string
{
    if (is_string($value)) {
        return '"' . $value . '"';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if ($value === null) {
        return 'null';
    }

    if (is_array($value)) {
        $json = json_encode($value);

        return strlen((string) $json) > 200 ? substr((string) $json, 0, 200) . '…' : (string) $json;
    }

    return (string) $value;
}

/**
 * Every file in the repository that git would carry, so that the checks about
 * the repository are about the repository and not about whatever is lying in
 * the working directory.
 *
 * @return list<string> paths relative to the root
 */
function check_tracked_files(): array
{
    $root = dirname(__DIR__);
    $listed = [];

    exec('git -C ' . escapeshellarg($root) . ' ls-files 2>&1', $listed, $how);

    if ($how !== 0 || $listed === []) {
        // Before the first commit there is nothing to list, so fall back to
        // walking the tree. A check that quietly examines nothing is worse
        // than one that fails.
        $listed = [];
        $walk = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $one): bool => $one->getFilename() !== '.git',
            ),
        );

        foreach ($walk as $one) {
            if ($one->isFile()) {
                $listed[] = str_replace('\\', '/', substr($one->getPathname(), strlen($root) + 1));
            }
        }
    }

    return array_values(array_filter($listed, static fn (string $one): bool => $one !== ''));
}

function check_root(): string
{
    return dirname(__DIR__);
}

/**
 * What `php bin/measure.php` prints. Run once and remembered, because two of
 * the checks above want it.
 */
function check_measurement(): string
{
    static $printed = null;

    if ($printed === null) {
        $printed = (string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(check_root() . '/bin/measure.php') . ' 2>&1',
        );
    }

    return $printed;
}
