<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Contracts\Events\ShouldNotBeDeferred;

/**
 * Unlike Laravel, Event::defer() does not delay this event, since a pool can hand the
 * connection to another borrower before the deferred closure ends.
 */
class ConnectionEstablished extends ConnectionEvent implements ShouldNotBeDeferred
{
}
