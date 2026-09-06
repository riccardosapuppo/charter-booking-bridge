<?php

declare(strict_types=1);

namespace Charter;

/**
 * The one way out of this process.
 *
 * The original had `wp_remote_request` written into the middle of the client,
 * so nothing about the bridge could be run without WordPress and a network.
 * Here the call is behind a name, which is what lets the whole suite run
 * against an invented manager in memory — and what lets a test count how many
 * times the manager was rung up for one page.
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
