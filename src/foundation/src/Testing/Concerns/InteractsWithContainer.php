<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Closure;
use Hypervel\Foundation\Vite;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Vite as ViteFacade;
use Hypervel\Support\HtmlString;
use Mockery;
use Mockery\MockInterface;

trait InteractsWithContainer
{
    /**
     * The original Vite handler.
     */
    protected ?Vite $originalVite = null;

    /**
     * The original deferred callbacks collection.
     */
    protected ?DeferredCallbackCollection $originalDeferredCallbacksCollection = null;

    /**
     * Register an instance in the container.
     *
     * @template TInstance
     *
     * @param TInstance $instance
     * @return TInstance
     */
    protected function swap(string $abstract, mixed $instance): mixed
    {
        return $this->instance($abstract, $instance);
    }

    /**
     * Register an instance in the container.
     *
     * @template TInstance
     *
     * @param TInstance $instance
     * @return TInstance
     */
    protected function instance(string $abstract, mixed $instance): mixed
    {
        $this->app->instance($abstract, $instance);

        return $instance;
    }

    /**
     * Mock an instance of an object in the container.
     *
     * @template TInstance of object
     *
     * @param class-string<TInstance>|string $abstract
     * @return ($abstract is class-string<TInstance> ? MockInterface&TInstance : MockInterface)
     */
    protected function mock(string $abstract, ?Closure $mock = null): MockInterface
    {
        return $this->instance($abstract, Mockery::mock(...array_filter(func_get_args())));
    }

    /**
     * Mock a partial instance of an object in the container.
     *
     * @template TInstance of object
     *
     * @param class-string<TInstance>|string $abstract
     * @return ($abstract is class-string<TInstance> ? MockInterface&TInstance : MockInterface)
     */
    protected function partialMock(string $abstract, ?Closure $mock = null): MockInterface
    {
        return $this->instance($abstract, Mockery::mock(...array_filter(func_get_args()))->makePartial());
    }

    /**
     * Spy an instance of an object in the container.
     *
     * @template TInstance of object
     *
     * @param class-string<TInstance>|string $abstract
     * @return ($abstract is class-string<TInstance> ? MockInterface&TInstance : MockInterface)
     */
    protected function spy(string $abstract, ?Closure $mock = null): MockInterface
    {
        return $this->instance($abstract, Mockery::spy(...array_filter(func_get_args())));
    }

    /**
     * Instruct the container to forget a previously mocked / spied instance of an object.
     *
     * @return $this
     */
    protected function forgetMock(string $abstract): static
    {
        $this->app->forgetInstance($abstract);

        return $this;
    }

    /**
     * Register a Vite handler that returns empty strings for all assets.
     *
     * @return $this
     */
    protected function withoutVite(): static
    {
        if ($this->originalVite === null) {
            $this->originalVite = app(Vite::class);
        }

        ViteFacade::clearResolvedInstance();

        $this->swap(Vite::class, new class extends Vite {
            /**
             * Return empty asset markup.
             */
            public function __invoke(string|array $entrypoints, ?string $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }

            /**
             * Return an empty result for dynamic calls.
             */
            public function __call(string $method, array $parameters): mixed
            {
                return '';
            }

            /**
             * Return empty asset markup as a string.
             */
            public function __toString(): string
            {
                return '';
            }

            /**
             * Ignore integrity key configuration.
             */
            public function useIntegrityKey(string|false $key): static
            {
                return $this;
            }

            /**
             * Ignore build directory configuration.
             */
            public function useBuildDirectory(string $path): static
            {
                return $this;
            }

            /**
             * Ignore hot file configuration.
             */
            public function useHotFile(string $path): static
            {
                return $this;
            }

            /**
             * Ignore entry point configuration.
             */
            public function withEntryPoints(array $entryPoints): static
            {
                return $this;
            }

            /**
             * Ignore script tag attributes.
             */
            public function useScriptTagAttributes(array|callable $attributes): static
            {
                return $this;
            }

            /**
             * Ignore style tag attributes.
             */
            public function useStyleTagAttributes(array|callable $attributes): static
            {
                return $this;
            }

            /**
             * Ignore preload tag attributes.
             */
            public function usePreloadTagAttributes(array|callable|false $attributes): static
            {
                return $this;
            }

            /**
             * Return an empty list of preloaded assets.
             */
            public function preloadedAssets(): array
            {
                return [];
            }

            /**
             * Return empty React refresh markup.
             */
            public function reactRefresh(): HtmlString
            {
                return new HtmlString('');
            }

            /**
             * Return empty asset content.
             */
            public function content(string $asset, ?string $buildDirectory = null): string
            {
                return '';
            }

            /**
             * Return an empty asset URL.
             */
            public function asset(string $asset, ?string $buildDirectory = null): string
            {
                return '';
            }
        });

        return $this;
    }

    /**
     * Restore Vite in the container.
     *
     * @return $this
     */
    protected function withVite(): static
    {
        if ($this->originalVite) {
            $this->app->instance(Vite::class, $this->originalVite);
        }

        return $this;
    }

    /**
     * Execute deferred callbacks immediately.
     */
    protected function withoutDefer(): static
    {
        if ($this->originalDeferredCallbacksCollection === null) {
            $this->originalDeferredCallbacksCollection = $this->app->make(DeferredCallbackCollection::class);
        }

        $this->app->forgetInstance(DeferredCallbackCollection::class);

        $this->swap(DeferredCallbackCollection::class, new class extends DeferredCallbackCollection {
            /**
             * Set the callback with the given key.
             */
            public function offsetSet(mixed $offset, mixed $value): void
            {
                $value();
            }
        });

        return $this;
    }

    /**
     * Restore deferred callbacks.
     */
    protected function withDefer(): static
    {
        $this->app->forgetInstance(DeferredCallbackCollection::class);

        if ($this->originalDeferredCallbacksCollection !== null) {
            $this->app->instance(DeferredCallbackCollection::class, $this->originalDeferredCallbacksCollection);
        }

        return $this;
    }
}
