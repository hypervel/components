<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Context\NonCopyableContext;

/**
 * Coroutine-owned Telescope queues and deferred storage state.
 *
 * The state is omitted from copied context so each child that records entries
 * owns its queue and schedules at most one independent deferred store.
 */
class RecordingState implements NonCopyableContext
{
    /**
     * The entries awaiting deferred storage.
     *
     * @var list<IncomingEntry>
     */
    public array $entries = [];

    /**
     * The entry updates awaiting storage.
     *
     * @var list<EntryUpdate>
     */
    public array $updates = [];

    /**
     * Whether the entry-recording recursion guard is active.
     */
    public bool $processingEntry = false;

    /**
     * Whether deferred storage has been scheduled for this coroutine.
     */
    public bool $storeScheduled = false;
}
