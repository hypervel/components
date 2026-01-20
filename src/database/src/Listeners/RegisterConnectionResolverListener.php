<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hypervel\Database\ConnectionResolverInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hypervel\Database\Eloquent\Model;
use Psr\Container\ContainerInterface;

/**
 * Registers the database connection resolver on Hypervel's Eloquent Model.
 *
 * This listener fires on application boot and sets up the static connection
 * resolver that all Eloquent models use to obtain database connections.
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
    }
}
