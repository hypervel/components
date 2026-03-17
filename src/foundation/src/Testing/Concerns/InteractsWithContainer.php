<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Closure;
use Hypervel\Foundation\Vite;
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
     * Register an instance of an object in the container.
     *
     * @param object $instance
     * @return object
     */
    protected function swap(string $abstract, mixed $instance): mixed
    {
        return $this->instance($abstract, $instance);
    }

    /**
     * Register an instance of an object in the container.
     *
     * @param object $instance
     * @return object
     */
    protected function instance(string $abstract, mixed $instance): mixed
    {
        /* @phpstan-ignore-next-line */
        $this->app->instance($abstract, $instance);

        return $instance;
    }

    /**
     * Mock an instance of an object in the container.
     */
    protected function mock(string $abstract, ?Closure $mock = null): MockInterface
    {
        return $this->instance($abstract, Mockery::mock(...array_filter(func_get_args())));
    }

    /**
     * Mock a partial instance of an object in the container.
     */
    protected function partialMock(string $abstract, ?Closure $mock = null): MockInterface
    {
        return $this->instance($abstract, Mockery::mock(...array_filter(func_get_args()))->makePartial());
    }

    /**
     * Spy an instance of an object in the container.
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
            public function __invoke(string|array $entrypoints, ?string $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }

            public function __call(string $method, array $parameters): mixed
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function useIntegrityKey(string|false $key): static
            {
                return $this;
            }

            public function useBuildDirectory(string $path): static
            {
                return $this;
            }

            public function useHotFile(string $path): static
            {
                return $this;
            }

            public function withEntryPoints(array $entryPoints): static
            {
                return $this;
            }

            public function useScriptTagAttributes(array|callable $attributes): static
            {
                return $this;
            }

            public function useStyleTagAttributes(array|callable $attributes): static
            {
                return $this;
            }

            public function usePreloadTagAttributes(array|callable|false $attributes): static
            {
                return $this;
            }

            public function preloadedAssets(): array
            {
                return [];
            }

            public function reactRefresh(): ?HtmlString
            {
                return new HtmlString('');
            }

            public function content(string $asset, ?string $buildDirectory = null): string
            {
                return '';
            }

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
}
