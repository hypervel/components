<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Pipeline\Pipeline as BasePipeline;
use Hypervel\Container\Container;
use Hypervel\Support\Traits\Conditionable;

class Pipeline extends BasePipeline
{
    use Conditionable;

    public static function make(): static
    {
        return new static(
            Container::getInstance()
        );
    }
}
