<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Horizon;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

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
