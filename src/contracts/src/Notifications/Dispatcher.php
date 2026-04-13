<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Notifications;

interface Dispatcher
{
    /**
     * Send the given notification to the given notifiable entities.
     */
    public function send(mixed $notifiables, mixed $notification): void;

    /**
     * Send the given notification immediately.
     */
    public function sendNow(mixed $notifiables, mixed $notification, ?array $channels = null): void;
}
