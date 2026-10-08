<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Unit;

use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\DestructConnector;
use Hypervel\Tests\TestCase;

class DestructTest extends TestCase
{
    public function testTheConnectorCanStillBeDestructedProperly(): void
    {
        $destructed = false;
        $connector = new DestructConnector($destructed);

        $this->assertFalse($destructed);

        unset($connector);

        $this->assertTrue($destructed);
    }
}
