<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Horizon;
use Hypervel\Tests\Horizon\IntegrationTest;

/**
 * @internal
 * @coversNothing
 */
class RedisPrefixTest extends IntegrationTest
{
    public function testPrefixCanBeConfigured()
    {
        config(['horizon.prefix' => 'custom:']);

        Horizon::use('default');

        $this->assertSame('custom:', config('redis.horizon.options.prefix'));
    }
}
