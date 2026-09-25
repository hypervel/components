<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use __PHP_Incomplete_Class;
use Aws\Credentials\Credentials;
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
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

class QueueServiceProviderTest extends TestCase
{
    // REMOVED: DynamoDbFailedJobProviderTest; DynamoDB failed-job storage is unsupported.

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

    #[DefineEnvironment('enableSqsCredentialCaching')]
    public function testSqsCredentialCachingAllowsCachedCredentialsToBeUnserialized(): void
    {
        $store = $this->app->make('cache')->store('serialized');
        $store->forever('credentials', new Credentials('key', 'secret', 'token', 1893456000));

        $credentials = $store->get('credentials');

        $this->assertInstanceOf(Credentials::class, $credentials);
        $this->assertSame('key', $credentials->getAccessKeyId());
        $this->assertSame('secret', $credentials->getSecretKey());
        $this->assertSame('token', $credentials->getSecurityToken());
        $this->assertSame(1893456000, $credentials->getExpiration());
    }

    #[DefineEnvironment('useSerializingCacheStore')]
    public function testSqsCredentialsAreNotUnserializedWithoutCredentialCaching(): void
    {
        $store = $this->app->make('cache')->store('serialized');
        $store->forever('credentials', new Credentials('key', 'secret'));

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $store->get('credentials'));
    }

    /**
     * Configure a serializing cache store under the shipped class policy.
     */
    protected function useSerializingCacheStore(Application $app): void
    {
        $app->make('config')->set([
            'cache.serializable_classes' => false,
            'cache.stores.serialized' => ['driver' => 'array', 'serialize' => true],
        ]);
    }

    /**
     * Enable SQS credential caching with an uncast setting, as the connector accepts.
     */
    protected function enableSqsCredentialCaching(Application $app): void
    {
        $this->useSerializingCacheStore($app);

        $app->make('config')->set('queue.connections.sqs.credential_cache', ['enabled' => '1']);
    }
}
