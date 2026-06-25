<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface Factory
{
    /**
     * Get a guard instance by name.
     */
    public function guard(?string $name = null): Guard|StatefulGuard;

    /**
     * Get the default authentication driver name.
     */
    public function getDefaultDriver(): string;

    /**
     * Set the default guard the factory should serve.
     */
    public function shouldUse(?string $name): void;
}
