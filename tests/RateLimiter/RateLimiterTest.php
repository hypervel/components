<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\DatabaseStore;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\SwooleStore;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Facades\RateLimiter as RateLimiterFacade;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

enum NamedLimiter: string
{
    case Api = 'api';
}

enum LimiterStore: string
{
    case Local = 'worker-array';
}

class RateLimiterTest extends TestCase
{
    public function testFacadeResolvesTheCanonicalManager(): void
    {
        $this->assertSame(
            $this->app->make(RateLimiter::class),
            RateLimiterFacade::getFacadeRoot(),
        );
    }

    public function testManagerResolvesAndCachesWrappedStores(): void
    {
        $manager = $this->app->make(RateLimiter::class);

        $first = $manager->store();
        $second = $manager->store(LimiterStore::Local);

        $this->assertInstanceOf(Limiter::class, $first);
        $this->assertSame($first, $second);
        $this->assertInstanceOf(WorkerArrayStore::class, $first->getStore());

        $manager->purge('worker-array');

        $this->assertNotSame($first, $manager->store());
    }

    public function testManagerForgetsResolvedStores(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $resolved = $manager->store('worker-array');

        $this->assertSame($manager, $manager->forgetInstance('worker-array'));
        $this->assertNotSame($resolved, $manager->store('worker-array'));
    }

    public function testNamedLimiterStoresAreRegisteredAndNormalized(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $callback = static fn (): Limit => Limit::perMinute(10);

        $result = $manager->for(NamedLimiter::Api, $callback, LimiterStore::Local);

        $this->assertSame($manager, $result);
        $this->assertSame($callback, $manager->limiter('api'));
        $this->assertSame('worker-array', $manager->limiterStore(NamedLimiter::Api));
        $this->assertNull($manager->limiter('missing'));
        $this->assertNull($manager->limiterStore('missing'));
    }

    public function testScopeResolverRegisteredAfterStoreResolutionAffectsNamedOperations(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $store = $manager->store('worker-array');
        $policy = Limit::perMinute(1)->by('user');

        $this->assertTrue($store->consume($policy, 'api')->allowed());
        $this->assertTrue($store->inspect($policy, 'api')->denied());

        $manager->resolveKeyScopeUsing(static fn (string $name): string => 'tenant:' . $name);

        $this->assertTrue($store->consume($policy, 'api')->allowed());
        $this->assertTrue($store->inspect($policy, 'api')->denied());
    }

    public function testScopeResolversComposeInRegistrationOrderAndMayBeCleared(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $store = $manager->store('worker-array');
        $policy = Limit::perMinute(1)->by('user');
        $calls = [];

        $this->assertTrue($store->consume($policy, 'api')->allowed());
        $this->assertTrue($store->inspect($policy, 'api')->denied());

        $manager->resolveKeyScopeUsing(static function (string $name) use (&$calls): ?string {
            $calls[] = 'nullable:' . $name;

            return null;
        });

        $this->assertTrue($store->inspect($policy, 'api')->denied());
        $this->assertSame(['nullable:api'], $calls);

        $calls = [];
        $manager->resolveKeyScopeUsing(static function (string $name) use (&$calls): string {
            $calls[] = 'tenant:' . $name;

            return 'tenant:7';
        });

        $this->assertTrue($store->consume($policy, 'api')->allowed());
        $this->assertSame(['nullable:api', 'tenant:api'], $calls);

        $calls = [];
        $manager->resolveKeyScopeUsing(static function (string $name) use (&$calls): string {
            $calls[] = 'account:' . $name;

            return 'account:9';
        });

        $this->assertTrue($store->consume($policy, 'api')->allowed());
        $this->assertSame(
            ['nullable:api', 'tenant:api', 'account:api'],
            $calls,
        );

        $calls = [];
        $manager->resolveKeyScopeUsing(null);
        $manager->resolveKeyScopeUsing(static function (string $name) use (&$calls): string {
            $calls[] = 'account:' . $name;

            return 'account:9';
        });

        $this->assertTrue($store->consume($policy, 'api')->allowed());
        $this->assertSame(['account:api'], $calls);

        $calls = [];
        $manager->resolveKeyScopeUsing(null);

        $this->assertTrue($store->inspect($policy, 'api')->denied());
        $this->assertSame([], $calls);
    }

    public function testCustomDriverReturnsAStoreThatIsWrappedOnce(): void
    {
        config([
            'rate-limiter.stores.custom' => [
                'driver' => 'custom',
                'name' => 'spoofed',
            ],
        ]);

        $manager = $this->app->make(RateLimiter::class);
        $created = 0;
        $test = $this;

        $manager->extend('custom', function ($app, array $config) use (&$created, $test): WorkerArrayStore {
            ++$created;

            $test->assertSame('custom', $config['name']);

            return new WorkerArrayStore;
        });

        $first = $manager->store('custom');
        $second = $manager->store('custom');

        $this->assertSame($first, $second);
        $this->assertSame(1, $created);
    }

    public function testCustomDriversMustReturnAStore(): void
    {
        config([
            'rate-limiter.stores.invalid' => [
                'driver' => 'invalid',
            ],
        ]);

        $manager = $this->app->make(RateLimiter::class);
        $manager->extend('invalid', static fn (): stdClass => new stdClass);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must return an instance of [Hypervel\RateLimiter\Contracts\Store]');

        $manager->store('invalid');
    }

    public function testDatabaseStoreMayOmitTheConnection(): void
    {
        config([
            'rate-limiter.stores.database-default' => [
                'driver' => 'database',
                'table' => 'rate_limits',
            ],
        ]);

        $store = $this->app->make(RateLimiter::class)->store('database-default')->getStore();

        $this->assertInstanceOf(DatabaseStore::class, $store);
        $this->assertNull((new ClassInvoker($store))->connectionName);
    }

    public function testSwooleStoreMayOmitTheMemoryLimitBuffer(): void
    {
        $config = config()->array('rate-limiter.stores.swoole');
        unset($config['memory_limit_buffer']);
        config(['rate-limiter.stores.swoole-default' => $config]);

        $store = $this->app->make(RateLimiter::class)->store('swoole-default')->getStore();

        $this->assertInstanceOf(SwooleStore::class, $store);
        $this->assertSame(0.05, (new ClassInvoker($store))->memoryLimitBuffer);
    }

    #[DataProvider('invalidStoreConfigurations')]
    public function testInvalidStoreConfigurationsFailWhenResolved(
        array $config,
        string $exception,
        string $message,
    ): void {
        config(['rate-limiter.stores.invalid' => $config]);

        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $this->app->make(RateLimiter::class)->store('invalid');
    }

    public static function invalidStoreConfigurations(): array
    {
        return [
            'missing driver' => [
                [],
                RuntimeException::class,
                'does not specify a driver',
            ],
            'invalid database connection' => [
                ['driver' => 'database', 'connection' => false, 'table' => 'rate_limits'],
                InvalidArgumentException::class,
                'database connection must be null or a non-empty string',
            ],
            'invalid database table' => [
                ['driver' => 'database', 'connection' => null, 'table' => ''],
                InvalidArgumentException::class,
                'database table must be a non-empty string',
            ],
            'invalid Redis connection' => [
                ['driver' => 'redis', 'connection' => null],
                InvalidArgumentException::class,
                'Redis connection must be a non-empty string',
            ],
            'invalid Swoole memory buffer' => [
                ['driver' => 'swoole', 'memory_limit_buffer' => '0.05'],
                InvalidArgumentException::class,
                'memory limit buffer must be numeric',
            ],
        ];
    }

    public function testDefaultStoreMayBeChangedAtBootTime(): void
    {
        $manager = $this->app->make(RateLimiter::class);

        $manager->setDefaultInstance('worker-array');

        $this->assertSame('worker-array', $manager->getDefaultInstance());
        $this->assertSame($manager->store('worker-array'), $manager->store());
    }
}
