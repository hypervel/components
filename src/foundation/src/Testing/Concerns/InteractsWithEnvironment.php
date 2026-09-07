<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Closure;
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

    /**
     * Run a callback with a temporary environment variable value.
     */
    protected function withEnvironmentValue(string $key, ?string $value, Closure $callback): mixed
    {
        return $this->withEnvironmentValues([$key => $value], $callback);
    }

    /**
     * Run a callback with temporary environment variable values.
     *
     * @param array<string, null|string> $values
     */
    protected function withEnvironmentValues(array $values, Closure $callback): mixed
    {
        $originalValues = [];

        foreach ($values as $key => $value) {
            $originalValues[$key] = [
                'putenv' => getenv($key),
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
            ];

            $value === null
                ? putenv($key)
                : putenv("{$key}={$value}");
            unset($_SERVER[$key], $_ENV[$key]);
        }

        Env::flushRepository();

        try {
            return $callback();
        } finally {
            foreach ($originalValues as $key => $value) {
                $value['putenv'] === false
                    ? putenv($key)
                    : putenv("{$key}={$value['putenv']}");

                if ($value['server_exists']) {
                    $_SERVER[$key] = $value['server'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($value['env_exists']) {
                    $_ENV[$key] = $value['env'];
                } else {
                    unset($_ENV[$key]);
                }
            }

            Env::flushRepository();
        }
    }
}
