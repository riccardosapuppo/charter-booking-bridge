<?php

declare(strict_types=1);

/**
 * Plugin Name: Charter Booking Bridge
 * Description: Registers the ten REST routes the site's pages call, and hands each one to the bridge.
 * Version: 1.0.0
 * Author: Riccardo Sapuppo
 * Requires PHP: 8.1
 * License: MIT
 *
 * WordPress as a way in, and nothing more.
 *
 * This file is the only part of the repository that knows WordPress exists, and
 * it is deliberately the thinnest part: it turns a WP_REST_Request into an
 * array and a Caller, calls a method, and turns an Answer back into a
 * WP_REST_Response or a WP_Error. Nothing is decided here.
 *
 * Two consequences worth naming. Nothing in src/ needs WordPress, so the whole
 * suite runs in about a second with nothing installed. And this file can be
 * read in one sitting by somebody deciding whether to trust it: everything a
 * booking depends on is on the other side of the seam, in code that has checks
 * around it.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../../src/autoload.php';

/**
 * A transient-backed cache, which is what WordPress has.
 *
 * `get_transient` answers false for "not there" and can answer an empty array
 * for "there, and empty". The bridge needs those told apart — that distinction
 * is the difference between reading a catalogue once and reading it on every
 * page view for ever — so false becomes null here and nowhere else.
 */
final class Charter_Transients implements Charter\Cache
{
    public function get(string $key): ?array
    {
        $held = get_transient('charter_' . $key);

        return is_array($held) ? $held : null;
    }

    public function put(string $key, array $value, int $seconds): void
    {
        set_transient('charter_' . $key, $value, $seconds);
    }
}

/**
 * The real way out, over HTTP.
 *
 * Eight seconds, because the bridge retries rather than waits. A boat's page
 * makes ten calls in a row, so patience on each one multiplies: what bounds the
 * page is the timeout times the attempts times the calls, and that product has
 * to sit inside max_execution_time.
 */
final class Charter_WordPress_Http implements Charter\Transport
{
    public function send(string $method, string $path, ?array $body): array
    {
        $base = getenv('CHARTER_MANAGER_URL');

        if (!is_string($base) || $base === '') {
            throw new Charter\Unreachable('CHARTER_MANAGER_URL is not set.');
        }

        $said = wp_remote_request(rtrim($base, '/') . $path, [
            'method' => $method,
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => $body === null ? null : wp_json_encode($body),
        ]);

        if (is_wp_error($said)) {
            throw new Charter\Unreachable($said->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($said);
        $raw = wp_remote_retrieve_body($said);

        if ($code < 200 || $code >= 300) {
            throw new Charter\Unreachable('HTTP ' . $code . ' from ' . $path);
        }

        $json = json_decode($raw, true);

        if (!is_array($json)) {
            throw new Charter\Unreachable('unreadable answer from ' . $path);
        }

        return $json;
    }
}

/**
 * The customer's message, which the manager has no field for, sent to whoever
 * looks after the bookings.
 */
final class Charter_Mailed_Notes implements Charter\Notes
{
    public function keep(string $reservation, Charter\Customer $who, string $message): void
    {
        wp_mail(
            (string) get_option('admin_email'),
            'Booking ' . $reservation . ': a note from the customer',
            $who->name . ' ' . $who->surname . ' (' . $who->email . ') wrote:' . "\n\n" . $message,
        );
    }
}

function charter_bridge(): Charter\Bridge
{
    static $bridge = null;

    if ($bridge === null) {
        $bridge = new Charter\Bridge(
            new Charter\Manager(new Charter_WordPress_Http()),
            new Charter_Transients(),
            new Charter\Clock(),
            (int) (getenv('CHARTER_OPERATOR_ID') ?: 0),
            new Charter_Mailed_Notes(),
        );
    }

    return $bridge;
}

/**
 * Who is asking, in WordPress's terms.
 *
 * `wp_verify_nonce` against the REST nonce the site's own pages are given. One
 * line, and the whole of what {@see Charter\Caller::fromOurPages()} means on a
 * live site: the request was composed by a page we served. It does not say
 * anybody signed in, and it is not meant to — a booking form is public.
 */
function charter_caller(WP_REST_Request $request): Charter\Caller
{
    $nonce = (string) $request->get_header('x_wp_nonce');

    return new Charter\Caller(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false,
    );
}

/**
 * @param callable(array<string,mixed>, Charter\Caller): Charter\Answer $route
 */
function charter_answer(WP_REST_Request $request, callable $route): WP_REST_Response|WP_Error
{
    $said = $route($request->get_params(), charter_caller($request));

    if ($said->ok) {
        return rest_ensure_response(['status' => 'OK'] + $said->body);
    }

    // Two texts, and they go to two different places. What the manager said
    // goes to the log; what the visitor is shown says what happened to them and
    // nothing about the manager's internals. There is a check that holds those
    // two apart.
    if ($said->logged !== '') {
        error_log('[charter-bridge] ' . $said->code . ': ' . $said->logged);
    }

    return new WP_Error($said->code, $said->said, ['status' => $said->status]);
}

add_action('rest_api_init', static function (): void {
    $routes = [
        ['/yachts-carousel', 'GET', 'yachtsCarousel'],
        ['/yacht-detail', 'GET', 'yachtDetail'],
        ['/yacht-request', 'POST', 'yachtRequest'],
        ['/yacht-promo', 'POST', 'yachtPromo'],
        ['/countries', 'GET', 'countries'],
        ['/locations', 'GET', 'locations'],
        ['/yacht-categories', 'GET', 'yachtCategories'],
        ['/free-yachts-search', 'GET', 'freeYachtsSearch'],
        ['/track-search', 'POST', 'trackSearch'],
        ['/yachts-most-searched', 'GET', 'yachtsMostSearched'],
    ];

    foreach ($routes as [$path, $method, $on]) {
        register_rest_route('charter/v1', $path, [
            'methods' => $method,
            'callback' => static fn (WP_REST_Request $request): WP_REST_Response|WP_Error => charter_answer(
                $request,
                static fn (array $input, Charter\Caller $who): Charter\Answer => charter_bridge()->{$on}($input, $who),
            ),
            // Still open, all ten, and on purpose: nine read a catalogue, and
            // the tenth is a booking form the public is meant to be able to
            // use. Who may do what is decided inside the bridge, where it can
            // be tested without a web server — see Guard.
            'permission_callback' => '__return_true',
        ]);
    }
});
