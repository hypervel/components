<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Support\AggregateServiceProvider;

class TestingServiceProvider extends AggregateServiceProvider
{
    /**
     * The provider class names.
     *
     * @var array<int, class-string<\Hypervel\Support\ServiceProvider>>
     */
    protected array $providers = [
        ParallelTestingServiceProvider::class,
    ];
}
