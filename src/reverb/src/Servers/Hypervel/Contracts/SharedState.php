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

    /**
     * Get the current subscription count for a channel.
     */
    public function getSubscriptionCount(string $appId, string $channel): int;

    /**
     * Get the current subscription count for a specific user in a channel.
     */
    public function getUserSubscriptionCount(string $appId, string $channel, string $userId): int;

    /**
     * Attempt to acquire a subscription_count webhook throttle lock.
     *
     * Returns true if the lock was acquired (fire the webhook), false if
     * the lock is still held (suppress the webhook). Lock expires after
     * the given TTL in milliseconds.
     */
    public function trySubscriptionCountLock(string $appId, string $channel, int $ttlMs = 5000): bool;

    /**
     * Attempt to acquire a cache_miss webhook dedupe lock.
     *
     * Returns true if the lock was acquired (fire the webhook), false if
     * already locked (suppress duplicate). Lock expires after TTL.
     */
    public function tryCacheMissLock(string $appId, string $channel, int $ttlMs = 10000): bool;

    /**
     * Clear the cache_miss dedupe lock for a channel.
     */
    public function clearCacheMissLock(string $appId, string $channel): void;

    /**
     * Clear the subscription_count throttle lock for a channel.
     */
    public function clearSubscriptionCountLock(string $appId, string $channel): void;

    /**
     * Mark a channel as having a pending deferred channel_vacated webhook.
     *
     * Set when disconnect smoothing defers the vacated webhook. The marker
     * is consumed by subscribe to suppress the false channel_occupied that
     * would otherwise fire on reconnect within the smoothing window.
     */
    public function setSmoothingPending(string $appId, string $channel, int $ttlMs): void;

    /**
     * Atomically consume a channel smoothing marker if it is still live.
     *
     * Returns true if an unexpired marker was found and consumed (suppress
     * channel_occupied). Returns false if no marker exists or it has expired.
     */
    public function clearSmoothingPending(string $appId, string $channel, int $ttlMs): bool;

    /**
     * Mark a presence channel member as having a pending deferred member_removed webhook.
     */
    public function setMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): void;

    /**
     * Atomically consume a member smoothing marker if it is still live.
     *
     * Returns true if an unexpired marker was found and consumed (suppress
     * member_added). Returns false if no marker exists or it has expired.
     */
    public function clearMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): bool;
}
