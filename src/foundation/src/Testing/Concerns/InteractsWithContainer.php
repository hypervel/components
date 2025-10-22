<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Closure;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Dispatcher\HttpDispatcher;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Foundation\Testing\Dispatcher\HttpDispatcher as TestingHttpDispatcher;
use Hypervel\Foundation\Vite;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\HtmlString;
use Mockery;
use Mockery\MockInterface;

trait InteractsWithContainer
{
    protected ?ApplicationContract $app = null;

    protected $originalVite;

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
        $this->app->set($abstract, $instance);

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
     * Register an empty handler for Vite in the container.
     *
     * @return $this
     */
    protected function withoutVite(): static
    {
        if ($this->originalVite == null) {
            $this->originalVite = app(Vite::class);
        }

        Facade::clearResolvedInstance(Vite::class);

        $this->swap(Vite::class, new class extends Vite {
            public function __invoke(array|string $entrypoints, ?string $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }

            public function __call($method, $parameters): string
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function useIntegrityKey(bool|string $key): static
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

            public function useScriptTagAttributes(callable|array $attributes): static
            {
                return $this;
            }

            public function useStyleTagAttributes(callable|array $attributes): static
            {
                return $this;
            }

            public function usePreloadTagAttributes(callable|array|false $attributes): static
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

    protected function flushApplication(): void
    {
        $this->app = null;
    }

    protected function refreshApplication(): void
    {
        $this->app = $this->createApplication();
        /* @phpstan-ignore-next-line */
        $this->app->bind(HttpDispatcher::class, TestingHttpDispatcher::class);
        $this->app->bind(ConnectionResolverInterface::class, DatabaseConnectionResolver::class);
        $this->app->get(ApplicationInterface::class);
    }

    protected function createApplication(): ApplicationContract
    {
        return require BASE_PATH . '/bootstrap/app.php';
    }
}
