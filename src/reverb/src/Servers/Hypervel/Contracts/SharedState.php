<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Contracts;

use Hypervel\Reverb\Servers\Hypervel\Scaling\SubscriptionResult;

interface SharedState
{
    /**
     * Record a channel subscription and return the transition result.
     */
    public function subscribe(string $appId, string $channel, ?string $userId = null): SubscriptionResult;

    /**
     * Record a channel unsubscription and return the transition result.
     */
    public function unsubscribe(string $appId, string $channel, ?string $userId = null): SubscriptionResult;

    /**
     * Attempt to acquire a connection slot for the given app.
     *
     * Returns true if the slot was acquired, false if the limit is reached.
     */
    public function acquireConnectionSlot(string $appId, int $maxConnections): bool;

    /**
     * Release a connection slot for the given app.
     */
    public function releaseConnectionSlot(string $appId): void;
}
