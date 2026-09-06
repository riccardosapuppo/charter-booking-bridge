<?php

declare(strict_types=1);

namespace Charter;

/**
 * A cache for the tests and for a single request. The WordPress half hands the
 * bridge a transient-backed one instead; see wordpress/charter-bridge.
 */
final class MemoryCache implements Cache
{
    /** @var array<string,array{value:array<mixed>,until:int}> */
    private array $held = [];

    /**
     * How many times it has been asked for something.
     *
     * On a real site every one of these is a row out of the options table, so a
     * page that reads a catalogue once per boat is a page that does not look
     * expensive and is.
     */
    public int $reads = 0;

    public function __construct(private Clock $clock = new Clock())
    {
    }

    public function get(string $key): ?array
    {
        $this->reads++;

        $entry = $this->held[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['until'] <= $this->clock->now()) {
            unset($this->held[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function put(string $key, array $value, int $seconds): void
    {
        $this->held[$key] = ['value' => $value, 'until' => $this->clock->now() + $seconds];
    }
}
