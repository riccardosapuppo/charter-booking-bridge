<?php

declare(strict_types=1);

namespace Charter;

/**
 * The one place the account is read.
 *
 * In the original there were nine of these, one inside each route handler,
 * each a pair of string literals under a comment that said, in Italian, "CONFIG
 * (IN CLEAR)". Nine, not the eight everybody counted — and the difference is
 * not pedantry. It is the number of edits a password change takes, and the
 * ninth is the one that gets forgotten, so half the site keeps working and the
 * other half fails in a way nobody can reproduce.
 *
 * Here there is one read, from the environment, and the tests can prove it:
 * change the environment, and every route that talks to the manager sends the
 * new account. Nine of nine. On the code as it was, nought of nine.
 *
 * Nothing in this repository holds a value. `.env.example` gives the two names
 * and no values, because the names are the part that is worth publishing.
 */
final class Secrets
{
    public const USERNAME = 'CHARTER_MANAGER_USERNAME';
    public const PASSWORD = 'CHARTER_MANAGER_PASSWORD';

    /**
     * The account, in the shape the catalogue side of the manager wants: the
     * two fields at the top level of the request body.
     *
     * This is not a header. The manager takes the account inside the JSON, and
     * in two different shapes depending on which family of endpoints is being
     * asked — which is why both shapes live here, in one file, rather than
     * being remembered at each of the nineteen call sites.
     *
     * @return array{username:string,password:string}
     */
    public static function flat(): array
    {
        return ['username' => self::read(self::USERNAME), 'password' => self::read(self::PASSWORD)];
    }

    /**
     * The same account nested under `credentials`, which is the shape the
     * booking and availability side wants.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    public static function nested(array $body): array
    {
        return ['credentials' => self::flat()] + $body;
    }

    private static function read(string $name): string
    {
        $value = getenv($name);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException(
                $name . ' is not set. The bridge has no account to reach the manager with. '
                . 'See .env.example for the two names it reads.',
            );
        }

        return $value;
    }
}
