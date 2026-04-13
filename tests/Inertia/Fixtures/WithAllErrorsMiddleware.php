<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

class WithAllErrorsMiddleware extends ExampleMiddleware
{
    protected bool $withAllErrors = true;
}
