<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Testbench\TestCase;
use Redis;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class RedisConnectionIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    protected bool $isOlderThan6 = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isOlderThan6 = version_compare((string) phpversion('redis'), '6.0.0', '<');
    }

    public function testPhpRedisConnectSignatureAndConnection(): void
    {
        $redis = new Redis;
        $reflection = new ReflectionClass($redis);
        $parameters = $reflection->getMethod('connect')->getParameters();

        $this->assertSame('host', $parameters[0]->getName());
        $this->assertSame('port', $parameters[1]->getName());
        $this->assertSame('timeout', $parameters[2]->getName());

        if ($this->isOlderThan6) {
            $this->assertSame('retry_interval', $parameters[3]->getName());
        } else {
            $this->assertSame('persistent_id', $parameters[3]->getName());
        }

        $connected = $redis->connect(
            env('REDIS_HOST', '127.0.0.1'),
            (int) env('REDIS_PORT', 6379),
            0.0,
            null,
            0,
            0,
        );

        $this->assertTrue($connected);

        $auth = env('REDIS_PASSWORD', null);
        if (is_string($auth) && $auth !== '') {
            $this->assertTrue($redis->auth($auth));
        }

        $this->assertTrue(
            $redis->select($this->getParallelRedisDb())
        );

        $ping = $redis->ping();
        $this->assertTrue($ping === true || str_contains((string) $ping, 'PONG'));

        $redis->close();
    }
}
