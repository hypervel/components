<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Credentials\EcsCredentialProvider;
use Aws\Credentials\InstanceProfileProvider;
use Aws\Exception\CredentialsException;
use Closure;
use DateInterval;
use DateTimeInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Hypervel\Cache\ArrayLock;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Queue\AwsCredentialCache;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use ReflectionFunction;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnitEnum;

class QueueSqsConnectorTest extends TestCase
{
    protected string $tempDir;

    /**
     * Create an isolated directory for shared AWS configuration files.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('QueueSqsConnectorTest');
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    /**
     * Remove the isolated directory.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testCredentialsAreWrappedWithASharedCacheProviderWhenCachingIsEnabled(): void
    {
        $config = $this->credentials([
            'credential_cache' => ['enabled' => true],
        ]);

        $this->assertInstanceOf(Closure::class, $config['credentials']);
    }

    public function testCredentialsAreNotWrappedWhenCachingIsNotConfigured(): void
    {
        $config = $this->credentials([]);

        $this->assertNull($config['credentials']);
    }

    public function testCredentialsAreNotWrappedWhenCachingIsDisabled(): void
    {
        $config = $this->credentials([
            'credential_cache' => ['enabled' => false],
        ]);

        $this->assertNull($config['credentials']);
    }

    public function testExplicitCredentialsAreLeftUntouched(): void
    {
        $config = $this->credentials([
            'credentials' => false,
            'credential_cache' => ['enabled' => true],
        ]);

        $this->assertFalse($config['credentials']);
    }

    public function testStaticKeyAndSecretCredentialsAreLeftUntouched(): void
    {
        $config = $this->credentials([
            'key' => 'static-key',
            'secret' => 'static-secret',
            'credential_cache' => ['enabled' => true],
        ]);

        $this->assertSame(['key' => 'static-key', 'secret' => 'static-secret'], $config['credentials']);
    }

    public function testCredentialCacheHitsDoNotInvokeTheUnderlyingProvider(): void
    {
        $repository = new Repository(new ArrayStore);
        $repository->forever('credentials', new Credentials('cached-key', 'cached-secret', 'cached-token', time() + 3600));

        $provider = fn () => (new AwsCredentialCache(fn () => $repository))->resolve(
            'credentials',
            fn () => $this->fail('The underlying provider should not be invoked on a cache hit.'),
        );

        $this->assertSame('cached-key', $provider()->wait()->getAccessKeyId());
    }

    public function testCredentialResolutionRefreshesCredentialsBeforeTheyExpire(): void
    {
        $repository = new Repository(new ArrayStore);
        $repository->forever('credentials', new Credentials('expiring-key', 'expiring-secret', expires: time() + 30));
        $calls = 0;

        $credentials = (new AwsCredentialCache(fn () => $repository))->resolve(
            'credentials',
            function () use (&$calls) {
                ++$calls;

                return Create::promiseFor(new Credentials('fresh-key', 'fresh-secret', expires: time() + 3600));
            },
        )->wait();

        $this->assertSame(1, $calls);
        $this->assertSame('fresh-key', $credentials->getAccessKeyId());
        $this->assertSame('fresh-key', $repository->get('credentials')->getAccessKeyId());
    }

    public function testCredentialResolutionReusesCredentialsRefreshedByAnotherProcess(): void
    {
        $repository = new Repository(new ArrayStore);
        $cache = new AwsCredentialCache(fn () => $repository);
        $calls = 0;
        $provider = function () use (&$calls) {
            ++$calls;

            return Create::promiseFor(new Credentials('shared-key', 'shared-secret', expires: time() + 3600));
        };

        $first = $cache->resolve('credentials', $provider)->wait();
        $second = $cache->resolve('credentials', $provider)->wait();

        $this->assertSame(1, $calls);
        $this->assertSame('shared-key', $first->getAccessKeyId());
        $this->assertSame('shared-key', $second->getAccessKeyId());
    }

    public function testCredentialResolutionDoesNotCacheCredentialsWithoutAnExpiration(): void
    {
        $repository = new Repository(new ArrayStore);
        $repository->forever('credentials', new Credentials('stale-key', 'stale-secret'));
        $cache = new AwsCredentialCache(fn () => $repository);
        $calls = 0;
        $provider = function () use (&$calls): PromiseInterface {
            ++$calls;

            return Create::promiseFor(new Credentials('constant-key', 'constant-secret'));
        };

        $first = $cache->resolve('credentials', $provider)->wait();
        $second = $cache->resolve('credentials', $provider)->wait();

        $this->assertSame(2, $calls);
        $this->assertSame('constant-key', $first->getAccessKeyId());
        $this->assertSame('constant-key', $second->getAccessKeyId());
        $this->assertFalse($repository->has('credentials'));
    }

    public function testCredentialResolutionFallsBackToADirectFetchWhenTheCacheStoreIsUnavailable(): void
    {
        // A broken cache backend (e.g. Redis down) must behave exactly like a
        // cache miss rather than breaking credential resolution.
        $provider = fn () => (new AwsCredentialCache(
            fn () => throw new RuntimeException('Cache store is down.'),
        ))->resolve(
            'credentials',
            fn () => Create::promiseFor(new Credentials('direct-key', 'direct-secret')),
        );

        $this->assertSame('direct-key', $provider()->wait()->getAccessKeyId());
    }

    public function testCredentialsAreServedFromTheFallbackStoreWhenThePrimaryStoreIsUnavailable(): void
    {
        $fallback = new Repository(new ArrayStore);
        $fallback->forever('credentials', new Credentials('fallback-key', 'fallback-secret', 'fallback-token', time() + 3600));

        // With the primary store down, the fallback keeps credential fetches
        // deduplicated rather than degrading straight to one fetch per process.
        $provider = fn () => (new AwsCredentialCache(
            fn () => throw new RuntimeException('Cache store is down.'),
            fn () => $fallback,
        ))->resolve(
            'credentials',
            fn () => $this->fail('The underlying provider should not be invoked on a fallback cache hit.'),
        );

        $this->assertSame('fallback-key', $provider()->wait()->getAccessKeyId());
    }

    public function testCredentialsAreWrittenThroughToTheFallbackStore(): void
    {
        $primary = new Repository(new ArrayStore);
        $fallback = new Repository(new ArrayStore);

        $cache = new AwsCredentialCache(fn () => $primary, fn () => $fallback);

        // The fallback is written through on every set so it is already warm
        // when the primary store becomes unavailable.
        $cache->resolve(
            'credentials',
            fn () => Create::promiseFor(new Credentials('shared-key', 'shared-secret', expires: time() + 3600)),
        )->wait();

        $this->assertSame('shared-key', $primary->get('credentials')->getAccessKeyId());
        $this->assertSame('shared-key', $fallback->get('credentials')->getAccessKeyId());
    }

    public function testThePrimaryStoreIsPreferredOverTheFallbackStore(): void
    {
        $primary = new Repository(new ArrayStore);
        $primary->forever('credentials', new Credentials('primary-key', 'primary-secret', expires: time() + 3600));

        $fallback = new Repository(new ArrayStore);
        $fallback->forever('credentials', new Credentials('fallback-key', 'fallback-secret', expires: time() + 3600));

        $cache = new AwsCredentialCache(fn () => $primary, fn () => $fallback);

        $credentials = $cache->resolve(
            'credentials',
            fn () => $this->fail('The underlying provider should not be invoked on a cache hit.'),
        )->wait();

        $this->assertSame('primary-key', $credentials->getAccessKeyId());
    }

    public function testCredentialResolutionFallsBackToADirectFetchWhenEveryCacheStoreIsUnavailable(): void
    {
        $provider = fn () => (new AwsCredentialCache(
            fn () => throw new RuntimeException('Cache store is down.'),
            fn () => throw new RuntimeException('Fallback store is down too.'),
        ))->resolve(
            'credentials',
            fn () => Create::promiseFor(new Credentials('direct-key', 'direct-secret')),
        );

        $this->assertSame('direct-key', $provider()->wait()->getAccessKeyId());
    }

    public function testCancellationWhileRecheckingCachedCredentialsReleasesTheRefreshLock(): void
    {
        $cancellation = new CanceledException('canceled');
        $repository = new InterruptibleCredentialRepository(new ArrayStore);
        // The first read misses before locking; the second is the recheck under the lock.
        $repository->getFailures[2] = $cancellation;

        try {
            (new AwsCredentialCache(fn () => $repository))->resolve(
                'credentials',
                fn () => $this->fail('The provider should not run after the recheck is canceled.'),
            );

            $this->fail('The cancellation should propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertRefreshLockIsFree($repository);
    }

    public function testCancellationWhileStoringCredentialsReleasesTheRefreshLock(): void
    {
        $cancellation = new CanceledException('canceled');
        $repository = new InterruptibleCredentialRepository(new ArrayStore);
        $repository->putFailure = $cancellation;

        $promise = (new AwsCredentialCache(fn () => $repository))->resolve(
            'credentials',
            fn () => Create::promiseFor(new Credentials('fresh-key', 'fresh-secret', expires: time() + 3600)),
        );

        try {
            $promise->wait();

            $this->fail('The cancellation should propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertRefreshLockIsFree($repository);
    }

    /**
     * @param Closure(Throwable): mixed $provider
     */
    #[DataProvider('failingCredentialProviders')]
    public function testFailedCredentialFetchesReleaseTheRefreshLock(Closure $provider): void
    {
        $failure = new RuntimeException('Credential fetch failed.');
        $repository = new Repository(new ArrayStore);

        try {
            (new AwsCredentialCache(fn () => $repository))
                ->resolve('credentials', fn () => $provider($failure))
                ->wait();

            $this->fail('The provider failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertRefreshLockIsFree($repository);
    }

    /**
     * Get credential providers that fail synchronously or through rejection.
     */
    public static function failingCredentialProviders(): array
    {
        return [
            'provider throws' => [static fn (Throwable $failure) => throw $failure],
            'provider rejects' => [static fn (Throwable $failure) => Create::rejectionFor($failure)],
        ];
    }

    public function testRejectedCancellationSurvivesACanceledLockRelease(): void
    {
        $cancellation = new CanceledException('canceled');
        $store = new InterruptibleCredentialStore;
        $store->releaseFailure = new CanceledException('release canceled');
        $repository = new Repository($store);

        $promise = (new AwsCredentialCache(fn () => $repository))->resolve(
            'credentials',
            fn () => Create::rejectionFor($cancellation),
        );

        try {
            $promise->wait();

            $this->fail('The cancellation should propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCredentialsCacheKeyIsScopedToThePodIdentityAndConnection(): void
    {
        $this->withoutContainerCredentials(function () {
            $_SERVER['AWS_CONTAINER_CREDENTIALS_FULL_URI'] = 'http://169.254.170.23/v1/credentials';

            $key = SqsConnector::credentialsCacheKey(['region' => 'us-east-2', 'prefix' => 'prefix']);

            $this->assertNotSame($key, SqsConnector::credentialsCacheKey(['region' => 'us-west-2', 'prefix' => 'prefix']));
            $this->assertNotSame($key, SqsConnector::credentialsCacheKey(['region' => 'us-east-2', 'prefix' => 'other-prefix']));

            $_SERVER['AWS_CONTAINER_CREDENTIALS_FULL_URI'] = 'http://169.254.170.23/v1/other-credentials';

            $this->assertNotSame($key, SqsConnector::credentialsCacheKey(['region' => 'us-east-2', 'prefix' => 'prefix']));
        });
    }

    public function testCredentialsCacheKeyDistinguishesTheSelectedCredentialSource(): void
    {
        $key = static fn (mixed $credentials = null, array $config = []): string => SqsConnector::credentialsCacheKey([
            'region' => 'us-east-2',
            'credentials' => $credentials,
            ...$config,
        ]);

        $instance = $key(['provider' => 'instance', 'profile' => 'role-a']);

        $this->assertNotSame($instance, $key(['provider' => 'instance', 'profile' => 'role-b']));
        $this->assertNotSame($instance, $key(['provider' => 'instance', 'profile' => 'role-a', 'ec2_metadata_service_endpoint' => 'http://[fd00:ec2::254]']));
        $this->assertNotSame($instance, $key(['provider' => 'instance', 'profile' => 'role-a', 'ec2_metadata_service_endpoint_mode' => 'IPv6']));
        $this->assertNotSame($instance, $key(['provider' => 'instance', 'profile' => 'role-a', 'use_aws_shared_config_files' => false]));
        $this->assertNotSame($instance, $this->withEnvironment(
            [CredentialProvider::ENV_PROFILE => 'other-profile'],
            fn () => $key(['provider' => 'instance', 'profile' => 'role-a']),
        ));
        $this->assertNotSame(
            $this->withEnvironment([InstanceProfileProvider::ENV_DISABLE => null], fn () => $key(['provider' => 'instance', 'profile' => 'role-a'])),
            $this->withEnvironment([InstanceProfileProvider::ENV_DISABLE => 'true'], fn () => $key(['provider' => 'instance', 'profile' => 'role-a'])),
        );

        $container = $key('ecs');

        $this->assertNotSame($container, $this->withEnvironment(
            [EcsCredentialProvider::ENV_URI => '/v2/credentials/other-task'],
            fn () => $key('ecs'),
        ));
        $this->assertNotSame($container, $this->withEnvironment(
            [EcsCredentialProvider::ENV_AUTH_TOKEN => 'other-token'],
            fn () => $key('ecs'),
        ));

        $default = $key();

        $this->assertNotSame($default, $this->withEnvironment(
            [CredentialProvider::ENV_KEY => 'other-key', CredentialProvider::ENV_SECRET => 'other-secret'],
            $key,
        ));
        $this->assertNotSame($default, $this->withEnvironment([CredentialProvider::ENV_ARN => 'arn:aws:iam::1:role/other'], $key));
        $this->assertNotSame($default, $this->withEnvironment([CredentialProvider::ENV_SHARED_CREDENTIALS_FILE => '/other/credentials'], $key));
        $this->assertNotSame($default, $key(config: ['disableAssumeRole' => true]));
        $this->assertNotSame(
            $this->withEnvironment([InstanceProfileProvider::ENV_DISABLE => null], $key),
            $this->withEnvironment([InstanceProfileProvider::ENV_DISABLE => 'true'], $key),
        );
    }

    public function testCredentialsCacheKeyUsesServerContainerUrisOnlyWhenTheEnvironmentHasNone(): void
    {
        $this->withoutContainerCredentials(function () {
            $_SERVER[EcsCredentialProvider::ENV_URI] = '/v2/credentials/server-task';

            $key = SqsConnector::credentialsCacheKey(['credentials' => 'ecs']);

            $this->assertSame($key, $this->withEnvironment(
                [EcsCredentialProvider::ENV_URI => '/v2/credentials/server-task'],
                fn () => SqsConnector::credentialsCacheKey(['credentials' => 'ecs']),
            ));
            $this->assertNotSame($key, $this->withEnvironment(
                [EcsCredentialProvider::ENV_URI => '/v2/credentials/environment-task'],
                fn () => SqsConnector::credentialsCacheKey(['credentials' => 'ecs']),
            ));
        });
    }

    public function testInstanceProfilesDoNotReuseEachOthersCachedCredentials(): void
    {
        $this->useCredentialCache(new Repository(new ArrayStore));

        $client = static fn (RequestInterface $request): PromiseInterface => Create::promiseFor(new Response(200, [], $request->getMethod() === 'PUT'
            ? 'metadata-token'
            : json_encode([
                'Code' => 'Success',
                'AccessKeyId' => basename($request->getUri()->getPath()) . '-key',
                'SecretAccessKey' => 'secret',
                'Token' => 'token',
                'Expiration' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            ], JSON_THROW_ON_ERROR)));

        $credentials = fn (string $role): Credentials => $this->withEnvironment(
            [InstanceProfileProvider::ENV_DISABLE => null],
            fn () => (new SqsConnector)->connect($this->config([
                'credentials' => ['provider' => 'instance', 'profile' => $role, 'client' => $client],
                'credential_cache' => ['enabled' => true],
            ]))->getSqs()->getCredentials()->wait(),
        );

        $this->assertSame('role-a-key', $credentials('role-a')->getAccessKeyId());
        $this->assertSame('role-b-key', $credentials('role-b')->getAccessKeyId());
    }

    public function testCachedDefaultChainCredentialsFollowReplacedEnvironmentCredentials(): void
    {
        $this->useCredentialCache(new Repository(new ArrayStore));

        $credentials = fn (string $key): Credentials => $this->withEnvironment([
            CredentialProvider::ENV_KEY => $key,
            CredentialProvider::ENV_SECRET => "{$key}-secret",
            CredentialProvider::ENV_SESSION => null,
        ], fn () => (new SqsConnector)->connect($this->config([
            'credential_cache' => ['enabled' => true],
        ]))->getSqs()->getCredentials()->wait());

        $this->assertSame('probe-first', $credentials('probe-first')->getAccessKeyId());
        $this->assertSame('probe-second', $credentials('probe-second')->getAccessKeyId());
    }

    #[DataProvider('credentialCachingStates')]
    public function testCachedDefaultChainKeepsTheConfiguredSharedFileSetting(bool $cachingEnabled): void
    {
        $this->useCredentialCache(new Repository(new ArrayStore));

        $credentials = fn (array $config): Credentials => $this->withSharedCredentialsFile(
            fn () => (new SqsConnector)->connect($this->config([
                'credential_cache' => ['enabled' => $cachingEnabled],
                ...$config,
            ]))->getSqs()->getCredentials()->wait(),
        );

        $this->assertSame('file-key', $credentials([])->getAccessKeyId());

        $this->expectException(CredentialsException::class);

        $credentials(['use_aws_shared_config_files' => false]);
    }

    /**
     * Get the credential caching states to compare.
     */
    public static function credentialCachingStates(): array
    {
        return [
            'caching disabled' => [false],
            'caching enabled' => [true],
        ];
    }

    public function testConnectSucceedsWhenOptionalSdkConfigurationIsOmitted(): void
    {
        $queue = (new SqsConnector)->connect([
            'queue' => 'default',
            'region' => 'us-east-1',
        ]);

        $this->assertInstanceOf(SqsQueue::class, $queue);
        $this->assertSame('', (new ClassInvoker($queue))->prefix);
        $this->assertSame('', (new ClassInvoker($queue))->suffix);
        $this->assertTrue((new ClassInvoker($queue))->dispatchAfterCommit);
        $this->assertSame([], (new ClassInvoker($queue))->overflowStorage);
    }

    public function testDefaultConfigurationPreservesIndividualHttpOverridesAndOptions(): void
    {
        $config = (new QueueSqsConnectorStub)->defaultConfiguration([
            'http' => [
                'timeout' => 15,
                'proxy' => 'http://proxy.test',
            ],
        ]);

        $this->assertNull($config['credentials']);
        $this->assertNull($config['key']);
        $this->assertNull($config['secret']);
        $this->assertNull($config['token']);
        $this->assertSame('', $config['prefix']);
        $this->assertSame('', $config['suffix']);
        $this->assertTrue($config['after_commit']);
        $this->assertSame([], $config['overflow']);
        $this->assertSame('latest', $config['version']);
        $this->assertSame([
            'timeout' => 15,
            'connect_timeout' => 60,
            'proxy' => 'http://proxy.test',
        ], $config['http']);
        $this->assertSame([
            'store' => null,
            'fallback_store' => null,
            'enabled' => false,
        ], $config['credential_cache']);
    }

    #[DataProvider('credentialCacheEnabledSettings')]
    public function testCredentialCacheEnabledSettingIsNormalizedToABoolean(mixed $enabled, bool $expected): void
    {
        $config = (new QueueSqsConnectorStub)->defaultConfiguration([
            'credential_cache' => ['enabled' => $enabled],
        ]);

        $this->assertSame($expected, $config['credential_cache']['enabled']);
    }

    /**
     * Get raw credential cache settings and their normalized values.
     */
    public static function credentialCacheEnabledSettings(): array
    {
        return [
            'true' => [true, true],
            'integer one' => [1, true],
            'string one' => ['1', true],
            'string zero' => ['0', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('incompleteStaticCredentials')]
    public function testConnectRejectsIncompleteStaticCredentials(?string $key, ?string $secret): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The SQS access key and secret must be configured together.');

        (new SqsConnector)->connect($this->config([
            'key' => $key,
            'secret' => $secret,
        ]));
    }

    public static function incompleteStaticCredentials(): array
    {
        return [
            'key only' => ['key', null],
            'secret only' => [null, 'secret'],
        ];
    }

    public function testConnectBuildsStaticCredentialsWithToken(): void
    {
        $queue = (new SqsConnector)->connect($this->config([
            'key' => 'key',
            'secret' => 'secret',
            'token' => 'token',
        ]));

        $credentials = $queue->getSqs()->getCredentials()->wait();

        $this->assertSame('key', $credentials->getAccessKeyId());
        $this->assertSame('secret', $credentials->getSecretKey());
        $this->assertSame('token', $credentials->getSecurityToken());
        $this->assertNull($queue->getSqs()->getConfig('token'));
    }

    public function testCallableAndObjectCredentialsPassThrough(): void
    {
        $connector = new QueueSqsConnectorStub;
        $callable = static fn () => null;
        $object = new Credentials('key', 'secret');

        $this->assertSame($callable, $connector->resolveCredentials([
            'credentials' => $callable,
        ]));
        $this->assertSame($object, $connector->resolveCredentials([
            'credentials' => $object,
        ]));
    }

    #[DataProvider('namedCredentialProvider')]
    public function testNamedCredentialProvidersReceiveOptionsAndAreMemoized(
        string $provider,
        array $options,
        string $expectedClass,
        string $option,
        mixed $expected,
    ): void {
        $credentials = (new QueueSqsConnectorStub)->resolveCredentials([
            'credentials' => ['provider' => $provider, ...$options],
        ]);

        $this->assertInstanceOf(Closure::class, $credentials);

        $resolved = (new ReflectionFunction($credentials))
            ->getClosureUsedVariables()['provider'];

        $this->assertInstanceOf($expectedClass, $resolved);
        $this->assertSame($expected, (new ClassInvoker($resolved))->{$option});
    }

    public static function namedCredentialProvider(): array
    {
        return [
            'ecs' => ['ecs', ['timeout' => 2], EcsCredentialProvider::class, 'timeout', 2],
            'instance' => ['instance', ['profile' => 'worker'], InstanceProfileProvider::class, 'profile', 'worker'],
        ];
    }

    public function testNamedCredentialProviderCanBeGivenAsString(): void
    {
        $credentials = (new QueueSqsConnectorStub)->resolveCredentials([
            'credentials' => 'ecs',
        ]);

        $this->assertInstanceOf(Closure::class, $credentials);
        $this->assertInstanceOf(
            EcsCredentialProvider::class,
            (new ReflectionFunction($credentials))->getClosureUsedVariables()['provider'],
        );
    }

    public function testInvalidNamedCredentialProviderFailsDescriptively(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credential provider [invalid].');

        (new QueueSqsConnectorStub)->resolveCredentials([
            'credentials' => ['provider' => 'invalid'],
        ]);
    }

    public function testQueueOnlyOptionsAreNotPassedToTheAwsClient(): void
    {
        $overflow = [
            'enabled' => true,
            'store' => 'sqs-overflow',
            'always' => false,
            'delete_after_processing' => true,
            'flush_on_clear' => false,
        ];

        $queue = (new SqsConnector)->connect($this->config([
            'overflow' => $overflow,
            'credential_cache' => ['enabled' => false],
        ]));

        $this->assertSame($overflow, (new ClassInvoker($queue))->overflowStorage);

        foreach (['driver', 'queue', 'prefix', 'suffix', 'after_commit', 'key', 'secret', 'token', 'overflow', 'credential_cache'] as $option) {
            $this->assertNull($queue->getSqs()->getConfig($option));
        }
    }

    protected function config(array $overrides = []): array
    {
        return [
            'queue' => 'default',
            'region' => 'us-east-1',
            ...$overrides,
        ];
    }

    /**
     * Run the given connection config through the connector's credential resolution.
     */
    protected function credentials(array $config): array
    {
        return (new QueueSqsConnectorStub)->credentials([
            'driver' => 'sqs',
            'region' => 'us-east-2',
            'queue' => 'default',
            ...$config,
        ]);
    }

    /**
     * Resolve cache stores for credential caching from the given repository.
     */
    protected function useCredentialCache(Repository $repository): void
    {
        $cache = m::mock(Factory::class);
        $cache->shouldReceive('store')->andReturn($repository);

        Container::getInstance()->instance('cache', $cache);
    }

    /**
     * Run the callback with the given environment variables, restoring them afterwards.
     *
     * @param array<string, null|string> $variables
     */
    protected function withEnvironment(array $variables, Closure $callback): mixed
    {
        $original = [];

        foreach ($variables as $name => $value) {
            $original[$name] = getenv($name);

            putenv($value === null ? $name : "{$name}={$value}");
        }

        try {
            return $callback();
        } finally {
            foreach ($original as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    /**
     * Run the callback with only a shared credentials file available to the default chain.
     */
    protected function withSharedCredentialsFile(Closure $callback): mixed
    {
        file_put_contents(
            $credentialsFile = $this->tempDir . '/credentials',
            "[default]\naws_access_key_id = file-key\naws_secret_access_key = file-secret\n",
        );

        return $this->withoutContainerCredentials(fn () => $this->withEnvironment([
            CredentialProvider::ENV_SHARED_CREDENTIALS_FILE => $credentialsFile,
            CredentialProvider::ENV_CONFIG_FILE => $this->tempDir . '/config',
            CredentialProvider::ENV_KEY => null,
            CredentialProvider::ENV_SECRET => null,
            CredentialProvider::ENV_SESSION => null,
            CredentialProvider::ENV_PROFILE => null,
            CredentialProvider::ENV_ARN => null,
            CredentialProvider::ENV_TOKEN_FILE => null,
            InstanceProfileProvider::ENV_DISABLE => 'true',
        ], $callback));
    }

    /**
     * Run the callback without container credential URIs in the environment or $_SERVER, restoring both afterwards.
     */
    protected function withoutContainerCredentials(Closure $callback): mixed
    {
        $names = [EcsCredentialProvider::ENV_URI, EcsCredentialProvider::ENV_FULL_URI];
        $server = array_intersect_key($_SERVER, array_flip($names));

        foreach ($names as $name) {
            unset($_SERVER[$name]);
        }

        try {
            return $this->withEnvironment(array_fill_keys($names, null), $callback);
        } finally {
            foreach ($names as $name) {
                if (array_key_exists($name, $server)) {
                    $_SERVER[$name] = $server[$name];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    /**
     * Assert that the credential refresh lock is not held.
     */
    protected function assertRefreshLockIsFree(Repository $repository): void
    {
        $this->assertTrue($repository->getStore()->lock('credentials:refresh', 15)->get());
    }
}

class QueueSqsConnectorStub extends SqsConnector
{
    public function defaultConfiguration(array $config): array
    {
        return $this->getDefaultConfiguration($config);
    }

    /**
     * Resolve the credential provider for the normalized connection config.
     */
    public function resolveCredentials(array $config): mixed
    {
        return $this->resolveCredentialProvider($this->getDefaultConfiguration($config));
    }

    /**
     * Configure the credentials for the normalized connection config.
     */
    public function credentials(array $config): array
    {
        return $this->withCredentials($this->getDefaultConfiguration($config));
    }
}

class InterruptibleCredentialRepository extends Repository
{
    /**
     * Failures thrown by numbered get() calls.
     *
     * @var array<int, Throwable>
     */
    public array $getFailures = [];

    /**
     * The failure thrown by put(), if any.
     */
    public ?Throwable $putFailure = null;

    /**
     * The number of get() calls made.
     */
    protected int $getCalls = 0;

    /**
     * Retrieve an item from the cache, or throw the failure queued for this call.
     */
    public function get(array|UnitEnum|string $key, mixed $default = null): mixed
    {
        if ($failure = $this->getFailures[++$this->getCalls] ?? null) {
            throw $failure;
        }

        return parent::get($key, $default);
    }

    /**
     * Store an item in the cache, or throw the queued put failure.
     */
    public function put(array|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if ($this->putFailure) {
            throw $this->putFailure;
        }

        return parent::put($key, $value, $ttl);
    }
}

class InterruptibleCredentialStore extends ArrayStore
{
    /**
     * The failure thrown when releasing a lock, if any.
     */
    public ?Throwable $releaseFailure = null;

    /**
     * Get a lock instance that throws the queued release failure.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): ArrayLock
    {
        $lock = new InterruptibleCredentialLock($this, $name, $seconds, $owner);
        $lock->releaseFailure = $this->releaseFailure;

        return $lock;
    }
}

class InterruptibleCredentialLock extends ArrayLock
{
    /**
     * The failure thrown when releasing the lock, if any.
     */
    public ?Throwable $releaseFailure = null;

    /**
     * Release the lock, or throw the queued release failure.
     */
    public function release(): bool
    {
        if ($this->releaseFailure) {
            throw $this->releaseFailure;
        }

        return parent::release();
    }
}
