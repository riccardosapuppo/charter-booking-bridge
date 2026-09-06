<?php

declare(strict_types=1);

namespace Charter;

/**
 * The manager was reached and refused. The message belongs in the site's log
 * and nowhere else: it is the manager's own diagnostics, and what is in it is
 * the manager's business, not a visitor's.
 */
final class ManagerSaidNo extends \RuntimeException
{
}
