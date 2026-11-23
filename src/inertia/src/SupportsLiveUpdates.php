<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Broadcasting\Channel;

interface SupportsLiveUpdates
{
    /**
     * Mark the property as live, refreshing it whenever one of the given events is received.
     *
     * @param null|array<int, class-string|object|string>|class-string|object|string $on
     * @param null|array<int, Channel|string>|Channel|string $channel
     */
    public function live(array|object|string|null $on = null, array|Channel|string|null $channel = null, ?int $throttle = null): static;

    /**
     * Determine if the property should receive live updates.
     */
    public function isLive(): bool;

    /**
     * Get the live-update listeners that should refresh the property.
     *
     * @return array<int, array{channel: array{name: string, type: string}, events: array<int, string>}>
     */
    public function liveListeners(): array;

    /**
     * Get the number of milliseconds to throttle live updates by.
     */
    public function liveThrottle(): ?int;
}
