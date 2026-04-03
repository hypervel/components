<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Notifications;

use Hypervel\Support\Collection;

interface Factory
{
    /**
     * Get a channel instance by name.
     */
    public function channel(?string $name = null): mixed;

    /**
     * Send the given notification to the given notifiable entities.
     */
    public function send(array|Collection $notifiables, mixed $notification): void;

    /**
     * Send the given notification immediately.
     */
    public function sendNow(array|Collection $notifiables, mixed $notification): void;
}
