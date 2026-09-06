# Charter Booking Bridge

A charter operator's fleet lives in the booking system their supplier runs. The
website is WordPress. Between the two there has to be something, and this is a
rebuild of that something: ten REST routes that stand between a browser and a
system where a boat can actually be taken off the market for a week.

Nine of those routes read a catalogue. The tenth commits money that is not the
website's. All ten were registered like this:

```php
register_rest_route('…/v1', '/yacht-request', [
    'methods'             => 'POST',
    'callback'            => 'yacht_request',
    'permission_callback' => '__return_true',
]);
```

`/yacht-request` walks the supplier's three obligatory steps — a quote, an
option, a booking — and comes back with a confirmed reservation. With that
callback there is no nonce, no session, no captcha and no limit: **anybody who
knows the address can create real bookings in the operator's system, in
somebody's invented name, for whatever weeks they choose**, and every one of
them has to be cancelled by hand at the other end.

The password being in clear in the file is the fault everybody finds first. This
is the one that matters, and it is one line.

It comes with six claims, and each of them can fail:

| | |
| --- | --- |
| **A stranger cannot book a boat.** | 1000 posts with no session and no nonce: the old routes leave **1000** confirmed reservations in the operator's system. These leave **0**, and make **0** calls to reach that answer. |
| **The customer the operator receives is the one who filled the form.** | An empty form books, and arrives with **7** of 7 fields empty — because the customer that was built with fallbacks was never the customer that was sent. Here it is refused before anything is written. |
| **Guessing promo codes does not fill the operator's system.** | 200 codes tried from one address cost the old route **400** calls that open a reservation and leave **200** records behind. The bridge: **6** and **1**. |
| **The total on the screen is the total that gets booked.** | One week and two extras: the old flow books **175.00** less than the page showed the visitor. This one is out by **0.00**. |
| **One place holds the account.** | The account is written out **9** times, so a password change reaches **0** of the nine routes that need it. Changing it here reaches all nine. |
| **A boat's page costs one call once the catalogue has been read.** | Fifty views of one boat: **158** calls to the supplier, with the boat's fittings in **1** group called "Other". Here, **59** calls and **3** groups. |

`php bin/measure.php` prints those, with the working shown, and exits non-zero
if any of them stops being true. CI runs it and diffs its output against the
copy further down this file, so the figures above cannot quietly stop being the
figures.

---

## Red before green

A suite of green ticks says nothing about whether anything was ever wrong.

So the ten routes as they were are still here, in `src/TheWayItWas.php`, still
running: de-branded, and otherwise behaving exactly as they behaved. Both
versions implement the same interface, every check is written against that
interface, and one command points the whole suite at the wrong side and requires
it to fail:

```
$ php bin/red.php

  red   the route that books has a door on it
        a POST from nowhere, with no nonce and no session, was accepted by the route
        that books — expected false, got true
  red   a boat keeps its own kind
        the boat came back as null, because the variable holding its kind was reused
        inside the loop over its fittings — expected "Monoscafo a vela", got null
  …
  17 checks are meant to go red here, and 17 did.
```

Each of those is listed in `bin/red.php` with a phrase out of what it says when
it fails. A check that starts passing against the original is a check that has
stopped being about the repair, and this command fails on that too.

---

## Before you start

**PHP 8.3**, and nothing else.

```
php --version        # 8.3.33 here; any 8.1 or later will do
```

```
php bin/prove.php                      # 27 checks, about a second
php bin/prove.php --bridge=as-it-was   # the same ones, against the original
php bin/red.php                        # and the same run, insisting on the red
php bin/walkthrough.php                # one visitor, front page to booking
php bin/measure.php                    # the six claims, with the working shown
```

**What you do not need**: Composer, a database, a web server, a network
connection, an account with anybody, and WordPress. There is no install step and
nothing is written outside the repository, so there is nothing to undo
afterwards either — deleting the directory is the whole of it. The checkout is
230 KB across 51 files, and the five commands above together take about three
seconds.

Everything runs against an invented supplier in memory: an operator numbered
900001, six boats named after the phonetic alphabet, a marina and a bay that do
not exist, and one promo code spelled `DEMO-PROMO-10` so that nobody can mistake
it for one somebody could spend.

`bin/walkthrough.php` is the one to read the output of. It is not the suite: it
walks the ten routes in the order the site calls them, as a visitor would, and
prints what came back at every step — including two steps that are meant to be
refused, because a journey that only ever succeeds tells you nothing about the
doors. It found one of the faults below that reading the file had not.

---

## What is in here

| | |
| --- | --- |
| `src/Bridge.php` | The ten routes, repaired. |
| `src/TheWayItWas.php` | The same ten as they were, kept runnable so the difference can be counted. |
| `src/Routes.php` | The interface both of them implement, which is what lets one suite judge both. |
| `src/Guard.php`, `src/Caller.php` | Who is asking, and how often. The door the writing route did not have. |
| `src/Secrets.php` | The one place the account is read. |
| `src/Manager.php`, `src/Catalogue.php` | The supplier's system: how it is spoken to, and the one table of response keys. |
| `src/Customer.php`, `src/Dates.php`, `src/Extras.php` | The three things that arrived from the browser and were believed. |
| `src/InventedManager.php`, `src/Fleet.php` | A booking system that does not exist, answering in the shape a real one does. |
| `src/Claims.php` | The six claims, run against both sides. |
| `bin/prove.php`, `bin/red.php`, `bin/measure.php` | The runner, the insistence on the red, and the measurement. |
| `bin/walkthrough.php` | One visitor's journey through all ten routes, printed step by step. |
| `wordpress/charter-bridge/` | One file: WordPress as a way in, and nothing more. |

---

## The nine things that were wrong

### A stranger could book a boat

`permission_callback => '__return_true'` on the route that books, and in the
whole of the original file no `wp_verify_nonce`, no `current_user_can`, and
nothing counting attempts. The only validation before the supplier was reached
was that the boat id and the two dates were not empty.

The repair is two conditions and a value type. A booking has to have been
composed on a page we served — that is what `Caller::fromOurPages()` means, and
it is about the request carrying a nonce, not about anybody signing in, because
a booking form is meant to be public. And five bookings an hour from one address
is generous for a family choosing a holiday and useless to a script.

### The customer that was built was not the customer that was sent

The strangest thing in the file. The booking route assembled a customer with a
thought-out fallback for every field a form might leave empty — `Promo`, `Code`,
a zip of `00000`, a phone of ten zeroes — fourteen lines of defence, into a
variable that was never read again. Thirty lines further down the request body
built a second customer, inline, out of the raw values, with no fallback at all.

So the defence was inert and the operator received bookings whose customer was a
row of empty strings. The browser insisted on those fields, which is why nobody
saw it; the endpoint was open to everything that is not a browser.

The repair is not to restore the fallbacks. A booking with no name is not a
booking, and inventing a name for it is how the empty ones got in.

### Trying a promo code wrote two reservations

To find out whether a code was any good, the route asked the supplier for a
quote without it, asked again with it, and compared the prices. Asking for a
quote is not a question: it leaves a record in the operator's system. No limit,
no cleaning up, and no record of who asked — so a five-hundred-word dictionary
left a thousand rows, and told whoever ran it which codes exist and what each one
is worth.

Here the price without the code is asked once per boat and week and kept for
fifteen minutes, and the attempts are counted per address.

### The total was added up in the browser

Bed linen is priced per person and the boat sleeps six, so the page multiplied
thirty-five by six and showed the visitor a total. The request then sent
`quantity: 1`, and the bridge threw even that away and forwarded a bare number,
which the supplier reads as one of them.

The visitor read one figure and the operator booked another, 175 apart on a
single extra, and neither side had anything that could notice. The quantity now
comes from the boat's own offer, on the server, which is also where the check
that the extra is on offer at all now happens — the original had that check
written out in full, twenty-three lines of it, and never called it from
anywhere.

### The account was in the file, nine times

Nine copies, not the eight everybody counts, under a comment that said, in
Italian, "CONFIG (IN CLEAR)". The difference is not pedantry: it is the number of
edits a password change takes, and the ninth is the one that gets missed, after
which half the site fails intermittently for a reason nobody can reproduce.

There is now one read, from the environment, and `.env.example` gives the names
and no values. The account this replaced should be treated as burnt whatever is
published: it lived in clear in a theme file, which means backups, staging
copies, every diff, and the theme editor in the admin panel.

### One wrong key, and a cache that never took

Eleven catalogue readers, written by copying. In one of them:

```php
$items = $res['categories'] ?? [];        // equipmentCategories
```

The answer holds that list under `equipmentCategories`. `categories` is what the
*yacht* category endpoint uses, thirty lines further down, where it is right. So
the map came back empty, every fitting on every boat fell into "Other", and the
grouping the page exists to show collapsed into one heap.

The second half is the one worth the trouble. The guard was `!empty($cached)`, so
the empty map that had just been stored could never be accepted, and the
supplier was asked again on every single page view, for ever, for something that
would never have anything in it. Nothing looked broken. The site was just slow,
and the supplier saw traffic nobody could explain.

The eleven readers are now one function and one table of endpoints and keys, and
the cache tells "never asked" from "asked, and the answer was nothing".

### The boat's kind was overwritten by its fittings

```php
$categoryId = $model['yachtCategoryId'];     // the boat: a sailing monohull
…
foreach ($boat['standardYachtEquipment'] as $fitted) {
    $categoryId = $equipment[$fitted]['categoryId'];   // the same name, eighteen lines later
}
…
'yachtCategoryName' => $categoriesMap[$categoryId] ?? null,   // the last fitting's category
```

By the end of the loop the variable held the category of the last piece of kit on
the boat, which was then looked up in the map of *boat* categories and found
nothing. The page said "Yacht".

### The discounted shelf was almost always empty

The one that was not found by reading. `bin/walkthrough.php` opens the front
page the way a visitor does, and the shelf headed "special offers" came back
with nothing on it while the fleet plainly had offers.

The shelf sorted the fleet by build year, cut it to the newest twelve, and only
then filtered for a discount. Discounting is done on the unsold end of a fleet —
the older boats — which the sort had just pushed to the bottom and the cut had
just thrown away. Two pages in production asked that question and were usually
handed an empty carousel.

Filter, then cut. Which needs the price of every boat rather than of twelve, so
it is a different shape of request and not a moved line.

### Three bytes at the top of the file

The file began with a UTF-8 byte order mark. In a `functions.php`, included on
every request, those three bytes are output: they go out before any header can,
which is where "headers already sent" comes from, and they sit in front of the
body of every REST answer, where they stop `await res.json()` from parsing it.
Whether it happens at all depends on how output buffering is configured, so it
happens in production and not on the laptop.

Nothing here begins with one, and both the suite and CI say so — a mark comes
back through an editor rather than through a commit, so it is worth a check
rather than a memory.

---

## What was not wrong with it

A rebuild that paints everything black is not worth reading, and four things
that looked wrong were checked and are not.

**The price never travels through the browser.** The form sends identifiers,
dates and a customer. There is no `price`, `total` or `amount` in any request
body anywhere in the front end, and no route reads one. The supplier decides what
a week costs and the site commits whatever it says. That is the most defensible
decision in the whole project, and it survives here unchanged.

**There is no SQL injection, because there is no SQL.** Not one hand-written
query: options and transients, and nothing else.

**The front end is careful.** There is an escaping helper and it is used almost
everywhere; every parameter read out of the URL is coerced to a number or
normalised as a date; the helper that renders a name is defensive enough that it
quietly covered up one of the faults above. The interesting story here is an
asymmetry between two halves of one project, not a disaster.

**The timeouts exist.** Thirty seconds on each call, which is reasonable on its
own. The fault is the sum: eleven of them in a row on one page, against a
`max_execution_time` of thirty. The repair is fewer calls and a shorter timeout
with retries, not a timeout where there was none.

---

## What this is not

The limits, so they are read here rather than found:

- **The front end is not rebuilt.** The site's eight HTML fragments are not in
  this repository, and the faults that live in them are described above and not
  fixed: four different rules for deciding whether a boat is available, one page
  asking for a hundred results where the server allows fifty and then filtering
  and sorting inside the truncated set as if it were everything, a date picker
  with no floor, and a countdown that starts a new timer on every change of
  date without stopping the old one.
- **The supplier is invented.** `InventedManager` answers in the shape the real
  one does, and the shapes were taken from behaviour that was observed rather
  than from anybody's documentation, but it is not the real system and nothing
  here has been run against it.
- **The lost update is not modelled.** The search counter is a read, a change
  and a write, with nothing holding it, and under real traffic two requests
  overwrite each other. Reproducing that needs two processes and a database;
  here there is one process, so what this repository does about it is bound the
  damage — thirty ids a request, de-duplicated, and only ids that are boats —
  rather than prove the race.
- **The retries are tested, the backoff is not.** A check drops the network for
  two calls and expects the page to survive, which the original did not. What is
  not tested is the waiting between attempts, because there is none: three
  attempts, back to back. A real one wants a pause that grows, and that needs a
  clock the client can be handed.
- **Nothing here talks HTTP.** The WordPress file is linted and read, never run:
  running it needs WordPress, and the whole point of the arrangement is that
  nothing that decides anything needs WordPress.

---

## The WordPress half

One file, `wordpress/charter-bridge/charter-bridge.php`, and it decides nothing.
It turns a `WP_REST_Request` into an array and a caller, calls a method, and
turns the answer back into a response or a `WP_Error`. Transients become the
cache, `wp_remote_request` becomes the way out, and the customer's message —
which the supplier has no field for, and which the original collected in the
browser and dropped on the floor — becomes an email to whoever looks after the
bookings.

That is the opposite of what it replaces, where all of this lived in a child
theme's `functions.php`: 1,483 lines, loaded on every request to the site, and
editable from the theme editor in the admin panel.

It also means nothing in `src/` needs WordPress, which is why the suite runs in
a second on a checkout with nothing installed.

---

## The measurement, in full

```

An invented operator, an invented fleet, and the ten routes run twice:
once as they were, once repaired.

==============================================================================
HOLDS   A stranger cannot book a boat
==============================================================================

  1000 POSTs at the booking route from an address with no session, no nonce and no
  form behind it. Nothing else: the same body, a thousand times.

                              as it was    the bridge
  ---------------------------------------------------
  confirmed reservations           1000             0
  records of any kind              1000             0
  calls to the operator            4000             0

  Every one of those is a week the operator has to take off the market and then
  cancel by hand. The route was registered with permission_callback => '__return_true',
  like the nine that only read a catalogue.

==============================================================================
HOLDS   The customer the operator receives is the one who filled the form
==============================================================================

  A booking posted with every field of the form left empty.

                                  as it was          the bridge
  -------------------------------------------------------------
  what happened                      booked             refused
  customer fields sent empty         7 of 7    nothing was sent

  as it was, the operator received: {"name":"","surname":"","zip":"","email":""}
  the bridge answered:             A booking needs a name and a surname.

  The fallbacks for this were written — Promo, Code, a zip of 00000, a phone of ten
  zeroes — fourteen lines of them, into a variable that was never read again. Thirty
  lines further down the request body built a second customer out of the raw values,
  and that is the one that went.

==============================================================================
HOLDS   Guessing promo codes does not fill the operator's system
==============================================================================

  200 codes tried from one address, none of them real.

                                     as it was    the bridge
  ----------------------------------------------------------
  calls that open a reservation            400             6
  records left behind                      200             1

  Two calls per try, because the way to find out whether a code is any good was to
  ask for a quote without it, ask again with it, and compare the prices. Both of
  those are records in the operator's system. A five-hundred-word dictionary left a
  thousand of them, and told whoever ran it which codes exist and what each is worth.

==============================================================================
HOLDS   The total on the screen is the total that gets booked
==============================================================================

  One week on a boat that lets for 6400, plus bed linen at 35 a head for six, plus a
  tender at 250. The page adds those up in the browser; the operator adds them up
  again when the booking is made.

                              as it was    the bridge
  ---------------------------------------------------
  shown on the page            6,860.00      6,860.00
  booked by the operator       6,685.00      6,860.00
  apart                          175.00          0.00

  The page multiplies by the amount on the extra. The request sent quantity: 1, and
  the bridge threw even that away and forwarded a bare number, which the operator
  reads as one of them. Neither side had anything that could notice the difference.

==============================================================================
HOLDS   One place holds the account, and changing it changes everything
==============================================================================

  The operator issues a new password. It is set in one place and nothing else is
  touched. Then all nine routes that talk to the operator are called.

                                         as it was    the bridge
  --------------------------------------------------------------
  routes still working, of 9                     0             9
  copies of the account in the code              9             0

  Nine copies, not the eight everybody counts, and the difference is the whole point:
  a rotation is nine coordinated edits, and the one that gets missed leaves part of
  the site failing intermittently for a reason nobody can reproduce.

==============================================================================
HOLDS   A boat's page costs one call once the catalogue has been read
==============================================================================

  The same boat, opened fifty times, on a cold cache.

                                     as it was          the bridge
  ----------------------------------------------------------------
  calls for the first view                  11                  10
  calls for fifty views                    158                  59
  groups the fittings fall into              1                   3
  the boat's kind                      nothing    Monoscafo a vela

  The last two rows are one mistake with two faces. The equipment categories were
  read out of the wrong key of the answer, so the map came back empty, every fitting
  fell into "Other", and — because an empty map is indistinguishable from a cache
  that was never filled — it was fetched again on every single view, for ever.

All 6 claims hold.
```

---

## About the original

Rebuilt from the WordPress side of a live charter website, with everything
identifying removed: no client, no operator, no supplier, no host, no account,
no real boat, base or location identifiers, no real booking numbers, no real
promo codes, and no real people — the customer in the checks is invented and her
address is a road that does not exist. The supplier's own API documentation is
not here and none of it is quoted: what is described is the behaviour that was
observed, in my own words.

What is kept is the shape of the problem and the nine faults, which were real,
and one of which let anybody with the address book a boat.

MIT licensed. See [LICENSE](LICENSE).
