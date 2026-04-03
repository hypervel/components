<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Contracts;

use Hypervel\Reverb\Application;

abstract class Connection
{
    /**
     * The last time the connection was seen.
     */
    protected ?int $lastSeenAt;

    /**
     * Stores the ping state of the connection.
     */
    protected bool $hasBeenPinged = false;

    /**
     * Indicates if the connection uses control frames.
     */
    protected bool $usesControlFrames = false;

    /**
     * Whether a connection slot was acquired for this connection.
     *
     * Tracked per-connection (not on the Server singleton) so concurrent
     * open() calls in coroutines don't overwrite each other's state.
     */
    protected bool $connectionSlotAcquired = false;

    /**
     * Create a new connection instance.
     */
    public function __construct(
        protected WebSocketConnection $connection,
        protected Application $application,
        protected ?string $origin,
    ) {
        $this->lastSeenAt = time();
    }

    /**
     * Get the raw socket connection identifier.
     */
    abstract public function identifier(): string;

    /**
     * Get the normalized socket ID.
     */
    abstract public function id(): string;

    /**
     * Send a message to the connection.
     */
    abstract public function send(string $message): void;

    /**
     * Send a control frame to the connection.
     */
    abstract public function control(int $opcode = WEBSOCKET_OPCODE_PING): void;

    /**
     * Terminate a connection.
     */
    abstract public function terminate(): void;

    /**
     * Get the application the connection belongs to.
     */
    public function app(): Application
    {
        return $this->application;
    }

    /**
     * Get the origin of the connection.
     */
    public function origin(): ?string
    {
        return $this->origin;
    }

    /**
     * Mark the connection as pinged.
     */
    public function ping(): void
    {
        $this->hasBeenPinged = true;
    }

    /**
     * Mark the connection as ponged.
     */
    public function pong(): void
    {
        $this->hasBeenPinged = false;
    }

    /**
     * Get the last time the connection was seen.
     */
    public function lastSeenAt(): ?int
    {
        return $this->lastSeenAt;
    }

    /**
     * Set the connection last seen at timestamp.
     */
    public function setLastSeenAt(int $time): static
    {
        $this->lastSeenAt = $time;

        return $this;
    }

    /**
     * Touch the connection last seen at timestamp.
     */
    public function touch(): static
    {
        $this->setLastSeenAt(time());
        $this->pong();

        return $this;
    }

    /**
     * Disconnect and unsubscribe from all channels.
     */
    public function disconnect(): void
    {
        $this->terminate();
    }

    /**
     * Determine whether the connection is still active.
     */
    public function isActive(): bool
    {
        return time() < $this->lastSeenAt + $this->app()->pingInterval();
    }

    /**
     * Determine whether the connection is inactive.
     */
    public function isInactive(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Determine whether the connection is stale.
     */
    public function isStale(): bool
    {
        return $this->isInactive() && $this->hasBeenPinged;
    }

    /**
     * Determine whether the connection uses control frames.
     */
    public function usesControlFrames(): bool
    {
        return $this->usesControlFrames;
    }

    /**
     * Mark the connection as using control frames to track activity.
     */
    public function setUsesControlFrames(bool $usesControlFrames = true): static
    {
        $this->usesControlFrames = $usesControlFrames;

        return $this;
    }

    /**
     * Whether a connection slot was acquired for this connection.
     */
    public function hasAcquiredConnectionSlot(): bool
    {
        return $this->connectionSlotAcquired;
    }

    /**
     * Mark that a connection slot was acquired for this connection.
     */
    public function markConnectionSlotAcquired(): void
    {
        $this->connectionSlotAcquired = true;
    }

    /**
     * Clear the connection slot acquired flag.
     */
    public function clearConnectionSlotAcquired(): void
    {
        $this->connectionSlotAcquired = false;
    }
}
