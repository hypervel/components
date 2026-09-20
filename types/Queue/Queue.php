<?php

declare(strict_types=1);

namespace Hypervel\Types\Queue;

use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\IndexAwareQueue;
use Hypervel\Contracts\Queue\Queue;
use UnitEnum;

use function PHPStan\Testing\assertType;

/**
 * Verify enum names through the public queue contracts.
 */
function enumQueueNames(Queue $queue, ClearableQueue $clearable, IndexAwareQueue $indexed, UnitEnum $name): void
{
    assertType('int', $queue->size($name));
    assertType('int', $queue->pendingSize($name));
    assertType('int', $queue->delayedSize($name));
    assertType('int', $queue->reservedSize($name));
    assertType('int|null', $queue->creationTimeOfOldestPendingJob($name));
    $queue->push('job', [], $name);
    $queue->pushOn($name, 'job');
    $queue->pushRaw('payload', $name);
    $queue->later(1, 'job', [], $name);
    $queue->laterOn($name, 1, 'job');
    $queue->bulk(['job'], [], $name);
    assertType('Hypervel\Contracts\Queue\Job|null', $queue->pop($name));
    assertType('int', $clearable->clear($name));
    assertType('Hypervel\Contracts\Queue\Job|null', $indexed->pop($name, 1));
}
