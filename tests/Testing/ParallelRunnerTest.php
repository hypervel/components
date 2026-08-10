<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelRunner;
use ParaTest\Options;
use ParaTest\RunnerInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class ParallelRunnerTest extends TestCase
{
    /** @var array<string, array{bool, mixed}> */
    private array $originalEnvironment;

    /** @var array<string, array{bool, mixed}> */
    private array $originalServer;

    /** @var array<string, false|string> */
    private array $originalProcessEnvironment;

    private ContainerContract $originalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->snapshotArrayValues($_ENV, [
            'APP_BASE_PATH',
            'COLUMNS',
            'LINES',
            'TEST_TOKEN',
        ]);
        $this->originalServer = $this->snapshotArrayValues($_SERVER, [
            'APP_BASE_PATH',
            'HYPERVEL_PARALLEL_TESTING',
            'TEST_TOKEN',
        ]);
        $this->originalProcessEnvironment = [
            'COLUMNS' => getenv('COLUMNS'),
            'LINES' => getenv('LINES'),
        ];
        $this->originalContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        ParallelRunner::resolveApplicationUsing(null);
        ParallelRunner::resolveRunnerUsing(null);
        ParallelTesting::resolveTokenUsing(null);

        $this->restoreArrayValues($_ENV, $this->originalEnvironment);
        $this->restoreArrayValues($_SERVER, $this->originalServer);

        foreach ($this->originalProcessEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }

        Container::setInstance($this->originalContainer);

        parent::tearDown();
    }

    #[Test]
    public function itCreatesTheApplicationFromTheInferredBasePath(): void
    {
        $_ENV['APP_BASE_PATH'] = $this->app->basePath();
        $_SERVER['APP_BASE_PATH'] = $this->app->basePath();

        $runner = (new ReflectionClass(ParallelRunner::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ParallelRunner::class, 'createApplication');

        /** @var ApplicationContract $createdApplication */
        $createdApplication = $method->invoke($runner);

        $this->assertSame($this->app->basePath(), $createdApplication->basePath());
    }

    #[Test]
    public function itResolvesProcessTokensAsStrings(): void
    {
        $tokens = [];

        ParallelRunner::resolveApplicationUsing(fn () => new Application($this->app->basePath()));

        $runner = new ParallelRunner($this->optionsWithProcesses(2), new BufferedOutput);
        $method = new ReflectionMethod(ParallelRunner::class, 'forEachProcess');

        $method->invoke($runner, function () use (&$tokens): void {
            $tokens[] = ParallelTesting::token();
        });

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
        $_SERVER['TEST_TOKEN'] = 'ambient';
        ParallelRunner::resolveApplicationUsing(static fn () => array_shift($applications));

        $runner = new ParallelRunner($this->optionsWithProcesses(2), new BufferedOutput);
        $method = new ReflectionMethod(ParallelRunner::class, 'forEachProcess');

        $method->invoke($runner, function () use (&$tokens): void {
            $tokens[] = ParallelTesting::token();
        });

        $this->assertSame(['1', '2'], $tokens);
        $this->assertSame('ambient', ParallelTesting::token());
    }

    #[Test]
    public function itClearsTheTokenResolverAndCleansUpTheApplicationWhenAProcessCallbackFails(): void
    {
        $application = new ParallelRunnerFlushTrackingApplication($this->app->basePath());
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
            $this->assertTrue($application->terminated);
            $this->assertTrue($application->flushed);
            $this->assertSame(['terminate', 'flush'], $application->lifecycle);
            $this->assertSame('ambient', $application->terminationToken);
            $this->assertSame('ambient', ParallelTesting::token());
        }
    }

    #[Test]
    public function itRunsSetupRunnerAndTeardownWithFreshApplicationsInTokenOrder(): void
    {
        $events = [];
        $runner = new ParallelRunnerStub(1, onRun: function () use (&$events): void {
            $events[] = 'runner';
        });
        $createdApplications = $this->trackingApplications(4);

        ParallelTesting::setUpProcess(function (string $token) use (&$events): void {
            $events[] = "setup:{$token}";
        });
        ParallelTesting::tearDownProcess(function (string $token) use (&$events): void {
            $events[] = "teardown:{$token}";
        });

        $this->resolveRunnerUsing($runner);
        $this->resolveApplicationsUsing($createdApplications);

        $exitCode = (new ParallelRunner($this->optionsWithProcesses(2), new BufferedOutput))->execute();

        $this->assertSame(1, $exitCode);
        $this->assertSame(
            ['setup:1', 'setup:2', 'runner', 'teardown:1', 'teardown:2'],
            $events,
        );
        $this->assertSame(1, $runner->runCount);
        $this->assertCount(4, array_unique(array_map(spl_object_id(...), $createdApplications)));
        $this->assertContainsOnlyInstancesOf(ParallelRunnerFlushTrackingApplication::class, $createdApplications);
        $this->assertSame([true, true, true, true], array_map(
            static fn (ParallelRunnerFlushTrackingApplication $application): bool => $application->flushed,
            $createdApplications,
        ));
    }

    #[Test]
    public function itTearsDownEverySetupEnteredTokenAfterSetupFails(): void
    {
        $events = [];
        $runner = new ParallelRunnerStub;
        $createdApplications = $this->trackingApplications(4);

        ParallelTesting::setUpProcess(function (string $token) use (&$events): void {
            $events[] = "setup:{$token}";

            if ($token === '2') {
                throw new RuntimeException('setup failed');
            }
        });
        ParallelTesting::tearDownProcess(function (string $token) use (&$events): void {
            $events[] = "teardown:{$token}";
        });

        $this->resolveRunnerUsing($runner);
        $this->resolveApplicationsUsing($createdApplications);

        try {
            (new ParallelRunner($this->optionsWithProcesses(3), new BufferedOutput))->execute();
            $this->fail('The setup exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('setup failed', $exception->getMessage());
        }

        $this->assertSame(['setup:1', 'setup:2', 'teardown:1', 'teardown:2'], $events);
        $this->assertSame(0, $runner->runCount);
        $this->assertSame([true, true, true, true], array_map(
            static fn (ParallelRunnerFlushTrackingApplication $application): bool => $application->flushed,
            $createdApplications,
        ));
    }

    #[Test]
    public function itDoesNotTearDownATokenWhoseApplicationCouldNotBeCreated(): void
    {
        $events = [];
        $runner = new ParallelRunnerStub;
        $setupApplication = new ParallelRunnerFlushTrackingApplication($this->app->basePath());
        $teardownApplication = new ParallelRunnerFlushTrackingApplication($this->app->basePath());

        ParallelTesting::setUpProcess(function (string $token) use (&$events): void {
            $events[] = "setup:{$token}";
        });
        ParallelTesting::tearDownProcess(function (string $token) use (&$events): void {
            $events[] = "teardown:{$token}";
        });

        $this->resolveRunnerUsing($runner);
        $this->resolveApplicationsUsing([
            $setupApplication,
            new RuntimeException('application failed'),
            $teardownApplication,
        ]);

        try {
            (new ParallelRunner($this->optionsWithProcesses(3), new BufferedOutput))->execute();
            $this->fail('The application exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('application failed', $exception->getMessage());
        }

        $this->assertSame(['setup:1', 'teardown:1'], $events);
        $this->assertSame(0, $runner->runCount);
        $this->assertTrue($setupApplication->flushed);
        $this->assertTrue($teardownApplication->flushed);
    }

    #[Test]
    public function itPreservesTheRunnerFailureWhileExhaustingTeardown(): void
    {
        $teardownTokens = [];
        $runner = new ParallelRunnerStub(exception: new RuntimeException('runner failed'));
        $createdApplications = $this->trackingApplications(4);

        ParallelTesting::tearDownProcess(function (string $token) use (&$teardownTokens): never {
            $teardownTokens[] = $token;

            throw new RuntimeException("teardown {$token} failed");
        });

        $this->resolveRunnerUsing($runner);
        $this->resolveApplicationsUsing($createdApplications);

        try {
            (new ParallelRunner($this->optionsWithProcesses(2), new BufferedOutput))->execute();
            $this->fail('The runner exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('runner failed', $exception->getMessage());
        }

        $this->assertSame(['1', '2'], $teardownTokens);
        $this->assertSame(1, $runner->runCount);
        $this->assertSame([true, true, true, true], array_map(
            static fn (ParallelRunnerFlushTrackingApplication $application): bool => $application->flushed,
            $createdApplications,
        ));
    }

    #[Test]
    public function itThrowsTheFirstTeardownFailureAfterASuccessfulRun(): void
    {
        $teardownTokens = [];
        $runner = new ParallelRunnerStub;

        ParallelTesting::tearDownProcess(function (string $token) use (&$teardownTokens): never {
            $teardownTokens[] = $token;

            throw new RuntimeException("teardown {$token} failed");
        });

        $this->resolveRunnerUsing($runner);
        $this->resolveApplicationsUsing($this->trackingApplications(4));

        try {
            (new ParallelRunner($this->optionsWithProcesses(2), new BufferedOutput))->execute();
            $this->fail('The teardown exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('teardown 1 failed', $exception->getMessage());
        }

        $this->assertSame(['1', '2'], $teardownTokens);
        $this->assertSame(1, $runner->runCount);
    }

    #[Test]
    public function itPreservesTheCallbackFailureWhenApplicationFlushAlsoFails(): void
    {
        $application = new ParallelRunnerFlushTrackingApplication(
            $this->app->basePath(),
            new RuntimeException('flush failed'),
            new RuntimeException('termination failed'),
        );

        $_SERVER['TEST_TOKEN'] = 'ambient';
        ParallelRunner::resolveApplicationUsing(static fn () => $application);

        $runner = new ParallelRunner($this->optionsWithProcesses(1), new BufferedOutput);
        $method = new ReflectionMethod(ParallelRunner::class, 'forEachProcess');

        try {
            $method->invoke($runner, static function (): never {
                throw new RuntimeException('callback failed');
            });
            $this->fail('The callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }

        $this->assertTrue($application->terminated);
        $this->assertTrue($application->flushed);
        $this->assertSame(['terminate', 'flush'], $application->lifecycle);
        $this->assertSame('ambient', ParallelTesting::token());
    }

    #[Test]
    public function itFlushesAfterTerminationFailsAndThrowsTheTerminationFailure(): void
    {
        $terminationFailure = new RuntimeException('termination failed');
        $application = new ParallelRunnerFlushTrackingApplication(
            $this->app->basePath(),
            terminateException: $terminationFailure,
        );

        $_SERVER['TEST_TOKEN'] = 'ambient';
        ParallelRunner::resolveApplicationUsing(static fn () => $application);

        $runner = new ParallelRunner($this->optionsWithProcesses(1), new BufferedOutput);
        $method = new ReflectionMethod(ParallelRunner::class, 'forEachProcess');

        try {
            $method->invoke($runner, static function (): void {
            });
            $this->fail('The termination exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($terminationFailure, $exception);
        }

        $this->assertTrue($application->terminated);
        $this->assertTrue($application->flushed);
        $this->assertSame(['terminate', 'flush'], $application->lifecycle);
        $this->assertSame('ambient', $application->terminationToken);
        $this->assertSame('ambient', ParallelTesting::token());
    }

    #[Test]
    public function itUsesAnOverriddenSetupLoopButOwnsTeardownByAttemptedToken(): void
    {
        $setupTokens = [];
        $teardownTokens = [];
        $runner = new ParallelRunnerStub;

        ParallelTesting::setUpProcess(function (string $token) use (&$setupTokens): void {
            $setupTokens[] = $token;
        });
        ParallelTesting::tearDownProcess(function (string $token) use (&$teardownTokens): void {
            $teardownTokens[] = $token;
        });

        $this->resolveRunnerUsing($runner);
        $this->resolveApplicationsUsing($this->trackingApplications(4));

        $parallelRunner = new ParallelRunnerWithCustomProcesses(
            $this->optionsWithProcesses(5),
            new BufferedOutput,
            ['7', '3'],
        );

        $this->assertSame(0, $parallelRunner->execute());
        $this->assertSame(['7', '3'], $setupTokens);
        $this->assertSame(['7', '3'], $teardownTokens);
        $this->assertSame(1, $parallelRunner->loopCalls);
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
     * Configure the application resolver with the given sequence.
     *
     * @param list<ApplicationContract|Throwable> $applications
     */
    protected function resolveApplicationsUsing(array $applications): void
    {
        ParallelRunner::resolveApplicationUsing(static function () use (&$applications): ApplicationContract {
            $application = array_shift($applications);

            if ($application instanceof Throwable) {
                throw $application;
            }

            return $application;
        });
    }

    /**
     * Configure the runner resolver.
     */
    protected function resolveRunnerUsing(ParallelRunnerStub $runner): void
    {
        ParallelRunner::resolveRunnerUsing(static fn () => $runner);
    }

    /**
     * Create flush-tracking applications.
     *
     * @return list<ParallelRunnerFlushTrackingApplication>
     */
    protected function trackingApplications(int $count): array
    {
        $applications = [];

        for ($index = 0; $index < $count; ++$index) {
            $applications[] = new ParallelRunnerFlushTrackingApplication($this->app->basePath());
        }

        return $applications;
    }

    /**
     * Snapshot selected array values with their presence.
     *
     * @param array<string, mixed> $values
     * @param list<string> $keys
     * @return array<string, array{bool, mixed}>
     */
    protected function snapshotArrayValues(array $values, array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = [array_key_exists($key, $values), $values[$key] ?? null];
        }

        return $snapshot;
    }

    /**
     * Restore selected array values with their original presence.
     *
     * @param array<string, mixed> $values
     * @param array<string, array{bool, mixed}> $snapshot
     */
    protected function restoreArrayValues(array &$values, array $snapshot): void
    {
        foreach ($snapshot as $key => [$existed, $value]) {
            if ($existed) {
                $values[$key] = $value;
            } else {
                unset($values[$key]);
            }
        }
    }
}

class ParallelRunnerFlushTrackingApplication extends Application
{
    public bool $terminated = false;

    public bool $flushed = false;

    /** @var list<string> */
    public array $lifecycle = [];

    public false|int|string|null $terminationToken = null;

    /**
     * Create a flush-tracking application.
     */
    public function __construct(
        ?string $basePath = null,
        private readonly ?Throwable $flushException = null,
        private readonly ?Throwable $terminateException = null,
    ) {
        parent::__construct($basePath);
    }

    /**
     * Terminate the application.
     */
    public function terminate(): void
    {
        $this->terminated = true;
        $this->lifecycle[] = 'terminate';
        $this->terminationToken = ParallelTesting::token();

        if ($this->terminateException !== null) {
            throw $this->terminateException;
        }

        parent::terminate();
    }

    /**
     * Flush the container of all bindings and resolved instances.
     */
    public function flush(): void
    {
        $this->flushed = true;
        $this->lifecycle[] = 'flush';

        if ($this->flushException !== null) {
            throw $this->flushException;
        }

        parent::flush();
    }
}

class ParallelRunnerStub implements RunnerInterface
{
    public int $runCount = 0;

    /**
     * Create a runner stub.
     */
    public function __construct(
        private readonly int $exitCode = RunnerInterface::SUCCESS_EXIT,
        private readonly ?Throwable $exception = null,
        private readonly ?Closure $onRun = null,
    ) {
    }

    /**
     * Run the test suite.
     */
    public function run(): int
    {
        ++$this->runCount;

        if ($this->onRun !== null) {
            ($this->onRun)();
        }

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->exitCode;
    }
}

class ParallelRunnerWithCustomProcesses extends ParallelRunner
{
    public int $loopCalls = 0;

    /**
     * Create a runner with a custom process sequence.
     *
     * @param list<string> $tokens
     */
    public function __construct(Options $options, BufferedOutput $output, private readonly array $tokens)
    {
        parent::__construct($options, $output);
    }

    /**
     * Apply the given callback for each process.
     */
    protected function forEachProcess(callable $callback): void
    {
        ++$this->loopCalls;

        foreach ($this->tokens as $token) {
            $this->forProcess($token, $callback);
        }
    }
}
