<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

/**
 * Interface for attributes that run after each test.
 */
interface AfterEach extends TestingFeature
{
    /**
     * Handle the attribute.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     */
    public function afterEach($app): void;
}
