<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

/**
 * Interface for attributes that run after all tests in a class.
 */
interface AfterAll extends TestingFeature
{
    /**
     * Handle the attribute.
     */
    public function afterAll(): void;
}
