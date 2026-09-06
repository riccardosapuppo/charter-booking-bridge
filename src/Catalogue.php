<?php

declare(strict_types=1);

namespace Charter;

/**
 * Every slow list the operator keeps, read in one place.
 *
 * One function, one table of endpoints and response keys, and two properties
 * worth stating plainly because the checks in test/ assert both:
 *
 * A list is asked for once and then kept. Ten views of a boat's page cost one
 * call for the equipment categories, not ten, and the second view of a boat
 * costs one call in total — the week's price, which is the only thing that can
 * have changed.
 *
 * And a list the operator answers with nothing is remembered as nothing. "There
 * and empty" and "never asked" are different facts, so the guard below is
 * `=== null` and not `empty()`: an empty shelf is worth keeping for twelve
 * hours, not re-fetching on every page view of the site's life.
 *
 * Adding an eleventh list is a row in the table, not a copy of a function.
 */
final class Catalogue
{
    /**
     * endpoint, the key its answer holds the list under, and how long the list
     * is worth keeping.
     *
     * @var array<string,array{path:string,key:string,hours:int}>
     */
    private const SHELVES = [
        'equipment' => ['path' => '/catalogue/v6/equipment', 'key' => 'equipment', 'hours' => 12],
        'equipmentCategories' => ['path' => '/catalogue/v6/equipmentCategories', 'key' => 'equipmentCategories', 'hours' => 12],
        'services' => ['path' => '/catalogue/v6/services', 'key' => 'services', 'hours' => 12],
        'bases' => ['path' => '/catalogue/v6/charterBases', 'key' => 'bases', 'hours' => 12],
        'locations' => ['path' => '/catalogue/v6/locations', 'key' => 'locations', 'hours' => 12],
        'builders' => ['path' => '/catalogue/v6/yachtBuilders', 'key' => 'builders', 'hours' => 12],
        'yachtCategories' => ['path' => '/catalogue/v6/yachtCategories', 'key' => 'categories', 'hours' => 12],
        'seasons' => ['path' => '/catalogue/v6/seasons', 'key' => 'seasons', 'hours' => 12],
        'models' => ['path' => '/catalogue/v6/yachtModels', 'key' => 'models', 'hours' => 12],
        'countries' => ['path' => '/catalogue/v6/countries', 'key' => 'countries', 'hours' => 24],
    ];

    /**
     * What has already been read during this request.
     *
     * Without it, drawing a shelf of twelve boats asks the cache for the model
     * list twelve times, and on a real site every one of those is a row out of
     * the options table, unserialised again. It does not look expensive in the
     * code, which is why there is a check that counts the reads.
     *
     * @var array<string,array<mixed>>
     */
    private array $thisRequest = [];

    public function __construct(
        private readonly Manager $manager,
        private readonly Cache $cache,
    ) {
    }

    /**
     * A whole list, keyed by id.
     *
     * @return array<string,array<string,mixed>>
     */
    public function map(string $shelf): array
    {
        $of = self::SHELVES[$shelf] ?? throw new \InvalidArgumentException('No such catalogue: ' . $shelf);

        return $this->keep('catalogue:' . $shelf, $of['hours'] * 3600, function () use ($of): array {
            return self::byId($this->manager->catalogue($of['path'])[$of['key']] ?? []);
        });
    }

    /**
     * The same list reduced to id => name, which is what most of the pages want.
     *
     * @return array<string,string>
     */
    public function names(string $shelf): array
    {
        $names = [];

        foreach ($this->map($shelf) as $id => $entry) {
            $names[$id] = Text::of($entry['name'] ?? '');
        }

        return $names;
    }

    /**
     * One operator's fleet, keyed by id. Its own shelf because the endpoint
     * takes the operator in the path, so the cache key has to as well.
     *
     * @return array<string,array<string,mixed>>
     */
    public function fleet(int $operator): array
    {
        return $this->keep('catalogue:fleet:' . $operator, 6 * 3600, function () use ($operator): array {
            return self::byId($this->manager->catalogue('/catalogue/v6/yachts/' . $operator)['yachts'] ?? []);
        });
    }

    /**
     * One boat's catalogue entry.
     *
     * Catalogue, and kept like the rest of it: a boat changes when somebody
     * edits the boat, which is not during a page view.
     *
     * @return array<string,mixed>|null
     */
    public function yacht(int $id): ?array
    {
        $held = $this->keep('catalogue:yacht:' . $id, 6 * 3600, function () use ($id): array {
            $said = $this->manager->catalogue('/catalogue/v6/yacht/' . $id)['yachts'][0] ?? null;

            return is_array($said) ? $said : [];
        });

        return $held === [] ? null : $held;
    }

    /**
     * Read it, or fetch it and keep it.
     *
     * `=== null` and not `empty()`: a list the operator legitimately answers
     * with nothing is a fact worth keeping for twelve hours, not a reason to
     * ask again on the next page view and every page view after that.
     *
     * @param callable():array<mixed> $fetch
     *
     * @return array<mixed>
     */
    private function keep(string $key, int $seconds, callable $fetch): array
    {
        if (isset($this->thisRequest[$key])) {
            return $this->thisRequest[$key];
        }

        $held = $this->cache->get($key);

        if ($held !== null) {
            return $this->thisRequest[$key] = $held;
        }

        $fresh = $fetch();

        $this->cache->put($key, $fresh, $seconds);

        return $this->thisRequest[$key] = $fresh;
    }

    /**
     * @param mixed $items
     *
     * @return array<string,array<string,mixed>>
     */
    private static function byId(mixed $items): array
    {
        $map = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && isset($item['id'])) {
                $map[(string) $item['id']] = $item;
            }
        }

        return $map;
    }
}
