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
use RuntimeException;
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

    #[Test]
    public function itRestoresTheAmbientTokenResolverAfterEachProcess(): void
    {
        $tokens = [];
        $applications = [
            new ParallelRunnerFlushTrackingApplication($this->app->basePath()),
            new ParallelRunnerFlushTrackingApplication($this->app->basePath()),
        ];
        $previousServerToken = is_string($_SERVER['TEST_TOKEN'] ?? null) ? $_SERVER['TEST_TOKEN'] : null;

        $_SERVER['TEST_TOKEN'] = 'ambient';
        ParallelRunner::resolveApplicationUsing(static fn () => array_shift($applications));

        $runner = new ParallelRunner($this->optionsWithProcesses(2), new BufferedOutput);
        $method = new ReflectionMethod(ParallelRunner::class, 'forEachProcess');

        try {
            $method->invoke($runner, function () use (&$tokens): void {
                $tokens[] = ParallelTesting::token();
            });

            $this->assertSame(['1', '2'], $tokens);
            $this->assertSame('ambient', ParallelTesting::token());
        } finally {
            ParallelRunner::resolveApplicationUsing(null);
            ParallelTesting::resolveTokenUsing(null);
            $this->restoreServerTestToken($previousServerToken);
        }
    }

    #[Test]
    public function itClearsTheTokenResolverAndFlushesTheApplicationWhenAProcessCallbackFails(): void
    {
        $application = new ParallelRunnerFlushTrackingApplication($this->app->basePath());
        $previousServerToken = is_string($_SERVER['TEST_TOKEN'] ?? null) ? $_SERVER['TEST_TOKEN'] : null;

        $_SERVER['TEST_TOKEN'] = 'ambient';
        ParallelRunner::resolveApplicationUsing(static fn () => $application);

        $runner = new ParallelRunner($this->optionsWithProcesses(1), new BufferedOutput);
        $method = new ReflectionMethod(ParallelRunner::class, 'forEachProcess');

        try {
            $method->invoke($runner, static function (): never {
                throw new RuntimeException('process callback failed');
            });

            $this->fail('The process callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('process callback failed', $exception->getMessage());
            $this->assertTrue($application->flushed);
            $this->assertSame('ambient', ParallelTesting::token());
        } finally {
            ParallelRunner::resolveApplicationUsing(null);
            ParallelTesting::resolveTokenUsing(null);
            $this->restoreServerTestToken($previousServerToken);
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

    /**
     * Restore the TEST_TOKEN server value.
     */
    protected function restoreServerTestToken(?string $token): void
    {
        if ($token === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $token;
        }
    }
}

class ParallelRunnerFlushTrackingApplication extends Application
{
    public bool $flushed = false;

    /**
     * Flush the container of all bindings and resolved instances.
     */
    public function flush(): void
    {
        $this->flushed = true;

        parent::flush();
    }
}
