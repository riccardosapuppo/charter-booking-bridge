<?php

declare(strict_types=1);

namespace Charter;

/**
 * A whole site in one object: an invented manager, an empty cache, a clock that
 * can be moved, somewhere for the customer's message to go, and the bridge
 * wired to all four.
 *
 * Both the checks and the measurement stand on this, and neither of them
 * reaches past it: everything they count — calls to the operator, records left
 * behind, reads of the cache — is counted on these objects, in memory, with no
 * network and nothing installed.
 */
final class Bench
{
    public readonly Routes $routes;

    private function __construct(
        public readonly InventedManager $manager,
        public readonly MemoryCache $cache,
        public readonly Clock $clock,
        public readonly KeptNotes $notes,
    ) {
        $this->routes = new Bridge(new Manager($manager), $cache, $clock, Fleet::OPERATOR, $notes);
    }

    /**
     * The next page load: a new bridge, the same transients, the same operator
     * and the same clock.
     *
     * PHP builds the whole world again on every request, so whatever a route
     * remembered inside itself during one page view is gone by the next one and
     * the cache is all that is left. A check that counts calls across page
     * views and reuses one bridge is a check about a memo in memory: it would
     * go on passing with the cache taken out altogether. So every such check
     * asks for one of these.
     */
    public function nextRequest(): Routes
    {
        return new Bridge(new Manager($this->manager), $this->cache, $this->clock, Fleet::OPERATOR, $this->notes);
    }

    public static function of(?InventedManager $manager = null): self
    {
        $manager ??= new InventedManager();

        // The one place the bridge reads the account from. A check that rotates
        // the account rotates it here, and then counts who noticed.
        putenv(Secrets::USERNAME . '=' . InventedManager::USERNAME);
        putenv(Secrets::PASSWORD . '=' . InventedManager::PASSWORD);

        $clock = new Clock();
        // A Wednesday in April, so that "the next Saturday" is three days off
        // and every figure in the README is the same figure tomorrow.
        $clock->pin((int) gmmktime(9, 0, 0, 4, 15, 2026));

        return new self($manager, new MemoryCache($clock), $clock, new KeptNotes());
    }

    /**
     * A week that is a real week, three months out, which every check that is
     * not about dates uses so that it is about something else.
     *
     * @return array{periodFrom:string,periodTo:string}
     */
    public function week(): array
    {
        $saturday = Dates::nextWeek($this->clock->now());

        return ['periodFrom' => $saturday->from, 'periodTo' => $saturday->to];
    }

    /**
     * A complete, invented customer. Nobody's: the address is a road that does
     * not exist, and the domain is one the standards reserve so that it can
     * never be delivered to.
     *
     * @return array<string,mixed>
     */
    public static function someone(): array
    {
        return [
            'name' => 'Giulia',
            'surname' => 'Verdi',
            'email' => 'giulia.verdi@example.invalid',
            'phone' => '+39 000 0000000',
            'address' => 'Via Inventata 1',
            'zip' => '00100',
            'city' => 'Porto Finto',
            'countryId' => 501,
            'message' => 'We are bringing a two-year-old, we will need a cot.',
        ];
    }
}
