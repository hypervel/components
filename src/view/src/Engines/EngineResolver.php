<?php

declare(strict_types=1);

namespace Hypervel\View\Engines;

use Closure;
use Hypervel\Contracts\View\Engine;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\BootOrTestsOnly;
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
     *
     * The resolver persists on the singleton EngineResolver for the worker
     * lifetime and applies to every subsequent engine resolution.
     */
    #[BootOnly(whenShared: true)]
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
     *
     * On the shared resolver, this clears an engine used by every coroutine;
     * the next resolution rebuilds it from its registered resolver.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function forget(string $engine): void
    {
        unset($this->resolved[$engine]);
    }
}
