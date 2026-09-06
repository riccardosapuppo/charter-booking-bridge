<?php

declare(strict_types=1);

namespace Charter;

/**
 * The ten routes the site calls, as an interface: the whole of what the
 * WordPress half is allowed to know about the bridge.
 *
 * It is written down as a type for two reasons. The WordPress file turns a
 * request into an array and a caller, calls one of these, and turns the answer
 * back — so this is the seam, and nothing on the far side of it needs
 * WordPress. And every check in test/ is written against this interface, which
 * is what keeps them about the answers the site gets rather than about how the
 * bridge happens to be arranged inside.
 *
 * Nine of the ten read a catalogue and are open. Two write: one counts what was
 * shown, and one commits a week of the operator's boat. Both of those go
 * through {@see Guard} before anything else happens.
 */
interface Routes
{
    /** GET — the newest boats, with the week's price. */
    public function yachtsCarousel(array $input, Caller $who): Answer;

    /** GET — one boat's page: the boat, its fittings, and the week's price. */
    public function yachtDetail(array $input, Caller $who): Answer;

    /** POST — the one that books. */
    public function yachtRequest(array $input, Caller $who): Answer;

    /** POST — is this promo code any good. */
    public function yachtPromo(array $input, Caller $who): Answer;

    /** GET — the country list behind a dropdown. */
    public function countries(array $input, Caller $who): Answer;

    /** GET — the places boats are let from. */
    public function locations(array $input, Caller $who): Answer;

    /** GET — sail, catamaran, motor. */
    public function yachtCategories(array $input, Caller $who): Answer;

    /** GET — the search itself, paged. */
    public function freeYachtsSearch(array $input, Caller $who): Answer;

    /** POST — the site says which boats were shown. */
    public function trackSearch(array $input, Caller $who): Answer;

    /** GET — the "most searched" shelf, read back off those counts. */
    public function yachtsMostSearched(array $input, Caller $who): Answer;
}
