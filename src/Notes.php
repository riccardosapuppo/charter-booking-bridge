<?php

declare(strict_types=1);

namespace Charter;

/**
 * Where what the customer wrote goes.
 *
 * The form has a message box. The browser put it in the request. The route
 * never read it — there is no `message` among the parameters it takes — and the
 * manager has no field for it either, so it went nowhere, and it went nowhere
 * silently. Somebody types "we are bringing a two-year-old, we need a cot" and
 * believes they have said so.
 *
 * The manager still has no field for it, so the bridge cannot simply forward
 * it. What it can do is refuse to drop it: the message is handed here, and the
 * WordPress half sends it to the agency's inbox alongside the reservation
 * number. An interface, because the test needs to see that it arrived.
 */
interface Notes
{
    public function keep(string $reservation, Customer $who, string $message): void;
}
