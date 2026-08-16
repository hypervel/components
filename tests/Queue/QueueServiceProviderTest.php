<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Queue\BackgroundQueue;
use Hypervel\Queue\DeferredQueue;
use Hypervel\Queue\Failed\DatabaseFailedJobProvider;
use Hypervel\Queue\Failed\DatabaseUuidFailedJobProvider;
use Hypervel\Queue\Failed\FileFailedJobProvider;
use Hypervel\Queue\Failed\NullFailedJobProvider;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueueServiceProvider;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

class QueueServiceProviderTest extends TestCase
{
    public function testReloadConfigurationRebuildsConnectionsWithExceptionReporting(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->twice()->with(m::type(RuntimeException::class));
        $this->app->instance(ExceptionHandler::class, $handler);
        config([
            'queue.default' => 'sync',
            'queue.failed.driver' => 'null',
        ]);
        $manager = $this->app->make('queue');
        $this->assertFalse($manager->connected('background'));
        $this->assertFalse($manager->connected('deferred'));
        $connection = $this->app->make('queue.connection');
        $failedJobs = $this->app->make('queue.failer');
        $background = $manager->connection('background');
        $deferred = $manager->connection('deferred');
        $this->assertInstanceOf(SyncQueue::class, $connection);
        $this->assertInstanceOf(NullFailedJobProvider::class, $failedJobs);

        config([
            'queue.default' => 'null',
            'queue.failed.driver' => 'file',
        ]);
        $this->app->getProvider(QueueServiceProvider::class)->reloadConfiguration();

        $refreshedBackground = $manager->connection('background');
        $refreshedDeferred = $manager->connection('deferred');
        $this->assertSame($manager, $this->app->make(QueueManager::class));
        $this->assertNotSame($background, $refreshedBackground);
        $this->assertNotSame($deferred, $refreshedDeferred);
        $this->assertInstanceOf(NullQueue::class, $this->app->make('queue.connection'));
        $this->assertInstanceOf(FileFailedJobProvider::class, $this->app->make('queue.failer'));

        $backgroundCallback = (new ReflectionProperty(BackgroundQueue::class, 'exceptionCallback'))
            ->getValue($refreshedBackground);
        $deferredCallback = (new ReflectionProperty(DeferredQueue::class, 'exceptionCallback'))
            ->getValue($refreshedDeferred);

        $this->assertIsCallable($backgroundCallback);
        $this->assertIsCallable($deferredCallback);
        $backgroundCallback(new RuntimeException('Background failed.'));
        $deferredCallback(new RuntimeException('Deferred failed.'));
    }

    public function testReloadConfigurationPreservesQueueFakeAndRefreshesItsWrappedManager(): void
    {
        $manager = $this->app->make('queue');
        $background = $manager->connection('background');
        $fake = Queue::fake(['queued-job']);
        $fake->push('queued-job');

        $this->app->getProvider(QueueServiceProvider::class)->reloadConfiguration();

        $this->assertSame($fake, Queue::getFacadeRoot());
        $this->assertSame(['queued-job'], $fake->pushed('queued-job')->all());
        $this->assertNotSame($background, $manager->connection('background'));
    }

    #[DataProvider('failedJobProviders')]
    public function testFailedJobProviderIsSelectedExplicitly(mixed $driver, string $provider): void
    {
        $this->app->make('config')->set('queue.failed.driver', $driver);
        $this->app->forgetInstance('queue.failer');

        $this->assertInstanceOf($provider, $this->app->make('queue.failer'));
    }

    public static function failedJobProviders(): array
    {
        return [
            'null value' => [null, NullFailedJobProvider::class],
            'null driver' => ['null', NullFailedJobProvider::class],
            'file driver' => ['file', FileFailedJobProvider::class],
            'database driver' => ['database', DatabaseFailedJobProvider::class],
            'database UUID driver' => ['database-uuids', DatabaseUuidFailedJobProvider::class],
        ];
    }

    public function testUnsupportedFailedJobProviderIsRejected(): void
    {
        $this->app->make('config')->set('queue.failed.driver', 'unsupported');
        $this->app->forgetInstance('queue.failer');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported failed job provider [unsupported].');

        $this->app->make('queue.failer');
    }

    public function testFileFailedJobProviderUsesItsDefaultsWhenOptionalConfigIsOmitted(): void
    {
        $this->app->make('config')->set('queue.failed', ['driver' => 'file']);
        $this->app->forgetInstance('queue.failer');

        $provider = $this->app->make('queue.failer');

        $this->assertSame(
            $this->app->storagePath('framework/cache/failed-jobs.json'),
            (new ReflectionProperty(FileFailedJobProvider::class, 'path'))->getValue($provider),
        );
        $this->assertSame(
            100,
            (new ReflectionProperty(FileFailedJobProvider::class, 'limit'))->getValue($provider),
        );
    }
}
