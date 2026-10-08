<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use Hypervel\Context\NonCopyableContext;
use SplQueue;

/**
 * The Guzzle promise tasks queued by one coroutine.
 *
 * Omitting the list from copied context keeps a child coroutine from adding
 * to, or running, the tasks of the coroutine it was copied from.
 *
 * @extends SplQueue<callable(): void>
 */
class PromiseTasks extends SplQueue implements NonCopyableContext
{
}
