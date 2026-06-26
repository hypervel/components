<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelRunner;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

class ParallelRunnerTest extends TestCase
{
    private mixed $originalAppBasePathEnvironment;

    private mixed $originalAppBasePathServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppBasePathEnvironment = $_ENV['APP_BASE_PATH'] ?? null;
        $this->originalAppBasePathServer = $_SERVER['APP_BASE_PATH'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restoreAppBasePath();

        parent::tearDown();
    }

    #[Test]
    public function itCreatesTheApplicationFromTheInferredBasePath(): void
    {
        $_ENV['APP_BASE_PATH'] = $this->app->basePath();
        $_SERVER['APP_BASE_PATH'] = $this->app->basePath();

        $runner = (new ReflectionClass(ParallelRunner::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ParallelRunner::class, 'createApplication');

        try {
            /** @var ApplicationContract $createdApplication */
            $createdApplication = $method->invoke($runner);

            $this->assertSame($this->app->basePath(), $createdApplication->basePath());
        } finally {
            Container::setInstance($this->app);
        }
    }

    /**
     * Restore the APP_BASE_PATH values.
     */
    protected function restoreAppBasePath(): void
    {
        if ($this->originalAppBasePathEnvironment === null) {
            unset($_ENV['APP_BASE_PATH']);
        } else {
            $_ENV['APP_BASE_PATH'] = $this->originalAppBasePathEnvironment;
        }

        if ($this->originalAppBasePathServer === null) {
            unset($_SERVER['APP_BASE_PATH']);
        } else {
            $_SERVER['APP_BASE_PATH'] = $this->originalAppBasePathServer;
        }
    }
}
