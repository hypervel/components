<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

use Closure;

/**
 * Interface for attributes that handle actions via a callback.
 */
interface Actionable extends TestingFeature
{
    /**
     * Handle the attribute.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     * @param \Closure(string, array<int, mixed>):void $action
     */
    public function handle($app, Closure $action): mixed;
}
