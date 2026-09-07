<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Context\NonCopyableContext;
use SplQueue;

/**
 * Deferred Scout jobs owned by one coroutine's exit callback.
 *
 * The queue also marks that its owner registered a defer callback. Omitting it
 * from copied context prevents a child from enqueueing into a queue whose defer
 * owner belongs to its parent.
 *
 * @extends SplQueue<callable(): void>
 */
class SearchableJobQueue extends SplQueue implements NonCopyableContext
{
}
