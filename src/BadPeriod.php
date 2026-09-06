<?php

declare(strict_types=1);

namespace Charter;

/**
 * A period that is not a period. The message is written to be shown: it says
 * what is wrong with the dates and nothing about the manager.
 */
final class BadPeriod extends \InvalidArgumentException
{
}
