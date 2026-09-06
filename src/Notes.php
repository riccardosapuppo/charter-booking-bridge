<?php

declare(strict_types=1);

namespace Charter;

/**
 * Where what the customer wrote goes.
 *
 * The booking form has a message box, and the operator's system has no field
 * for it — so the bridge cannot forward it, and the one thing it must not do
 * is drop it. Somebody types "we are bringing a two-year-old, we need a cot"
 * and believes they have said so.
 *
 * The message is handed here, and the WordPress half sends it to the agency's
 * inbox alongside the reservation number. An interface, because a check needs
 * to see that it arrived.
 */
interface Notes
{
    public function keep(string $reservation, Customer $who, string $message): void;
}
