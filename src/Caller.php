<?php

declare(strict_types=1);

namespace Charter;

/**
 * Who is asking.
 *
 * Nine of the ten routes read a catalogue and can stay open. One of them
 * commits a week of the operator's boat, and it is the whole reason this type
 * exists: the route that books is handed a caller, and it asks it two
 * questions before it does anything at all.
 */
final class Caller
{
    public function __construct(
        public readonly string $address = '203.0.113.7',
        public readonly bool $fromOurPages = false,
    ) {
    }

    /**
     * A visitor on the site's own pages: WordPress minted them a nonce, and it
     * came back with the form.
     *
     * The name says what it means rather than what it is made of. A nonce
     * proves the request was composed by a page we served, not that anybody
     * signed in — and a booking form open to the public is meant to be open to
     * the public. What it stops is the other thing: a script somewhere else
     * posting straight at the endpoint.
     */
    public static function fromOurPages(string $address = '203.0.113.7'): self
    {
        return new self($address, true);
    }

    public static function anonymous(string $address = '198.51.100.42'): self
    {
        return new self($address, false);
    }
}
