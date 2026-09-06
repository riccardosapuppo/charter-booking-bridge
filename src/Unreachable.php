<?php

declare(strict_types=1);

namespace Charter;

/**
 * The manager could not be reached, or answered something that was not an
 * answer. Carries the detail for the log; never for the browser.
 */
final class Unreachable extends \RuntimeException
{
}
