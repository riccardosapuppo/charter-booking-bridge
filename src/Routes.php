<?php

declare(strict_types=1);

namespace Charter;

/**
 * The ten routes the site calls, as an interface, so that the suite can be run
 * twice: once against the bridge, and once against the code as it was.
 *
 * That is the whole trick of this repository. Every check is written against
 * this interface and knows nothing about which side of the repair it is
 * talking to, so `php bin/red.php` can point the same checks at
 * {@see TheWayItWas} and require them to fail. A test that has never been seen
 * to fail is a test nobody has read.
 *
 * Ten routes, and in the original ten `permission_callback => '__return_true'`.
 * Nine of them read a catalogue. The other two write: one counts, and one
 * commits a week of somebody else's boat.
 */
interface Routes
{
    /** GET — the newest boats, with the week's price. */
    public function yachtsCarousel(array $input, Caller $who): Answer;

    /** GET — one boat's page: eleven upstream calls, in the original. */
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
