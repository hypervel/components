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
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     */
    public function push(mixed $data, float $timeout = -1): bool;

    /**
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     * @return false|TValue Returns false when pop fails
     */
    public function pop(float $timeout = -1): mixed;

    /**
     * Close the channel.
     *
     * Data in the channel can still be popped out after closing,
     * but push will no longer succeed. Native-backed channels must be closed
     * from a deterministic lifecycle path while the runtime is active, never
     * from a destructor after native teardown.
     */
    public function close(): bool;

    /**
     * Get the channel capacity.
     */
    public function getCapacity(): int;

    /**
     * Get the number of queued values.
     */
    public function getLength(): int;

    /**
     * Determine whether the channel is available.
     */
    public function isAvailable(): bool;

    /**
     * Determine whether producers are waiting.
     */
    public function hasProducers(): bool;

    /**
     * Determine whether consumers are waiting.
     */
    public function hasConsumers(): bool;

    /**
     * Determine whether the channel is empty.
     */
    public function isEmpty(): bool;

    /**
     * Determine whether the channel is full.
     */
    public function isFull(): bool;

    /**
     * Determine whether the channel is readable.
     */
    public function isReadable(): bool;

    /**
     * Determine whether the channel is writable.
     */
    public function isWritable(): bool;

    /**
     * Determine whether the channel is closing or closed.
     */
    public function isClosing(): bool;

    /**
     * Determine whether the last operation timed out.
     */
    public function isTimeout(): bool;
}
