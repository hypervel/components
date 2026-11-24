<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\ChannelInterface;

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
     * @param float $timeout Timeout in seconds (values less than or equal to zero wait indefinitely)
     * @phpstan-impure
     */
    public function push(mixed $data, float $timeout = -1): bool
    {
        return parent::push($data, $timeout);
    }

    /**
     * Pop data from the channel.
     *
     * @param float $timeout Timeout in seconds (values less than or equal to zero wait indefinitely)
     * @return false|TValue Returns false when pop fails
     * @phpstan-impure Removes a value and may hand the freed slot to a blocked producer.
     */
    public function pop(float $timeout = -1): mixed
    {
        return parent::pop($timeout);
    }

    /**
     * Close the channel.
     *
     * Call only from a deterministic lifecycle path while the Swoole runtime
     * is live. Native channel methods are uncatchably fatal after the native
     * handle is torn down, so destructors must not call this method.
     *
     * @phpstan-impure
     */
    public function close(): bool
    {
        // Native close resumes waiters before returning to this coroutine.
        $this->closed = true;
        return parent::close();
    }

    /**
     * Get the maximum number of buffered values.
     */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Determine if the channel is closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Determine if any producers are waiting to push.
     */
    public function hasWaitingProducers(): bool
    {
        return $this->stats()['producer_num'] > 0;
    }

    /**
     * Determine if any consumers are waiting to pop.
     */
    public function hasWaitingConsumers(): bool
    {
        return $this->stats()['consumer_num'] > 0;
    }

    /**
     * Determine if the last failed operation was canceled.
     *
     * This state must be inspected immediately after a failed operation.
     */
    public function wasCanceled(): bool
    {
        return $this->errCode === SWOOLE_CHANNEL_CANCELED;
    }

    /**
     * Determine if the last failed operation timed out.
     *
     * This state must be inspected immediately after a failed operation.
     */
    public function timedOut(): bool
    {
        return $this->errCode === SWOOLE_CHANNEL_TIMEOUT;
    }
}
