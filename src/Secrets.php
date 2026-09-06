<?php

declare(strict_types=1);

namespace Charter;

/**
 * The one place the account is read.
 *
 * One read, from the environment, and every route that talks to the operator
 * comes through here for it. That is a property a check can hold the code to:
 * change the two variables, call all nine routes that need an account, and all
 * nine of them talk to the operator with the new one. A rotation is one edit.
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
     * being remembered at every call site.
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
