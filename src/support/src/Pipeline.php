<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Support\Traits\Conditionable;
use Hyperf\Pipeline\Pipeline as BasePipeline;
use Hypervel\Context\ApplicationContext;

class Pipeline extends BasePipeline
{
    use Conditionable;

    public static function make(): static
    {
        return new static(
            ApplicationContext::getContainer()
        );
    }
}
