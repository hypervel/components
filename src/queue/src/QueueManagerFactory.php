<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Debug\ExceptionHandler;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Throwable;

class QueueManagerFactory
{
    public function __invoke(ContainerInterface $container): QueueManager
    {
        $manager = new QueueManager($container);
        if (! $container->has(ExceptionHandler::class)) {
            return $manager;
        }

        $connectors = ['coroutine', 'defer'];
        $reportHandler = fn (Throwable $e) => $container->get(ExceptionHandler::class)->report($e);
        foreach ($connectors as $connector) {
            try {
                $manager->connection($connector) // @phpstan-ignore-line
                    ->setExceptionCallback($reportHandler);
            } catch (InvalidArgumentException) {
                // Ignore exception when the connector is not configured.
            }
        }

        return $manager;
    }
}
