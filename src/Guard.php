<?php

declare(strict_types=1);

namespace Charter;

/**
 * The doorway the two writing routes go through.
 *
 * Nine of the ten routes read a catalogue, and open is the right answer for
 * them. The tenth walks a request through the operator's three booking steps
 * and comes back with a confirmed reservation, and that one is behind this.
 *
 * The rate limit is not the interesting half. The interesting half is that a
 * booking must have been composed on a page we served — that is what
 * {@see Caller::fromOurPages()} means, and it is about the request carrying a
 * nonce rather than about anybody signing in, because a booking form is meant
 * to be public. Five bookings an hour from one address is generous for a family
 * choosing a holiday and useless to a script.
 */
final class Guard
{
    public function __construct(
        private readonly Cache $cache,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Null when the caller may go on; a refusal when they may not.
     *
     * The refusal to an anonymous poster is deliberately the same shape as the
     * refusal to somebody who has asked too often. Neither says which of the
     * two it was, because the difference is only useful to whoever is probing.
     */
    public function admit(string $action, Caller $who, int $times, int $seconds): ?Answer
    {
        if (!$who->fromOurPages) {
            return Answer::refused(
                403,
                'not_from_our_pages',
                'This request did not come from the booking form. Reload the page and try again.',
                'A ' . $action . ' arrived with no valid nonce from ' . $who->address . '.',
            );
        }

        return $this->rate($action, $who, $times, $seconds);
    }

    /**
     * The counting half on its own, for a route that is meant to be open but
     * not unlimited.
     */
    public function rate(string $action, Caller $who, int $times, int $seconds): ?Answer
    {
        $key = 'rate:' . $action . ':' . $who->address;
        $window = $this->cache->get($key);
        $now = $this->clock->now();

        if ($window === null || ($window['until'] ?? 0) <= $now) {
            $window = ['count' => 0, 'until' => $now + $seconds];
        }

        $window['count'] = (int) $window['count'] + 1;

        $this->cache->put($key, $window, max(1, (int) $window['until'] - $now));

        if ($window['count'] > $times) {
            return Answer::refused(
                429,
                'too_many',
                'Too many attempts. Wait a minute and try again.',
                $who->address . ' has made ' . $window['count'] . ' ' . $action
                    . ' attempts in a window of ' . $seconds . ' seconds.',
            );
        }

        return null;
    }
}
