<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Features\RedisFeature;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Sentry\ClientBuilder;
use Sentry\Transport\TransportInterface;

class ConfigTest extends SentryTestCase
{
    public function testPoolIsConstructedFromSentryPoolConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'https://key@sentry.io/123',
            'sentry.pool' => [
                'max_objects' => 7,
                'wait_timeout' => 0.05,
                'max_lifetime' => 120,
            ],
        ]);

        // Verify the Pool is actually constructed with the config values from sentry.pool.
        // The old bug read from 'pools.sentry' which didn't exist, so the Pool always got defaults.
        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);

        $transport = $this->getTransportFromBuilder($builder);

        $this->assertInstanceOf(HttpPoolTransport::class, $transport);

        $pool = $this->getPoolFromTransport($transport);

        $this->assertSame(7, $pool->getOptions()->maxObjects);
        $this->assertSame(0.05, $pool->getOptions()->waitTimeout);
        $this->assertSame(120.0, $pool->getOptions()->maxLifetime);
        $this->assertSame(0.0, $pool->getOptions()->maxIdleTime);
        $this->assertNull($pool->getOptions()->idleTtl);
    }

    #[DataProvider('unsupportedPoolOptions')]
    public function testUnsupportedPoolOptionsAreRejected(string $name, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsupported Sentry pool option(s) [{$name}]. Supported options are [max_objects, wait_timeout, max_lifetime]."
        );

        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'https://key@sentry.io/123',
            'sentry.pool' => [$name => $value],
        ]);
    }

    public static function unsupportedPoolOptions(): array
    {
        return [
            ['min_retained_objects', 1],
            ['max_idle_time', 30],
            ['idle_ttl', 300],
            ['unknown', true],
        ];
    }

    public function testOldPoolsKeyIsNotUsed(): void
    {
        $this->assertNull($this->app['config']->get('pools.sentry'));
    }

    public function testRedisFeatureIsInDefaultFeaturesConfig(): void
    {
        $features = $this->app['config']->get('sentry.features', []);

        $this->assertContains(RedisFeature::class, $features);
    }

    public function testPoolWaitTimeoutDefaultIsSetForFastFail(): void
    {
        // Default config should have a low wait_timeout for backpressure
        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);
        $transport = $this->getTransportFromBuilder($builder);
        $pool = $this->getPoolFromTransport($transport);

        // Should be 10ms or less for fast-fail backpressure
        $this->assertLessThanOrEqual(0.01, $pool->getOptions()->waitTimeout);
    }

    private function getTransportFromBuilder(ClientBuilder $builder): TransportInterface
    {
        $reflection = new ReflectionProperty($builder, 'transport');

        return $reflection->getValue($builder);
    }

    private function getPoolFromTransport(HttpPoolTransport $transport): Pool
    {
        $reflection = new ReflectionProperty($transport, 'pool');

        return $reflection->getValue($transport);
    }
}
