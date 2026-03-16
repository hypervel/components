<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Mockery;
use Mockery\MockInterface;

trait InteractsWithContainer
{
    protected ?ApplicationContract $app = null;

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

    protected function flushApplication(): void
    {
        $this->app->flush();

        $this->app = null;
    }

    /**
     * Refresh the application instance.
     */
    protected function refreshApplication(): void
    {
        $this->app = $this->createApplication();
    }

    /**
     * Create the application.
     */
    protected function createApplication(): ApplicationContract
    {
        return require BASE_PATH . '/bootstrap/app.php';
    }
}
