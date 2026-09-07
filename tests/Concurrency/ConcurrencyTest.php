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
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Concurrency as ConcurrencyFacade;
use Hypervel\Support\Facades\Context;
use Hypervel\Testbench\Attributes\UsesVendor;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Concurrency\Fixtures\ConcurrentProcessExceptionFixtures;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;

class ConcurrencyTest extends TestCase
{
    private CoroutineDriver $coroutineDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coroutineDriver = new CoroutineDriver;
    }

    public function testRunReturnsConcurrentResults()
    {
        [$first, $second] = $this->coroutineDriver->run([
            fn () => 1 + 1,
            fn () => 2 + 2,
        ]);

        $this->assertSame(2, $first);
        $this->assertSame(4, $second);
    }

    public function testRunPreservesStringKeys()
    {
        $results = $this->coroutineDriver->run([
            'first' => fn () => 1 + 1,
            'second' => fn () => 2 + 2,
        ]);

        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertSame(2, $results['first']);
        $this->assertSame(4, $results['second']);
    }

    public function testRunPreservesOrderRegardlessOfCompletionTime()
    {
        [$first, $second, $third] = $this->coroutineDriver->run([
            function () {
                usleep(50000);
                return 'first';
            },
            function () {
                usleep(25000);
                return 'second';
            },
            function () {
                return 'third';
            },
        ]);

        $this->assertSame('first', $first);
        $this->assertSame('second', $second);
        $this->assertSame('third', $third);
    }

    public function testRunRethrowsExceptions()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('something went wrong');

        $this->coroutineDriver->run([
            fn () => throw new RuntimeException('something went wrong'),
            fn () => 'ok',
        ]);
    }

    public function testRunRethrowsCustomExceptionWithOriginalMessage()
    {
        try {
            $this->coroutineDriver->run([
                fn () => throw new ConcurrencyTestException('https://api.example.com', 400),
            ]);

            $this->fail('Expected exception was not thrown');
        } catch (ConcurrencyTestException $e) {
            $this->assertSame('Request to https://api.example.com failed with status 400', $e->getMessage());
            $this->assertSame('https://api.example.com', $e->uri);
            $this->assertSame(400, $e->statusCode);
        }
    }

    public function testRunRethrowsExceptionFromEarliestInputPositionWhenMultipleTasksFail()
    {
        $caught = null;

        try {
            $this->coroutineDriver->run([
                function () {
                    // Task 0: fails later (50ms).
                    usleep(50000);
                    throw new RuntimeException('first in input');
                },
                function () {
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

    public function testRunWithEmptyArrayReturnsEmptyArray()
    {
        $results = $this->coroutineDriver->run([]);

        $this->assertSame([], $results);
    }

    public function testRunWithSingleTask()
    {
        $results = $this->coroutineDriver->run([
            fn () => 42,
        ]);

        $this->assertSame([42], $results);
    }

    public function testRunWithSingleClosure()
    {
        $results = $this->coroutineDriver->run(fn () => 42);

        $this->assertSame([42], $results);
    }

    public function testRunExecutesConcurrently()
    {
        $results = $this->coroutineDriver->run([
            fn () => Coroutine::id(),
            fn () => Coroutine::id(),
            fn () => Coroutine::id(),
        ]);

        // Each task runs in its own coroutine, so IDs must be unique.
        $this->assertCount(3, array_unique($results));
    }

    public function testRunPropagatesParentContext()
    {
        CoroutineContext::set('test_key', 'test_value');

        $results = $this->coroutineDriver->run([
            fn () => CoroutineContext::get('test_key'),
            fn () => CoroutineContext::get('test_key'),
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

    public function testRunChildContextDoesNotLeakToParent()
    {
        $this->coroutineDriver->run([
            function () {
                CoroutineContext::set('child_key', 'child_value');
            },
        ]);

        $this->assertNull(CoroutineContext::get('child_key'));
    }

    public function testRunChildContextDoesNotLeakBetweenTasks()
    {
        $results = $this->coroutineDriver->run([
            function () {
                CoroutineContext::set('task_key', 'from_task_1');
                usleep(10000);
                return CoroutineContext::get('task_key');
            },
            function () {
                usleep(5000);
                return CoroutineContext::get('task_key');
            },
        ]);

        $this->assertSame('from_task_1', $results[0]);
        $this->assertNull($results[1]);
    }

    public function testDeferReturnsDeferredCallback()
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn () => $collection);

        $result = $this->coroutineDriver->defer([
            fn () => 1 + 1,
        ]);

        $this->assertInstanceOf(DeferredCallback::class, $result);
        $this->assertCount(1, $collection);
    }

    public function testDeferExecutesTasksWhenInvoked()
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn () => $collection);

        $executed = false;

        $this->coroutineDriver->defer([
            function () use (&$executed) {
                $executed = true;
            },
        ]);

        // Not executed yet.
        $this->assertFalse($executed);

        // Invoke the deferred callbacks.
        $collection->invoke();

        $this->assertTrue($executed);
    }

    public function testDeferPropagatesContext()
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn () => $collection);

        CoroutineContext::set('defer_key', 'defer_value');
        $capturedValue = null;

        $this->coroutineDriver->defer([
            function () use (&$capturedValue) {
                $capturedValue = CoroutineContext::get('defer_key');
            },
        ]);

        $collection->invoke();

        $this->assertSame('defer_value', $capturedValue);
    }

    public function testFacadeRun()
    {
        [$first, $second] = ConcurrencyFacade::run([
            fn () => 1 + 1,
            fn () => 2 + 2,
        ]);

        $this->assertSame(2, $first);
        $this->assertSame(4, $second);
    }

    public function testFacadeDefer()
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn () => $collection);

        $result = ConcurrencyFacade::defer([
            fn () => 1 + 1,
        ]);

        $this->assertInstanceOf(DeferredCallback::class, $result);
        $this->assertCount(1, $collection);
    }

    public function testFacadeResolvesManager()
    {
        $this->assertInstanceOf(ConcurrencyManager::class, ConcurrencyFacade::getFacadeRoot());
    }

    public function testManagerDefaultDriverIsCoroutine()
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

    public function testManagerResolvesCoroutineDriver()
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $this->assertInstanceOf(CoroutineDriver::class, $manager->driver('coroutine'));
    }

    public function testManagerResolvesProcessDriver()
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $this->assertInstanceOf(ProcessDriver::class, $manager->driver('process'));
    }

    public function testManagerResolvesSyncDriver()
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $this->assertInstanceOf(SyncDriver::class, $manager->driver('sync'));
    }

    public function testManagerResolvesEnumDriverIdentifiers(): void
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $manager->extend('Primary', fn () => new SyncDriver);
        $manager->extend('1', fn () => new SyncDriver);
        $manager->extend('0', fn () => new SyncDriver);

        $this->assertSame($manager->driver('Primary'), $manager->driver(ConcurrencyUnitIdentifier::Primary));
        $this->assertSame($manager->driver('1'), $manager->driver(ConcurrencyIntegerIdentifier::Primary));
        $this->assertSame($manager->driver('0'), $manager->driver(ConcurrencyIntegerIdentifier::Zero));
        $this->assertSame($manager->driver(), $manager->driver(''));
        $this->assertInstanceOf(SyncDriver::class, $manager->driver('0'));
        $this->assertInstanceOf(SyncDriver::class, $manager->driver(ConcurrencyIntegerIdentifier::Zero));
    }

    public function testManagerCachesDriverInstances()
    {
        $manager = $this->app->make(ConcurrencyManager::class);

        $first = $manager->driver('coroutine');
        $second = $manager->driver('coroutine');

        $this->assertSame($first, $second);
    }

    public function testSyncDriverRunsSequentially()
    {
        $driver = new SyncDriver;

        [$first, $second] = $driver->run([
            fn () => 1 + 1,
            fn () => 2 + 2,
        ]);

        $this->assertSame(2, $first);
        $this->assertSame(4, $second);
    }

    public function testSyncDriverPreservesStringKeys()
    {
        $driver = new SyncDriver;

        $results = $driver->run([
            'first' => fn () => 1 + 1,
            'second' => fn () => 2 + 2,
        ]);

        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertSame(2, $results['first']);
        $this->assertSame(4, $results['second']);
    }

    public function testSyncDriverDefer()
    {
        $collection = new DeferredCallbackCollection;
        $this->app->scoped(DeferredCallbackCollection::class, fn () => $collection);

        $driver = new SyncDriver;
        $result = $driver->defer([fn () => 1 + 1]);

        $this->assertInstanceOf(DeferredCallback::class, $result);
        $this->assertCount(1, $collection);
    }

    public function testProcessDriverRunReturnsResults()
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn () => $factory->result(
            output: json_encode(['successful' => true, 'result' => base64_encode(serialize('hello'))])
        ));

        $driver = new ProcessDriver($factory);

        $results = $driver->run([fn () => 'hello']);

        $this->assertSame(['hello'], array_values($results));
    }

    public function testProcessDriverUsesInvokeSerializedClosureCommand()
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn () => $factory->result(
            output: json_encode(['successful' => true, 'result' => base64_encode(serialize(null))])
        ));

        $driver = new ProcessDriver($factory);
        $driver->run([fn () => null]);

        $factory->assertRan(fn ($process) => str_contains($process->command, 'invoke-serialized-closure'));
    }

    public function testProcessDriverSetsEnvironmentVariable()
    {
        $factory = $this->app->make(ProcessFactory::class);
        $factory->fake(fn () => $factory->result(
            output: json_encode(['successful' => true, 'result' => base64_encode(serialize(null))])
        ));

        $driver = new ProcessDriver($factory);
        $driver->run([fn () => null]);

        $factory->assertRan(function ($process) {
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
            $driver->run(static fn () => null);
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
        $factory->fake(fn () => $factory->result(
            errorOutput: 'child failed',
            exitCode: 5,
        ));
        $driver = new ProcessDriver($factory);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Concurrent process failed with exit code [5]. Message: child failed');

        $driver->run(static fn () => null);
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
        $factory->fake(fn () => $factory->result(
            output: json_encode([
                'successful' => true,
                'result' => base64_encode(serialize('result')),
            ])
        ));

        $driver = new ProcessDriver($factory);

        $this->assertSame(['result'], $driver->run(
            static fn () => 'result',
            timeout: CarbonInterval::seconds(120),
        ));

        $factory->assertRan(fn ($process) => $process->timeout === 120);
    }

    public function testCoroutineAndSyncDriversAcceptProcessOnlyTimeouts(): void
    {
        $this->assertSame(['coroutine'], $this->coroutineDriver->run(
            static fn () => 'coroutine',
            timeout: 1,
        ));
        $this->assertSame(['sync'], (new SyncDriver)->run(
            static fn () => 'sync',
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
        $factory->fake(fn () => $factory->result(
            output: json_encode($payload, JSON_THROW_ON_ERROR)
        ));

        return new ProcessDriver($factory);
    }
}

class ConcurrencyTestException extends Exception
{
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
