<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

use UnitEnum;

interface Factory
{
    /**
     * Get a guard instance by name.
     */
    public function guard(UnitEnum|string|null $name = null): Guard|StatefulGuard;

    /**
     * Get the default authentication driver name.
     */
    public function getDefaultDriver(): string;

    /**
     * Set the default guard the factory should serve.
     */
    public function shouldUse(UnitEnum|string|null $name): void;
}
