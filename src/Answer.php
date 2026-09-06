<?php

declare(strict_types=1);

namespace Charter;

/**
 * What a route hands back: either a body, or a refusal with an HTTP status and
 * a code the front end can branch on.
 *
 * The refusal carries two texts, and that is the point of the class. `said` is
 * what the visitor is shown. `logged` is what the site's owner needs and the
 * visitor must not have — the operator's own diagnostics among it, which are
 * written for whoever runs the booking system and not for whoever happens to
 * have triggered an error on a public page.
 */
final class Answer
{
    /**
     * @param array<string,mixed> $body
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly string $code,
        public readonly array $body,
        public readonly string $said,
        public readonly string $logged,
    ) {
    }

    /**
     * @param array<string,mixed> $body
     */
    public static function ok(array $body): self
    {
        return new self(true, 200, '', $body, '', '');
    }

    public static function refused(int $status, string $code, string $said, string $logged = ''): self
    {
        return new self(false, $status, $code, [], $said, $logged === '' ? $said : $logged);
    }
}
