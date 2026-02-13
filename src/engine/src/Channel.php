<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\ChannelInterface;
use Hypervel\Engine\Exception\RuntimeException;

/**
 * @template TValue of mixed
 * @implements ChannelInterface<TValue>
 */
class Channel extends \Swoole\Coroutine\Channel implements ChannelInterface
{
    protected bool $closed = false;

    /**
     * Push data into the channel.
     *
     * @param TValue $data
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     */
    public function push(mixed $data, float $timeout = -1): bool
    {
        return parent::push($data, $timeout);
    }

    /**
     * Pop data from the channel.
     *
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     * @return false|TValue Returns false when pop fails
     */
    public function pop(float $timeout = -1): mixed
    {
        return parent::pop($timeout);
    }

    /**
     * Get the channel capacity.
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Get the current length of the channel.
     */
    public function getLength(): int
    {
        return $this->length();
    }

    /**
     * Determine if the channel is available.
     */
    public function isAvailable(): bool
    {
        return ! $this->isClosing();
    }

    /**
     * Close the channel.
     */
    public function close(): bool
    {
        $this->closed = true;
        return parent::close();
    }

    /**
     * Determine if the channel has producers waiting.
     *
     * @throws RuntimeException not supported in Swoole
     */
    public function hasProducers(): bool
    {
        throw new RuntimeException('Not supported.');
    }

    /**
     * Determine if the channel has consumers waiting.
     *
     * @throws RuntimeException not supported in Swoole
     */
    public function hasConsumers(): bool
    {
        throw new RuntimeException('Not supported.');
    }

    /**
     * Determine if the channel is readable.
     *
     * @throws RuntimeException not supported in Swoole
     */
    public function isReadable(): bool
    {
        throw new RuntimeException('Not supported.');
    }

    /**
     * Determine if the channel is writable.
     *
     * @throws RuntimeException not supported in Swoole
     */
    public function isWritable(): bool
    {
        throw new RuntimeException('Not supported.');
    }

    /**
     * Determine if the channel is closing or closed.
     */
    public function isClosing(): bool
    {
        return $this->closed || $this->errCode === SWOOLE_CHANNEL_CLOSED;
    }

    /**
     * Determine if the last operation timed out.
     */
    public function isTimeout(): bool
    {
        return ! $this->closed && $this->errCode === SWOOLE_CHANNEL_TIMEOUT;
    }
}
