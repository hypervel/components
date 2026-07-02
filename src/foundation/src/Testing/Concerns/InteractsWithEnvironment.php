<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Support\Env;

trait InteractsWithEnvironment
{
    /**
     * Set an environment variable for the current PHP process.
     */
    protected function setEnvironmentValue(string $key, string $value): void
    {
        // putenv() alone is not enough: $_SERVER / $_ENV can shadow it, and Env caches its repository.
        putenv($key . '=' . $value);
        unset($_SERVER[$key], $_ENV[$key]);

        Env::flushRepository();
    }

    /**
     * Unset an environment variable for the current PHP process.
     */
    protected function unsetEnvironmentValue(string $key): void
    {
        putenv($key);
        unset($_SERVER[$key], $_ENV[$key]);

        Env::flushRepository();
    }
}
