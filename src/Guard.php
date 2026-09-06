<?php

declare(strict_types=1);

namespace Charter;

/**
 * The doorway the two writing routes go through.
 *
 * There was no doorway. Ten routes, ten `permission_callback => '__return_true'`,
 * and in the whole file not one `wp_verify_nonce`, not one `current_user_can`,
 * and nothing counting anything per caller. Nine of those ten read a catalogue,
 * and open is the right answer for them. The tenth walked a request through the
 * manager's three booking steps and came back with a confirmed reservation.
 *
 * A rate limit is not the interesting half. The interesting half is that a
 * booking must have been composed on a page we served: that is one line, it was
 * absent, and an absent line is invisible.
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
