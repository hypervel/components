<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;

/**
 * Interface for attributes that run before each test.
 */
interface BeforeEach extends TestingFeature
{
    /**
     * Handle the attribute.
     */
    public function beforeEach(ApplicationContract $app): void;
}
