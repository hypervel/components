<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Notifications;

use UnitEnum;

interface Factory
{
    /**
     * Get a channel instance by name.
     */
    public function channel(UnitEnum|string|null $name = null): mixed;

    /**
     * Send the given notification to the given notifiable entities.
     */
    public function send(mixed $notifiables, mixed $notification): void;

    /**
     * Send the given notification immediately.
     */
    public function sendNow(mixed $notifiables, mixed $notification): void;
}
