<?php

declare(strict_types=1);

namespace Charter;

/**
 * A whole site in one object: an invented manager, an empty cache, a clock that
 * can be moved, and one of the two bridges wired to them.
 *
 * Both the checks and the measurement stand on this. `Bench::of('repaired')`
 * and `Bench::of('as-it-was')` differ in exactly one line, which is what makes
 * it possible to run the same checks against both and require the second to
 * fail.
 */
final class Bench
{
    public readonly Routes $routes;

    private function __construct(
        public readonly InventedManager $manager,
        public readonly MemoryCache $cache,
        public readonly Clock $clock,
        public readonly KeptNotes $notes,
        public readonly string $which,
    ) {
        $this->routes = $which === 'as-it-was'
            ? new TheWayItWas($manager, $cache, $clock)
            : new Bridge(new Manager($manager), $cache, $clock, Fleet::OPERATOR, $notes);
    }

    /**
     * @param string $which 'repaired' or 'as-it-was'
     */
    public static function of(string $which, ?InventedManager $manager = null): self
    {
        $manager ??= new InventedManager();

        // The one place the bridge reads the account from. A test that rotates
        // the account rotates it here, and then counts who noticed.
        putenv(Secrets::USERNAME . '=' . InventedManager::USERNAME);
        putenv(Secrets::PASSWORD . '=' . InventedManager::PASSWORD);

        $clock = new Clock();
        // A Wednesday in April, so that "the next Saturday" is three days off
        // and every figure in the README is the same figure tomorrow.
        $clock->pin((int) gmmktime(9, 0, 0, 4, 15, 2026));

        return new self($manager, new MemoryCache($clock), $clock, new KeptNotes(), $which);
    }

    /** The two names a bench can have. */
    public static function both(): array
    {
        return ['repaired', 'as-it-was'];
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
