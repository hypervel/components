<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Horizon;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class RedisPrefixTest extends IntegrationTestCase
{
    public function testPrefixCanBeConfigured()
    {
        config(['horizon.prefix' => 'custom:']);

        Horizon::use('default');

        $this->assertSame('custom:', config('database.redis.horizon.options.prefix'));
    }
}
