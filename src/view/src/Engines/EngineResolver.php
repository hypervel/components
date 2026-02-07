<?php

declare(strict_types=1);

namespace Hypervel\View\Engines;

use Closure;
use Hypervel\View\Contracts\Engine;
use InvalidArgumentException;

class EngineResolver
{
    /**
     * The array of engine resolvers.
     */
    protected array $resolvers = [];

    /**
     * The resolved engine instances.
     */
    protected array $resolved = [];

    /**
     * Register a new engine resolver.
     *
     * The engine string typically corresponds to a file extension.
     */
    public function register(string $engine, Closure $resolver): void
    {
        $this->forget($engine);

        $this->resolvers[$engine] = $resolver;
    }

    /**
     * Resolve an engine instance by name.
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $engine): Engine
    {
        if (isset($this->resolved[$engine])) {
            return $this->resolved[$engine];
        }

        if (isset($this->resolvers[$engine])) {
            return $this->resolved[$engine] = call_user_func($this->resolvers[$engine]);
        }

        throw new InvalidArgumentException("Engine [{$engine}] not found.");
    }

    /**
     * Remove a resolved engine.
     */
    public function forget(string $engine): void
    {
        unset($this->resolved[$engine]);
    }
}
