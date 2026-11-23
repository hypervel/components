<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Support;

use Hypervel\Doctor\Support\ActiveDrivers;
use Hypervel\Tests\Doctor\TestCase;

class ActiveDriversTest extends TestCase
{
    public function testMailerDiscoveryRequiresAnExplicitCompositeTransport(): void
    {
        config(['mail.mailers.failover' => ['mailers' => ['log']]]);

        $this->assertSame(['failover'], ActiveDrivers::mailers('failover'));
    }
}
