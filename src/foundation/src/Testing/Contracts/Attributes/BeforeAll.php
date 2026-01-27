<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

/**
 * Interface for attributes that run before all tests in a class.
 */
interface BeforeAll extends TestingFeature
{
    /**
     * Handle the attribute.
     */
    public function beforeAll(): void;
}
