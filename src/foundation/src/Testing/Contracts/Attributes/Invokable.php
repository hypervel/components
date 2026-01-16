<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

/**
 * Interface for attributes that are directly invokable.
 */
interface Invokable extends TestingFeature
{
    /**
     * Handle the attribute.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     */
    public function __invoke($app): mixed;
}
