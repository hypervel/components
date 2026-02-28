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
     * but push will no longer succeed.
     */
    public function close(): bool;

    public function getCapacity(): int;

    public function getLength(): int;

    public function isAvailable(): bool;

    public function hasProducers(): bool;

    public function hasConsumers(): bool;

    public function isEmpty(): bool;

    public function isFull(): bool;

    public function isReadable(): bool;

    public function isWritable(): bool;

    public function isClosing(): bool;

    public function isTimeout(): bool;
}
