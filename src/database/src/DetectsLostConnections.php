<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Container\Container;
use Hypervel\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Throwable;

trait DetectsLostConnections
{
    /**
     * Determine if the given exception was caused by a lost connection.
     */
    protected function causedByLostConnection(Throwable $e): bool
    {
        $container = Container::getInstance();

        $detector = $container->has(LostConnectionDetectorContract::class)
            ? $container->make(LostConnectionDetectorContract::class)
            : new LostConnectionDetector();

        return $detector->causedByLostConnection($e);
    }
}
