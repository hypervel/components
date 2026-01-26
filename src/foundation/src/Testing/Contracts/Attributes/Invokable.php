<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

use Hypervel\Foundation\Contracts\Application as ApplicationContract;

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
