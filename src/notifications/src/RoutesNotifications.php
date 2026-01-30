<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Notifications\Dispatcher;
use Hypervel\Support\Str;

trait RoutesNotifications
{
    /**
     * Send the given notification.
     */
    public function notify(mixed $instance): void
    {
        ApplicationContext::getContainer()
            ->get(Dispatcher::class)
            ->send($this, $instance);
    }

    /**
     * Send the given notification immediately.
     */
    public function notifyNow(mixed $instance, ?array $channels = null): void
    {
        ApplicationContext::getContainer()
            ->get(Dispatcher::class)
            ->sendNow($this, $instance, $channels);
    }

    /**
     * Get the notification routing information for the given driver.
     */
    public function routeNotificationFor(string $driver, ?Notification $notification = null): mixed
    {
        if (method_exists($this, $method = 'routeNotificationFor' . Str::studly($driver))) {
            return $this->{$method}($notification);
        }

        return match ($driver) {
            'database' => $this->notifications(),
            'mail' => $this->email,
            default => null,
        };
    }
}
