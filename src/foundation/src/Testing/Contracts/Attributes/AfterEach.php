<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;

/**
 * Interface for attributes that run after each test.
 */
interface AfterEach extends TestingFeature
{
    /**
     * Handle the attribute.
     */
    public function afterEach(ApplicationContract $app): void;
}
