<?php

declare(strict_types=1);

namespace Charter;

/**
 * What arrived is not somebody a boat can be booked for. The message says which
 * field, and is safe to put on the page.
 */
final class NotACustomer extends \InvalidArgumentException
{
}
