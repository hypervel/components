<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Container\Container;
use Hypervel\Contracts\Notifications\Dispatcher;
use InvalidArgumentException;

class AnonymousNotifiable
{
    /**
     * All of the notification routing information.
     */
    public array $routes = [];

    /**
     * Add routing information to the target.
     *
     * @throws InvalidArgumentException
     */
    public function route(string $channel, mixed $route): static
    {
        if ($channel === 'database') {
            throw new InvalidArgumentException('The database channel does not support on-demand notifications.');
        }

        $this->routes[$channel] = $route;

        return $this;
    }

    /**
     * Send the given notification.
     */
    public function notify(mixed $notification): void
    {
        Container::getInstance()
            ->make(Dispatcher::class)
            ->send($this, $notification);
    }

    /**
     * Send the given notification immediately.
     */
    public function notifyNow(mixed $notification): void
    {
        Container::getInstance()
            ->make(Dispatcher::class)
            ->sendNow($this, $notification);
    }

    /**
     * Get the notification routing information for the given driver.
     */
    public function routeNotificationFor(string $driver): mixed
    {
        return $this->routes[$driver] ?? null;
    }

    /**
     * Get the value of the notifiable's primary key.
     *
     * @return mixed
     */
    public function getKey()
    {
    }
}
