<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

/**
 * Interface for attributes that run before each test.
 */
interface BeforeEach extends TestingFeature
{
    /**
     * Handle the attribute.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     */
    public function beforeEach($app): void;
}
