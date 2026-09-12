<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency;

use Carbon\CarbonInterval;
use Exception;
use Hypervel\Concurrency\ConcurrencyManager;
use Hypervel\Concurrency\CoroutineDriver;
use Hypervel\Concurrency\ProcessDriver;
use Hypervel\Concurrency\SyncDriver;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Process\FakeProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Concurrency as ConcurrencyFacade;
use Hypervel\Support\Facades\Context;
use Hypervel\Testbench\Attributes\UsesVendor;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Concurrency\Fixtures\ConcurrentProcessExceptionFixtures;
use Hypervel\Tests\Concurrency\Fixtures\ExceptionWithFalseyParam;
use Hypervel\Tests\Concurrency\Fixtures\ExceptionWithoutParam;
use Hypervel\Tests\Concurrency\Fixtures\ExceptionWithParam;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Process as SwooleProcess;

class ConcurrencyTest extends TestCase
{
    private CoroutineDriver $coroutineDriver;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->coroutineDriver = new CoroutineDriver;
    }

    public function testRunReturnsConcurrentResults(): void
    {
        [$first, $second] = $this->coroutineDriver->run([
            fn (): int => 1 + 1,
            fn (): int => 2 + 2,
        ]);

        $this->assertSame(2, $first);
        $this->assertSame(4, $second);
    }

    public function testRunPreservesStringKeys(): void
    {
        $results = $this->coroutineDriver->run([
            'first' => fn (): int => 1 + 1,
            'second' => fn (): int => 2 + 2,
        ]);

        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertSame(2, $results['first']);
        $this->assertSame(4, $results['second']);
    }

    public function testRunPreservesOrderRegardlessOfCompletionTime(): void
    {
        [$first, $second, $third] = $this->coroutineDriver->run([
            function (): string {
                usleep(50000);
                return 'first';
            },
            function (): string {
                usleep(25000);
                return 'second';
            },
            function (): string {
                return 'third';
            },
        ]);

        $this->assertSame('first', $first);
        $this->assertSame('second', $second);
        $this->assertSame('third', $third);
    }

    public function testRunRethrowsExceptions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('something went wrong');

        $this->coroutineDriver->run([
            fn (): never => throw new RuntimeException('something went wrong'),
            fn (): string => 'ok',
        ]);
    }

    public function testRunRethrowsCustomExceptionWithOriginalMessage(): void
    {
        try {
            $this->coroutineDriver->run([
                fn (): never => throw new ConcurrencyTestException('https://api.example.com', 400),
            ]);

            $this->fail('Expected exception was not thrown');
        } catch (ConcurrencyTestException $e) {
            $this->assertSame('Request to https://api.example.com failed with status 400', $e->getMessage());
            $this->assertSame('https://api.example.com', $e->uri);
            $this->assertSame(400, $e->statusCode);
        }
    }

    public function testRunRethrowsExceptionFromEarliestInputPositionWhenMultipleTasksFail(): void
    {
        $caught = null;

        try {
            $this->coroutineDriver->run([
                function (): never {
                    // Task 0: fails later (50ms).
                    usleep(50000);
                    throw new RuntimeException('first in input');
                },
                function (): never {
                    // Task 1: fails immediately, before task 0.
                    throw new RuntimeException('second in input');
                },
            ]);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected exception was not thrown');
        $this->assertSame('first in input', $caught->getMessage());
    }

    public function testRunWithEmptyArrayReturnsEmptyArray(): void
    {
        $results = $this->coroutineDriver->run([]);

        $this->assertSame([], $results);
    }

    public function testRunWithSingleTask(): void
    {
        $results = $this->coroutineDriver->run([
            fn (): int => 42,
        ]);

        $this->assertSame([42], $results);
    }

    public function testRunWithSingleClosure(): void
    {
        $results = $this->coroutineDriver->run(fn (): int => 42);

        $this->assertSame([42], $results);
    }

    public function testRunExecutesConcurrently(): void
    {
        $results = $this->coroutineDriver->run([
            fn (): int => Coroutine::id(),
            fn (): int => Coroutine::id(),
            fn (): int => Coroutine::id(),
        ]);

        // Each task runs in its own coroutine, so IDs must be unique.
        $this->assertCount(3, array_unique($results));
    }

    public function testRunPropagatesParentContext(): void
    {
        CoroutineContext::set('test_key', 'test_value');

        $results = $this->coroutineDriver->run([
            fn (): mixed => CoroutineContext::get('test_key'),
            fn (): mixed => CoroutineContext::get('test_key'),
        ]);

        $this->assertSame(['test_value', 'test_value'], $results);
    }

    public function testRunSurfacesContextReplicationFailureWithoutStrandingTheWaitGroup(): void
    {
        $result = new Channel(1);
        $runner = Coroutine::create(static function () use ($result): void {
            CoroutineContext::set('throwing', new ThrowingReplicableContext);

            try {
                (new CoroutineDriver)->run([
                    static fn (): string => 'never',
                    static fn (): string => 'never',
                ]);
            } catch (RuntimeException $exception) {
                $result->push($exception);
            }
        });

        $outcome = $result->pop(0.1);

        if ($outcome === false && Coroutine::exists($runner)) {
            SwooleCoroutine::cancel($runner, true);
        }

        $this->assertInstanceOf(RuntimeException::class, $outcome);
        $this->assertSame('Unable to replicate context.', $outcome->getMessage());
    }

    public function testRunChildContextDoesNotLeakToParent(): void
    {
        $this->coroutineDriver->run([
            function (): void {
                CoroutineContext::set('child_key', 'child_value');
            },
        ]);

        $this->assertNull(CoroutineContext::get('child_key'));
    }

    public function testRunChildContextDoesNotLeakBetweenTasks(): void
    {
        $results = $this->coroutineDriver->run([
            function (): mixed {
                CoroutineContext::set('task_key', 'from_task_1');
                usleep(10000);
                return CoroutineContext::get('task_key');
            },
            function (): mixed {
                usleep(5000);
                return CoroutineContext::get('task_key');
            },
        ]);

        $this->assertSame('from_task_1', $results[0]);
        $this->assertNull($results[1]);
    }

    public function testDeferReturnsDeferredCallback(): void
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn (): DeferredCallbackCollection => $collection);

        $result = $this->coroutineDriver->defer([
            fn (): int => 1 + 1,
        ]);

        $this->assertInstanceOf(DeferredCallback::class, $result);
        $this->assertCount(1, $collection);
    }

    public function testDeferExecutesTasksWhenInvoked(): void
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn (): DeferredCallbackCollection => $collection);

        $executed = false;

        $this->coroutineDriver->defer([
            function () use (&$executed): void {
                $executed = true;
            },
        ]);

        // Not executed yet.
        $this->assertFalse($executed);

        // Invoke the deferred callbacks.
        $collection->invoke();

        $this->assertTrue($executed);
    }

    public function testDeferPropagatesContext(): void
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn (): DeferredCallbackCollection => $collection);

        CoroutineContext::set('defer_key', 'defer_value');
        $capturedValue = null;

        $this->coroutineDriver->defer([
            function () use (&$capturedValue): void {
                $capturedValue = CoroutineContext::get('defer_key');
            },
        ]);

        $collection->invoke();

        $this->assertSame('defer_value', $capturedValue);
    }

    public function testFacadeRun(): void
    {
        [$first, $second] = ConcurrencyFacade::run([
            fn (): int => 1 + 1,
            fn (): int => 2 + 2,
        ]);

        $this->assertSame(2, $first);
        $this->assertSame(4, $second);
    }

    public function testFacadeDefer(): void
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn (): DeferredCallbackCollection => $collection);

        $result = ConcurrencyFacade::defer([
            fn (): int => 1 + 1,
        ]);

        $this->assertInstanceOf(DeferredCallback::class, $result);
        $this->assertCount(1, $collection);
    }

    public function testFacadeResolvesManager(): void
    {
        $this->assertInstanceOf(ConcurrencyManager::class, ConcurrencyFacade::getFacadeRoot());
    }

    public function testManagerDefaultDriverIsCoroutine(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $this->assertSame('coroutine', $manager->getDefaultInstance());
    }

    public function testChangingDefaultDriverPreservesDriverConfiguration(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);
        $config = $this->app->make('config');
        $driverConfig = ['driver' => 'sync', 'option' => 'preserved'];
        $config->set('concurrency.driver.sync', $driverConfig);

        $manager->setDefaultInstance('sync');

        $this->assertSame('sync', $manager->getDefaultInstance());
        $this->assertSame($driverConfig, $manager->getInstanceConfig('sync'));
    }

    public function testManagerResolvesCoroutineDriver(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $this->assertInstanceOf(CoroutineDriver::class, $manager->driver('coroutine'));
    }

    public function testManagerResolvesProcessDriver(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $this->assertInstanceOf(ProcessDriver::class, $manager->driver('process'));
    }

    public function testManagerResolvesSyncDriver(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $this->assertInstanceOf(SyncDriver::class, $manager->driver('sync'));
    }

    public function testManagerResolvesEnumDriverIdentifiers(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $manager->extend('Primary', fn (): SyncDriver => new SyncDriver);
        $manager->extend('1', fn (): SyncDriver => new SyncDriver);
        $manager->extend('0', fn (): SyncDriver => new SyncDriver);

        $this->assertSame($manager->driver('Primary'), $manager->driver(ConcurrencyUnitIdentifier::Primary));
        $this->assertSame($manager->driver('1'), $manager->driver(ConcurrencyIntegerIdentifier::Primary));
        $this->assertSame($manager->driver('0'), $manager->driver(ConcurrencyIntegerIdentifier::Zero));
        $this->assertSame($manager->driver(), $manager->driver(''));
        $this->assertInstanceOf(SyncDriver::class, $manager->driver('0'));
        $this->assertInstanceOf(SyncDriver::class, $manager->driver(ConcurrencyIntegerIdentifier::Zero));
    }

    public function testManagerCachesDriverInstances(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $first = $manager->driver('coroutine');
        $second = $manager->driver('coroutine');

        $this->assertSame($first, $second);
    }

    public function testSyncDriverRunsSequentially(): void
    {
        $driver = new SyncDriver;

        [$first, $second] = $driver->run([
            fn (): int => 1 + 1,
            fn (): int => 2 + 2,
        ]);

        $this->assertSame(2, $first);
        $this->assertSame(4, $second);
    }

    public function testSyncDriverPreservesStringKeys(): void
    {
        $driver = new SyncDriver;

        $results = $driver->run([
            'first' => fn (): int => 1 + 1,
            'second' => fn (): int => 2 + 2,
        ]);

        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertSame(2, $results['first']);
        $this->assertSame(4, $results['second']);
    }

    public function testSyncDriverDefer(): void
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn (): DeferredCallbackCollection => $collection);

        $driver = new SyncDriver;
        $result = $driver->defer([fn (): int => 1 + 1]);

        $this->assertInstanceOf(DeferredCallback::class, $result);
        $this->assertCount(1, $collection);
    }

    public function testProcessDriverRunReturnsResults(): void
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn (): FakeProcessResult => $factory->result(
            output: json_encode(['successful' => true, 'result' => base64_encode(serialize('hello'))])
        ));

        $driver = new ProcessDriver($factory);

        $results = $driver->run([fn (): string => 'hello']);

        $this->assertSame(['hello'], array_values($results));
    }

    public function testProcessDriverUsesInvokeSerializedClosureCommand(): void
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn (): FakeProcessResult => $factory->result(
            output: json_encode(['successful' => true, 'result' => base64_encode(serialize(null))])
        ));

        $driver = new ProcessDriver($factory);
        $driver->run([fn (): null => null]);

        $factory->assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'invoke-serialized-closure'));
    }

    public function testProcessDriverSetsEnvironmentVariable(): void
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn (): FakeProcessResult => $factory->result(
            output: json_encode(['successful' => true, 'result' => base64_encode(serialize(null))])
        ));

        $driver = new ProcessDriver($factory);
        $driver->run([fn (): null => null]);

        $factory->assertRan(function (PendingProcess $process): bool {
            return isset($process->environment['HYPERVEL_INVOKABLE_CLOSURE'])
                && $process->environment['HYPERVEL_INVOKABLE_CLOSURE'] !== '';
        });
    }

    public function testProcessDriverPreservesPublicFalseyExceptionParameters(): void
    {
        $driver = $this->processDriverFor([
            'successful' => false,
            'exception' => ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION,
            'message' => 'public falsey values',
            'parameters' => [
                'status' => 0,
                'retry' => false,
                'reason' => '',
                'detail' => null,
            ],
        ]);
        $caught = null;

        try {
            $driver->run(static fn (): null => null);
        } catch (Exception $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Expected the transported exception to be thrown.');
        $this->assertSame(ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION, $caught::class);
        $this->assertSame(0, $caught->status);
        $this->assertFalse($caught->retry);
        $this->assertSame('', $caught->reason);
        $this->assertNull($caught->detail);
    }

    public function testProcessDriverReportsFailedChildProcessesBeforeDecoding(): void
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn (): FakeProcessResult => $factory->result(
            errorOutput: 'child failed',
            exitCode: 5,
        ));
        $driver = new ProcessDriver($factory);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageIsOrContains('Concurrent process failed with exit code [5]. Message: child failed');

        $driver->run(static fn (): null => null);
    }

    #[UsesVendor]
    public function testRunHandlerProcessErrorCode(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIsOrContains('Concurrent process failed with exit code [143].');

        $processDriver = new ProcessDriver($this->app->make(ProcessFactory::class));
        $processDriver->run([
            // exit() throws inside a coroutine, so terminate the child process directly.
            fn (): bool => SwooleProcess::kill(getmypid()),
        ]);
    }

    #[UsesVendor]
    public function testOutputIsMappedToArrayInput(): void
    {
        $input = [
            'first' => fn (): int => 1 + 1,
            'second' => fn (): int => 2 + 2,
        ];

        $processOutput = ConcurrencyFacade::driver('process')->run($input);

        $this->assertIsArray($processOutput);
        $this->assertArrayHasKey('first', $processOutput);
        $this->assertArrayHasKey('second', $processOutput);

        $syncOutput = ConcurrencyFacade::driver('sync')->run($input);

        $this->assertIsArray($syncOutput);
        $this->assertArrayHasKey('first', $syncOutput);
        $this->assertArrayHasKey('second', $syncOutput);
    }

    public function testProcessDriverRunMayUseCustomTimeout(): void
    {
        $factory = $this->app->make(ProcessFactory::class);

        $factory->fake(fn (): FakeProcessResult => $factory->result(output: json_encode([
            'successful' => true,
            'result' => base64_encode(serialize('result')),
        ])));

        $result = (new ProcessDriver($factory))->run([
            fn (): string => 'result',
        ], timeout: 120);

        $this->assertSame(['result'], $result);

        $factory->assertRan(function (PendingProcess $process): bool {
            return $process->timeout === 120;
        });
    }

    public function testDriverCanBeResolvedUsingBackedEnum(): void
    {
        $this->assertInstanceOf(
            SyncDriver::class,
            ConcurrencyFacade::driver(ConcurrencyDriverEnum::Sync),
        );
    }

    #[UsesVendor]
    public function testRunHandlerProcessErrorWithDefaultExceptionWithoutParam(): void
    {
        $this->expectExceptionObject(new Exception('This is a different exception'));

        ConcurrencyFacade::driver('process')->run([
            fn (): never => throw new Exception(
                'This is a different exception',
            ),
        ]);
    }

    #[UsesVendor]
    public function testRunHandlerProcessErrorWithCustomExceptionWithoutParam(): void
    {
        $this->expectExceptionObject(new ExceptionWithoutParam('Test'));
        ConcurrencyFacade::driver('process')->run([
            fn (): never => throw new ExceptionWithoutParam('Test'),
        ]);
    }

    #[UsesVendor]
    public function testRunHandlerProcessErrorWithCustomExceptionWithParam(): void
    {
        $this->expectException(ExceptionWithParam::class);
        $this->expectExceptionMessageIsOrContains('API request to https://api.example.com failed with status 400 Bad Request');
        ConcurrencyFacade::driver('process')->run([
            fn (): never => throw new ExceptionWithParam(
                'https://api.example.com',
                400,
                'Bad Request',
                'Invalid payload'
            ),
        ]);
    }

    #[UsesVendor]
    #[DataProvider('falseyExceptionParameters')]
    public function testRunHandlerProcessErrorWithFalseyParam(int|bool|string $value): void
    {
        try {
            ConcurrencyFacade::driver('process')->run([
                fn (): never => throw new ExceptionWithFalseyParam($value),
            ]);
        } catch (ExceptionWithFalseyParam $e) {
            $this->assertSame($value, $e->value);

            return;
        }

        $this->fail('The expected exception was not thrown.');
    }

    /**
     * Get falsey constructor parameters.
     *
     * @return array<string, array{bool|int|string}>
     */
    public static function falseyExceptionParameters(): array
    {
        return [
            'zero' => [0],
            'false' => [false],
            'empty string' => [''],
        ];
    }

    #[UsesVendor]
    public function testContextIsPropagatedToConcurrentProcesses(): void
    {
        Context::add('task', 'concurrency');
        Context::addHidden('token', 'secret');

        [$task, $token] = ConcurrencyFacade::driver('process')->run([
            static fn (): mixed => Context::get('task'),
            static fn (): mixed => Context::getHidden('token'),
        ]);

        $this->assertSame('concurrency', $task);
        $this->assertSame('secret', $token);
    }

    public function testContextIsPropagatedToDeferredConcurrentProcesses(): void
    {
        $this->withoutDefer();

        Context::add('task', 'concurrency');

        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake();

        (new ProcessDriver($factory))->defer([static fn (): string => 'result']);

        $factory->assertRan(static fn (PendingProcess $process): bool => ($process->environment['__HYPERVEL_CONTEXT'] ?? null) === base64_encode(serialize(Context::dehydrate())));
    }

    #[UsesVendor]
    #[DataProvider('getConcurrencyDrivers')]
    public function testRunPreservesCallbackOrder(string $driver): void
    {
        [$first, $second, $third] = ConcurrencyFacade::driver($driver)->run([
            function (): string {
                usleep(1000000);

                return 'first';
            },
            function (): string {
                usleep(500000);

                return 'second';
            },
            function (): string {
                usleep(200000);

                return 'third';
            },
        ]);

        $this->assertSame('first', $first);
        $this->assertSame('second', $second);
        $this->assertSame('third', $third);
    }

    /**
     * Get the concurrency drivers.
     *
     * @return array<int, array{string}>
     */
    public static function getConcurrencyDrivers(): array
    {
        return [
            ['sync'],
            ['process'],
        ];
    }

    #[UsesVendor]
    public function testBinaryContextIsPropagatedToConcurrentProcesses(): void
    {
        Context::add('task', 'concurrency');
        Context::addHidden('token', "binary-\xFF\x00\x8B");

        [$context] = ConcurrencyFacade::driver('process')->run([
            static fn (): array => [Context::get('task'), Context::getHidden('token')],
        ]);

        $this->assertSame(['concurrency', "binary-\xFF\x00\x8B"], $context);
    }

    public function testProcessDriverAppliesCustomTimeouts(): void
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn (): FakeProcessResult => $factory->result(
            output: json_encode([
                'successful' => true,
                'result' => base64_encode(serialize('result')),
            ])
        ));

        $driver = new ProcessDriver($factory);

        $this->assertSame(['result'], $driver->run(
            static fn (): string => 'result',
            timeout: CarbonInterval::seconds(120),
        ));

        $factory->assertRan(fn (PendingProcess $process): bool => $process->timeout === 120);
    }

    public function testCoroutineAndSyncDriversAcceptProcessOnlyTimeouts(): void
    {
        $this->assertSame(['coroutine'], $this->coroutineDriver->run(
            static fn (): string => 'coroutine',
            timeout: 1,
        ));
        $this->assertSame(['sync'], (new SyncDriver)->run(
            static fn (): string => 'sync',
            timeout: 1,
        ));
    }

    /**
     * Create a process driver that returns the given response envelope.
     *
     * @param array<string, mixed> $payload
     */
    private function processDriverFor(array $payload): ProcessDriver
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn (): FakeProcessResult => $factory->result(
            output: json_encode($payload, JSON_THROW_ON_ERROR)
        ));

        return new ProcessDriver($factory);
    }
}

class ConcurrencyTestException extends Exception
{
    /**
     * Create an exception for the failed request.
     */
    public function __construct(
        public readonly string $uri,
        public readonly int $statusCode,
    ) {
        parent::__construct("Request to {$uri} failed with status {$statusCode}");
    }
}

enum ConcurrencyUnitIdentifier
{
    case Primary;
}

enum ConcurrencyIntegerIdentifier: int
{
    case Primary = 1;
    case Zero = 0;
}

enum ConcurrencyDriverEnum: string
{
    case Sync = 'sync';
}
