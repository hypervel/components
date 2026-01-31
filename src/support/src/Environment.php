<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BadMethodCallException;
use Hypervel\Support\Traits\Macroable;

/**
 * @method bool isTesting()
 * @method bool isLocal()
 * @method bool isDevelop()
 * @method bool isProduction()
 */
class Environment
{
    use Macroable;

    public function __construct(
        protected ?string $env = null,
        protected ?bool $debug = null
    ) {
        $this->env = $env ?? env('APP_ENV', 'local');
        $this->debug = $debug ?? (bool) env('APP_DEBUG', false);
    }

    public function __call($method, $parameters)
    {
        if (Str::startsWith($method, 'is')) {
            return $this->is(Str::snake(substr($method, 2)));
        }

        throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
    }

    /**
     * Get the current application environment.
     */
    public function get(): ?string
    {
        return $this->env;
    }

    /**
     * Set the current application environment.
     */
    public function set(string $env): static
    {
        $this->env = $env;

        return $this;
    }

    /**
     * Set the current debug environment of application.
     */
    public function setDebug(bool $debug): static
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * check the current application environment.
     * @param string|string[] $environments
     */
    public function is(...$environments): bool
    {
        $patterns = is_array($environments[0]) ? $environments[0] : $environments;

        return Str::is($patterns, $this->env);
    }

    /**
     * Get the current debug environment of application.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }
}
