<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Features\RedisFeature;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use ReflectionProperty;
use Sentry\ClientBuilder;
use Sentry\Transport\TransportInterface;

class ConfigTest extends SentryTestCase
{
    public function testPoolIsConstructedFromSentryPoolConfig()
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'https://key@sentry.io/123',
            'sentry.pool' => [
                'min_objects' => 2,
                'max_objects' => 7,
                'wait_timeout' => 0.05,
            ],
        ]);

        // Verify the Pool is actually constructed with the config values from sentry.pool.
        // The old bug read from 'pools.sentry' which didn't exist, so the Pool always got defaults.
        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);

        $transport = $this->getTransportFromBuilder($builder);

        $this->assertInstanceOf(HttpPoolTransport::class, $transport);

        $pool = $this->getPoolFromTransport($transport);

        $this->assertSame(7, $pool->getOption()->getMaxObjects());
        $this->assertSame(0.05, $pool->getOption()->getWaitTimeout());
    }

    public function testOldPoolsKeyIsNotUsed()
    {
        $this->assertNull($this->app['config']->get('pools.sentry'));
    }

    public function testRedisFeatureIsInDefaultFeaturesConfig()
    {
        $features = $this->app['config']->get('sentry.features', []);

        $this->assertContains(RedisFeature::class, $features);
    }

    public function testPoolWaitTimeoutDefaultIsSetForFastFail()
    {
        // Default config should have a low wait_timeout for backpressure
        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);
        $transport = $this->getTransportFromBuilder($builder);
        $pool = $this->getPoolFromTransport($transport);

        // Should be 10ms or less for fast-fail backpressure
        $this->assertLessThanOrEqual(0.01, $pool->getOption()->getWaitTimeout());
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
