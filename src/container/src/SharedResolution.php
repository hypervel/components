<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Hypervel\Contracts\Container\BindingResolutionException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Coordinates one cacheable first resolution across coroutines.
 *
 * @internal
 */
class SharedResolution
{
    protected Channel $signal;

    protected bool $settled = false;

    protected mixed $value = null;

    protected ?Throwable $failure = null;

    public function __construct(
        public readonly int $ownerId,
    ) {
        $this->signal = new Channel(1);
    }

    /**
     * Publish the completed resolution and wake every waiter.
     */
    public function complete(mixed $value): void
    {
        $this->value = $value;
        $this->settled = true;
        $this->signal->close();
    }

    /**
     * Publish the resolution failure and wake every waiter.
     */
    public function fail(Throwable $failure): void
    {
        // The owner's cancellation belongs to the owner. Waiters see an ordinary
        // failure, so their own requests and jobs do not stop as if canceled.
        $this->failure = $failure instanceof CanceledException
            ? new BindingResolutionException('Shared container resolution was canceled before completion.', previous: $failure)
            : $failure;
        $this->settled = true;
        $this->signal->close();
    }

    /**
     * Wait for and return the completed resolution.
     *
     * @throws BindingResolutionException
     * @throws CanceledException
     * @throws Throwable
     */
    public function await(): mixed
    {
        if (! $this->settled) {
            $this->signal->pop();
        }

        // The owner always settles before waking waiters, so an unsettled wake is this waiter's cancellation.
        if (! $this->settled) {
            throw new CanceledException('Waiting for a shared container resolution was canceled.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->value;
    }
}
