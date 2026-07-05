<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelRunner;
use ParaTest\Options;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

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

    #[Test]
    public function itResolvesProcessTokensAsStrings(): void
    {
        $tokens = [];

        ParallelRunner::resolveApplicationUsing(fn () => new Application($this->app->basePath()));

        $runner = new ParallelRunner($this->optionsWithProcesses(2), new BufferedOutput);
        $method = new ReflectionMethod(ParallelRunner::class, 'forEachProcess');

        try {
            $method->invoke($runner, function () use (&$tokens): void {
                $tokens[] = ParallelTesting::token();
            });
        } finally {
            ParallelRunner::resolveApplicationUsing(null);
            ParallelTesting::resolveTokenUsing(null);
        }

        $this->assertSame(['1', '2'], $tokens);
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

    /**
     * Get ParaTest options with the given process count.
     */
    protected function optionsWithProcesses(int $processes): Options
    {
        $inputDefinition = new InputDefinition;
        Options::setInputDefinition($inputDefinition);

        return Options::fromConsoleInput(
            new ArgvInput([
                'paratest',
                '--configuration=' . dirname(__DIR__, 2) . '/phpunit.xml.dist',
                '--runner=' . ParallelRunner::class,
                '--processes=' . $processes,
            ], $inputDefinition),
            dirname(__DIR__, 2),
        );
    }
}
