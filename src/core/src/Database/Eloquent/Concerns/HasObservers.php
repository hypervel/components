<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Collection\Arr;
use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\ObserverManager;
use RuntimeException;

trait HasObservers
{
    /**
     * Register observers with the model.
     *
     * @throws RuntimeException
     */
    public static function observe(array|object|string $classes): void
    {
        $manager = ApplicationContext::getContainer()
            ->get(ObserverManager::class);

        foreach (Arr::wrap($classes) as $class) {
            $manager->register(static::class, $class);
        }
    }
}
