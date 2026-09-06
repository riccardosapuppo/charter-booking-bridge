<?php

declare(strict_types=1);

namespace Charter;

/**
 * The one way out of this process.
 *
 * The call to the network is behind a name, which is what lets the whole suite
 * run against an invented operator in memory — no WordPress, no account, no
 * network — and what lets a check count how many times the operator was rung
 * up to draw one page.
 */
interface Transport
{
    /**
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed> the decoded answer
     *
     * @throws Unreachable when the manager could not be reached at all
     */
    public function send(string $method, string $path, ?array $body): array;
}
