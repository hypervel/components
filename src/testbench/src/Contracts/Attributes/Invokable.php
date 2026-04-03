<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Contracts\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;

/**
 * Interface for attributes that are directly invokable.
 */
interface Invokable extends TestingFeature
{
    /**
     * Handle the attribute.
     */
    public function __invoke(ApplicationContract $app): mixed;
}
