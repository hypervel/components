<?php

declare(strict_types=1);

use Hypervel\Container\Container;
use Hypervel\Pennant\FeatureManager;

if (! function_exists('feature')) {
    /**
     * Get the feature manager instance.
     *
     * @return FeatureManager|mixed
     */
    function feature(UnitEnum|string|null $name = null, ?Closure $whenActive = null, ?Closure $whenInactive = null): mixed
    {
        $manager = Container::getInstance()->make(FeatureManager::class);

        return match (func_num_args()) {
            0 => $manager,
            1 => $manager->value(...func_get_args()),
            default => $manager->when(...func_get_args()),
        };
    }
}
