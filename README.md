# Charter Booking Bridge

A charter operator's fleet lives in the booking system their supplier runs. The
website is WordPress. Between the two there has to be something, and this is
that something: ten REST routes that stand between a browser and a system where
a boat can actually be taken off the market for a week.

Nine of those routes read a catalogue. The tenth commits money that is not the
website's — it walks the supplier's three obligatory steps, a quote, an option
and a booking, and comes back with a confirmed reservation. Everything here is
arranged around that asymmetry.

## What the bridge guarantees

Eight sentences. Each one is measured, none of them is an opinion, and every
figure below is computed by a program in this repository rather than typed here.

| | |
| --- | --- |
| **A booking comes from the site's own form, and one address gets five an hour.** | **1000** POSTs with no nonce and no session leave **0** records in the operator's system and cost **0** calls to reach it. The sixth booking in an hour from one address is refused. **200** promo codes guessed from one address cost **6** calls and leave **1** record. |
| **The customer the operator receives is the one who filled the form.** | A booking with an empty form is refused and nothing at all is sent onward. A booking with a customer in it arrives with **7** of 7 fields unchanged. |
| **The dates that reach the operator are days on the calendar, in order, ahead of us.** | **5** periods that look like periods — a word, a thirteenth month, the 31st of February, a week that ends before it starts, a week that has been — leave **0** records behind and make **0** calls that could. |
| **The total on the screen is the total that gets booked, and the extras are the boat's.** | One week and two extras: shown **6,860.00**, booked **6,860.00**, apart **0.00**. A request asking for **99** sets of bed linen sends the **6** the boat offers, and a request carrying a service the boat does not offer is refused and leaves **0** records. |
| **One place holds the account, and it is the environment.** | After a password change made in one place, **9** of nine routes still reach the operator. With the environment emptied, **0** of them do. **1** place in `src/` reads it. |
| **A boat's page costs one call once the catalogue has been read.** | **10** calls for the first view of a boat, **59** for fifty views, with its fittings in **3** groups and its own kind on it. |
| **A list the operator answers with nothing is remembered as nothing.** | Ten views of a page whose category list comes back empty ask for it **1** time — the same as when it comes back full. |
| **A blip on the way to the operator is not a broken page.** | Two dropped calls in a row and the page still loads. With the operator gone it is a **502** carrying two texts: one for the visitor, one for the log. |

`php bin/measure.php` prints those with the working shown, and exits non-zero if
any of them stops being true. CI runs it and diffs its output against the copy
at the end of this file, so the figures above cannot quietly stop being the
figures.

---

## Watching a check fail

A suite of green ticks says nothing about whether it is asking for anything. So
every guarantee above has been taken back out of the code, one at a time, to see
which check notices and what it says when it does. This is that table, and the
messages in it are the real ones.

| taken out of `src/` | what goes red |
| --- | --- |
| the nonce and the counting on the route that books | `a booking has to have been composed on the site's own form` — *a POST from nowhere, with no nonce and no session, was accepted by the route that books*; and `one address gets five bookings an hour` — *one address booked 6 boats in an hour, and five is the limit* |
| the checked customer, replaced by one built inline from the request | `the customer the operator receives is the one who filled the form` — *the form said "+39 000 0000000" for phone and the operator was sent ""* |
| the calendar behind a date, leaving only the shape of one | `the dates that reach the operator are days on the calendar, in order, ahead of us` — *a booking for a thirteenth month was accepted* |
| the quantity taken from the boat's offer, replaced by the request's | `the total on the screen is the total that gets booked` — *the page showed 6,860.00 and the operator booked 6,685.00*; and `the quantity on an extra comes from the boat, not from the request` — *the request asked for 99 sets of linen and the operator was sent 99, where the boat offers 6* |
| the refusal of an extra the boat does not offer | `an extra the boat does not offer is refused` — *a booking went through carrying an extra that this boat does not have, for the operator to sort out by hand* |
| the account coming only from the environment, given a fallback in the file | `with nothing in the environment, nothing reaches the operator` — *with no account in the environment, 9 routes still reached the operator* |
| the difference between an empty list and a list nobody asked for | `a list that came back empty is not asked for again` — *ten views asked for an empty list 10 times, so an empty answer is not being told from an empty cache* |
| the keeping of a catalogue that has already answered | `a catalogue that has answered is not asked again` — *ten views of one boat fetched the equipment categories 10 times*; and `a boat's page costs ten calls cold and one warm` — *the second view of the same boat cost 10 calls* |
| the retry on a call that reached nobody | `a blip on the way to the operator is not a broken page` — *one blip on the way to the operator lost the whole page* |

Every one of those also turns a claim from HOLDS to BROKEN in the measurement,
so `php bin/measure.php` exits non-zero and the README stops matching what the
program prints. The guarantee, the check and the figure are three views of one
thing, and taking the guarantee out moves all three.

Doing this is what found the two checks that were not asking for anything.
Counting calls across ten page views on a single bridge object counts a memo
held in memory for the length of one request, not the cache — the cache could be
taken out altogether and the count would not move. So `Bench::nextRequest()`
builds the bridge again between views, the way PHP does on every request, and
the two checks about the catalogue now go red when the cache goes.

---

## Before you start

**PHP 8.3**, and nothing else.

```
php --version        # 8.3.33 here; any 8.1 or later will do
```

```
php bin/prove.php          # 37 checks, about a second
php bin/walkthrough.php    # one visitor, front page to booking
php bin/measure.php        # the eight guarantees, with the working shown
```

**What you do not need**: Composer, a database, a web server, a network
connection, an account with anybody, and WordPress. There is no install step and
nothing is written outside the repository, so there is nothing to undo
afterwards either — deleting the directory is the whole of it.

Everything runs against an invented supplier in memory: an operator numbered
900001, six boats named after the phonetic alphabet, a marina and a bay that do
not exist, and one promo code spelled `DEMO-PROMO-10` so that nobody can mistake
it for one somebody could spend.

`bin/walkthrough.php` is the one to read the output of. It is not the suite: it
walks the ten routes in the order the site calls them, as a visitor would, one
page load at a time, and prints what came back at every step — including two
steps that are meant to be refused, because a journey that only ever succeeds
tells you nothing about the doors. It is the shape of check that notices a
shelf of special offers coming back empty on a fleet that plainly has some,
which is the sort of thing no diff shows and no assertion was written for.

---

## What is in here

| | |
| --- | --- |
| `src/Bridge.php` | The ten routes. |
| `src/Routes.php` | The interface: the whole of what the WordPress half knows about them. |
| `src/Guard.php`, `src/Caller.php` | Who is asking, and how often. |
| `src/Secrets.php` | The one place the account is read, and it reads the environment. |
| `src/Manager.php`, `src/Catalogue.php` | The supplier's system: how it is spoken to, and one table of endpoints and response keys. |
| `src/Customer.php`, `src/Dates.php`, `src/Extras.php` | The three things that arrive from the browser and are checked before they are believed. |
| `src/InventedManager.php`, `src/Fleet.php` | A booking system that does not exist, answering in the shape a real one does. |
| `src/Bench.php` | A whole site in one object, and `nextRequest()` — the next page load. |
| `src/Shown.php` | The browser's arithmetic, transcribed, so that a check can compare it with the operator's. |
| `src/Claims.php` | The eight guarantees, measured. |
| `bin/prove.php`, `bin/measure.php` | The runner and the measurement. |
| `bin/walkthrough.php` | One visitor's journey through all ten routes, printed step by step. |
| `wordpress/charter-bridge/` | One file: WordPress as a way in, and nothing more. |

---

## How the money is decided

The one thing worth saying twice, because it is the guarantee that costs the
most to lose.

The form sends identifiers, dates and a customer. There is no `price`, `total`
or `amount` in any request body anywhere, and no route reads one — there is a
check that searches `src/` for it and fails if one appears. The supplier decides
what a week costs and the site commits whatever it says.

The screen still has to add up: the page shows a total before anybody books.
That arithmetic is transcribed into `src/Shown.php`, which the bridge never
calls, and one check computes the figure the visitor read and compares it with
the figure the operator booked. Two numbers computed in two places, in two
languages, is exactly the arrangement in which they drift apart without a sound,
and this is the only thing that would notice.

---

## What this is not

The limits, so they are read here rather than found:

- **The front end is not in here.** The site's HTML fragments are not part of
  this repository. What the browser does with an answer is the browser's, and
  the one piece of it that is reproduced here is the arithmetic on the boat's
  page, because a check needs it.
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
- **The retries are checked, the backoff is not.** A check drops the network for
  two calls and expects the page to survive. What is not checked is the waiting
  between attempts, because there is none: three attempts, back to back. A real
  one wants a pause that grows, and that needs a clock the client can be handed.
- **Nothing here talks HTTP.** The WordPress file is linted and read, never run:
  running it needs WordPress, and the whole point of the arrangement is that
  nothing that decides anything needs WordPress.

---

## The WordPress half

One file, `wordpress/charter-bridge/charter-bridge.php`, and it decides nothing.
It turns a `WP_REST_Request` into an array and a caller, calls a method, and
turns the answer back into a response or a `WP_Error`. Transients become the
cache, `wp_remote_request` becomes the way out, and the customer's message —
which the supplier has no field for — becomes an email to whoever looks after
the bookings.

It also means nothing in `src/` needs WordPress, which is why the suite runs in
a second on a checkout with nothing installed.

---

## The measurement, in full

```
An invented operator, an invented fleet, and the ten routes measured against
them: what the bridge guarantees, and the working for each figure.

==============================================================================
HOLDS   A booking comes from the site's own form, and one address gets five an hour
==============================================================================

  1000 POSTs at the booking route from an address with no session, no nonce and no
  form behind it. Nothing else: the same body, a thousand times.

                              the bridge
  --------------------------------------
  confirmed reservations               0
  records of any kind                  0
  calls to the operator                0

  Nothing reaches the operator, because the refusal happens before the first call.
  Any record that did reach it would be a week somebody has to take off the market
  and then cancel by hand.

  Then six bookings from the site's own form: one address, six different boats.

                                                                    the bridge
  ----------------------------------------------------------------------------
  bookings accepted                                                     5 of 6
  what the sixth was told      Too many attempts. Wait a minute and try again.

  And 200 promo codes tried from one address, none of them real. Trying a code
  is not a question: the only way to price one is to ask for a quote carrying it,
  and a quote is a record in the operator's system.

                                     the bridge
  ---------------------------------------------
  calls that open a reservation               6
  records left behind                         1

==============================================================================
HOLDS   The customer the operator receives is the one who filled the form
==============================================================================

  A booking posted with every field of the form left empty, and then the same
  booking with a customer in it.

                                     the empty form    a filled form
  ------------------------------------------------------------------
  what happened                             refused           booked
  reached the operator               nothing at all       a customer
  fields that arrived unchanged                   -           7 of 7

  the empty form was told: A booking needs a name and a surname.

  The body that goes to the operator is built from the checked customer and from
  nothing else, which is what makes the second row a property of this code rather
  than of the browser. The form insists on those fields too, and the endpoint is
  open to everything that is not a browser.

==============================================================================
HOLDS   The dates that reach the operator are days on the calendar, in order, ahead of us
==============================================================================

  Five periods that look like periods, posted at the route that books.

                                                                             the bridge
  -------------------------------------------------------------------------------------
  a word                                                      The dates are not a date.
  a thirteenth month                                          The dates are not a date.
  the 31st of February                                        The dates are not a date.
  a week that ends before it starts      The end of the charter is not after its start.
  a week that has been                                      That week has already been.

                                             the bridge
  -----------------------------------------------------
  records left in the operator's system               0
  calls that open a reservation                       0
  a real week, same route                        booked

  The 31st of February is the one that is easy to let through: it has the shape of
  a date, and a converter that is not asked to consult a calendar will make it the
  3rd of March. Nothing downstream of this takes two strings, so nothing downstream
  has to wonder whether somebody checked.

==============================================================================
HOLDS   The total on the screen is the total that gets booked, and the extras are the boat's
==============================================================================

  One week on a boat that lets for 6400, plus bed linen at 35 a head for six, plus a
  tender at 250. The page adds those up in the browser; the operator adds them up
  again when the booking is made. No request body anywhere carries a price.

                              the bridge
  --------------------------------------
  shown on the page             6,860.00
  booked by the operator        6,860.00
  apart                             0.00

  Those two are computed in two places, in two languages, and what holds them
  together is that the quantity on each line is read off the boat's own offer and
  never off the request. So the request in this measurement asked for ninety-nine.

                                           the bridge
  ---------------------------------------------------
  sets of linen the browser asked for              99
  sets of linen the boat offers                     6
  sets of linen the operator was sent               6

  And a booking carrying a service this boat does not offer at all.

                                             the bridge
  -----------------------------------------------------
  what happened                                 refused
  records left in the operator's system               0

  the visitor is told: One of the extras is not available for this boat and week. Reload the page and choose again.

  A line the boat does not have is one the operator has to sort out by hand, and
  it is also a line with no offer behind it to read a quantity off. The two rows
  above and the three before them are the same guarantee seen twice.

==============================================================================
HOLDS   One place holds the account, and it is the environment
==============================================================================

  The operator issues a new password. It is set in the two environment variables
  the bridge reads, nothing else is touched, and all nine routes that talk to the
  operator are called. Then the same nine again, with the environment emptied.

                                                           the bridge
  -------------------------------------------------------------------
  routes reaching the operator after a rotation, of 9               9
  routes reaching the operator with no account set                  0
  places in src/ that read the account                              1

  The second row is the half worth measuring. A copy of the account left anywhere
  in the code would keep some of those nine working with nothing in the
  environment, and that is exactly the state in which a rotation looks done and is
  not: half the site working, the other half failing for a reason nobody can
  reproduce.

==============================================================================
HOLDS   A boat's page costs one call once the catalogue has been read
==============================================================================

  The same boat, opened fifty times, on a cold cache.

                                                 the bridge
  ---------------------------------------------------------
  calls for the first view                               10
  calls for fifty views                                  59
  calls for each view after the first                     1
  groups the fittings fall into                           3
  the boat's kind                          Monoscafo a vela

  The one call a later view still costs is the week's price, which is the only
  thing that can have changed. The last two rows are here because they are read
  out of the same catalogue: a fitting's category and a boat's category are two
  different lists, and a page that has lost one of them shows every fitting in a
  single heap called "Other" and no kind at all.

==============================================================================
HOLDS   A list the operator answers with nothing is remembered as nothing
==============================================================================

  Ten views of one boat's page, with the operator answering the equipment
  categories endpoint with a list that has nothing in it. An ordinary state: an
  operator who has not filled that shelf in.

                                                     the bridge
  -------------------------------------------------------------
  calls for that list, when it comes back empty               1
  calls for that list, when it comes back full                1
  the page still loads                                      yes

  Those two being the same number is the claim. A cache that answers "not here" for
  a stored empty list re-fetches it on every request of the site's life, for
  something that will never have anything in it — and nothing ever looks broken:
  the site is only slow, and the operator sees traffic nobody can explain.

==============================================================================
HOLDS   A blip on the way to the operator is not a broken page
==============================================================================

  A boat's page makes ten calls. The first of them finds nobody at the other end,
  twice, and then the network is back. Then the same page with the operator gone.

                                      the bridge
  ----------------------------------------------
  two dropped calls in a row      the page loads
  the operator unreachable          refused, 502

  the visitor is shown: The booking system could not be reached. Please try again shortly.
  the log is given:     /catalogue/v6/yacht/5001 could not be reached in 3 attempts: the invented manager is pretending the network dropped

  Reaching nobody is worth trying again; being told no is not. And when there is
  nothing left to try, those are two different texts on purpose: what the
  operator's own system says about itself is written for whoever runs it, and it
  goes to the log rather than onto a public page.

All 8 claims hold.
```

---

## Where this comes from

Rebuilt from the WordPress side of a live charter website, with everything
identifying removed: no client, no operator, no supplier, no host, no account,
no real boat, base or location identifiers, no real booking numbers, no real
promo codes, and no real people — the customer in the checks is invented and her
address is a road that does not exist. The supplier's own API documentation is
not here and none of it is quoted: what is described is the behaviour that was
observed, in my own words.

What is kept is the shape of the problem: ten routes, one of which spends money,
and a supplier that has to be spoken to carefully.

MIT licensed. See [LICENSE](LICENSE).
