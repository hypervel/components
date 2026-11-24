<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

/**
 * @template TValue of mixed
 */
interface ChannelInterface
{
    /**
     * @param TValue $data
     * @param float $timeout Timeout in seconds (values less than or equal to zero wait indefinitely)
     * @phpstan-impure
     */
    public function push(mixed $data, float $timeout = -1): bool;

    /**
     * @param float $timeout Timeout in seconds (values less than or equal to zero wait indefinitely)
     * @return false|TValue Returns false when pop fails
     * @phpstan-impure Removes a value and may hand the freed slot to a blocked producer.
     */
    public function pop(float $timeout = -1): mixed;

    /**
     * Close the channel.
     *
     * Data in the channel can still be popped out after closing,
     * but push will no longer succeed. Native-backed channels must be closed
     * from a deterministic lifecycle path while the runtime is active, never
     * from a destructor after native teardown.
     *
     * @phpstan-impure
     */
    public function close(): bool;

    /**
     * Get the maximum number of buffered values.
     */
    public function capacity(): int;

    /**
     * Get the number of buffered values.
     */
    public function length(): int;

    /**
     * Determine whether the channel is empty.
     */
    public function isEmpty(): bool;

    /**
     * Determine whether the channel is full.
     */
    public function isFull(): bool;

    /**
     * Determine whether the channel is closed.
     */
    public function isClosed(): bool;

    /**
     * Determine whether any producers are waiting to push.
     */
    public function hasWaitingProducers(): bool;

    /**
     * Determine whether any consumers are waiting to pop.
     */
    public function hasWaitingConsumers(): bool;

    /**
     * Determine whether the last failed operation was canceled.
     *
     * This state must be inspected immediately after a failed operation.
     */
    public function wasCanceled(): bool;

    /**
     * Determine whether the last failed operation timed out.
     *
     * This state must be inspected immediately after a failed operation.
     */
    public function timedOut(): bool;
}
