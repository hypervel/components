<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Support;

use Hypervel\Doctor\Support\ActiveDrivers;
use Hypervel\Tests\Doctor\TestCase;

class ActiveDriversTest extends TestCase
{
    public function testBuiltInNullDriversIgnoreRedisConfigurationUnderTheSameName(): void
    {
        config([
            'cache.default' => 'null',
            'cache.stores.null' => ['driver' => 'redis'],
            'queue.default' => 'null',
            'queue.connections.null' => ['driver' => 'redis'],
            'session.driver' => null,
        ]);

        $this->assertSame([], ActiveDrivers::redisConnections());
    }

    public function testMailerDiscoveryRequiresAnExplicitCompositeTransport(): void
    {
        config(['mail.mailers.failover' => ['mailers' => ['log']]]);

        $this->assertSame(['failover'], ActiveDrivers::mailers('failover'));
    }
}
