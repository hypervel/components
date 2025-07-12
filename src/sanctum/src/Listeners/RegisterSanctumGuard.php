<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Listeners;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Sanctum\SanctumGuard;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class RegisterSanctumGuard implements ListenerInterface
{
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    public function listen(): array
    {
        return [
            \Hyperf\Framework\Event\BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        $authManager = $this->container->get(AuthManager::class);
        $container = $this->container;

        $authManager->extend('sanctum', function ($name, $config) use ($authManager, $container) {
            $request = $container->get(RequestInterface::class);

            // Get the provider
            $provider = $authManager->createUserProvider($config['provider'] ?? null);

            // Get event dispatcher if available
            $events = null;
            if ($container->has(EventDispatcherInterface::class)) {
                $events = $container->get(EventDispatcherInterface::class);
            }

            // Get expiration from sanctum config
            $expiration = $container->get(ConfigInterface::class)->get('sanctum.expiration');

            return new SanctumGuard(
                name: $name,
                provider: $provider,
                request: $request,
                events: $events,
                expiration: $expiration
            );
        });
    }
}
