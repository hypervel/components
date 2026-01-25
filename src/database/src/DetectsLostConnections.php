<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hyperf\Context\ApplicationContext;
use Hypervel\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Hypervel\Database\LostConnectionDetector;
use Throwable;

trait DetectsLostConnections
{
    /**
     * Determine if the given exception was caused by a lost connection.
     */
    protected function causedByLostConnection(Throwable $e): bool
    {
        $container = ApplicationContext::getContainer();

        $detector = $container->has(LostConnectionDetectorContract::class)
            ? $container->get(LostConnectionDetectorContract::class)
            : new LostConnectionDetector();

        return $detector->causedByLostConnection($e);
    }
}
