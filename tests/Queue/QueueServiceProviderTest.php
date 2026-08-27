<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Foundation\Application;
use Hypervel\Queue\BackgroundQueue;
use Hypervel\Queue\DeferredQueue;
use Hypervel\Queue\Failed\DatabaseFailedJobProvider;
use Hypervel\Queue\Failed\DatabaseUuidFailedJobProvider;
use Hypervel\Queue\Failed\FileFailedJobProvider;
use Hypervel\Queue\Failed\NullFailedJobProvider;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueueServiceProvider;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

class QueueServiceProviderTest extends TestCase
{
    public function testBackgroundAndDeferredConnectionsAreLazyAndReportExceptions(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->twice()->with(m::type(RuntimeException::class));
        $this->app->instance(ExceptionHandler::class, $handler);

        $manager = $this->app->make('queue');

        $this->assertSame($manager, $this->app->make(QueueManager::class));
        $this->assertFalse($manager->connected('background'));
        $this->assertFalse($manager->connected('deferred'));

        $background = $manager->connection('background');
        $deferred = $manager->connection('deferred');

        $backgroundCallback = (new ReflectionProperty(BackgroundQueue::class, 'exceptionCallback'))
            ->getValue($background);
        $deferredCallback = (new ReflectionProperty(DeferredQueue::class, 'exceptionCallback'))
            ->getValue($deferred);

        $this->assertIsCallable($backgroundCallback);
        $this->assertIsCallable($deferredCallback);
        $backgroundCallback(new RuntimeException('Background failed.'));
        $deferredCallback(new RuntimeException('Deferred failed.'));
    }

    public function testBackgroundAndDeferredConnectionsAllowNoExceptionReporter(): void
    {
        $originalContainer = Container::getInstance();

        try {
            $application = new Application;
            $application->instance('config', new Repository([
                'queue' => [
                    'connections' => [
                        'background' => ['driver' => 'background'],
                        'deferred' => ['driver' => 'deferred'],
                    ],
                ],
            ]));
            (new QueueServiceProvider($application))->register();

            $manager = $application->make('queue');

            $this->assertNull(
                (new ReflectionProperty(BackgroundQueue::class, 'exceptionCallback'))
                    ->getValue($manager->connection('background')),
            );
            $this->assertNull(
                (new ReflectionProperty(DeferredQueue::class, 'exceptionCallback'))
                    ->getValue($manager->connection('deferred')),
            );
        } finally {
            Container::setInstance($originalContainer);
        }
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
