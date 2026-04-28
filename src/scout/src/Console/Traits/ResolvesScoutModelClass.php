<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console\Traits;

use Hypervel\Scout\Exceptions\ScoutException;

/**
 * Resolves a model argument to a fully-qualified class name.
 *
 * Used by Scout console commands that take a model class as their first
 * argument. Accepts either a fully-qualified class name (e.g. "App\Models\Post")
 * or a short name that resolves under the conventional "App\Models" namespace
 * (e.g. "Post").
 */
trait ResolvesScoutModelClass
{
    /**
     * Resolve the fully-qualified model class name.
     *
     * @throws ScoutException
     */
    protected function resolveModelClass(string $class): string
    {
        if (class_exists($class)) {
            return $class;
        }

        $namespacedClass = app()->getNamespace() . 'Models\\' . $class;

        if (class_exists($namespacedClass)) {
            return $namespacedClass;
        }

        throw new ScoutException("Model [{$class}] not found.");
    }
}
