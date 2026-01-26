<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Psr\Container\ContainerInterface;

/**
 * Registers the database connection resolver and event dispatcher on Eloquent Model.
 *
 * This listener fires on application boot and sets up the static connection
 * resolver and event dispatcher that all Eloquent models use.
 */
class RegisterConnectionResolverListener implements ListenerInterface
{
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        if ($this->container->has(ConnectionResolverInterface::class)) {
            Model::setConnectionResolver(
                $this->container->get(ConnectionResolverInterface::class)
            );
        }

        if ($this->container->has(Dispatcher::class)) {
            Model::setEventDispatcher(
                $this->container->get(Dispatcher::class)
            );
        }
    }
}
