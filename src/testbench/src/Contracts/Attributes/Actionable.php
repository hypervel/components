<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Contracts\Attributes;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

/**
 * Interface for attributes that handle actions via a callback.
 */
interface Actionable extends TestingFeature
{
    /**
     * Handle the attribute.
     *
     * @param Closure(string, array<int, mixed>):void $action
     */
    public function handle(ApplicationContract $app, Closure $action): mixed;
}
