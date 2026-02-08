<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Testbench\TestCase;
use Redis;
use ReflectionClass;

/**
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class RedisConnectionIntegrationTest extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

    protected bool $isOlderThan6 = false;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get(Repository::class);
        $this->configureRedisForTesting($config);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->isOlderThan6 = version_compare((string) phpversion('redis'), '6.0.0', '<');
    }

    public function testPhpRedisConnectSignatureAndConnection(): void
    {
        $redis = new Redis();
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

        $auth = env('REDIS_AUTH', null);
        if (is_string($auth) && $auth !== '') {
            $this->assertTrue($redis->auth($auth));
        }

        $this->assertTrue(
            $redis->select((int) env('REDIS_DB', $this->redisTestDatabase))
        );

        $ping = $redis->ping();
        $this->assertTrue($ping === true || str_contains((string) $ping, 'PONG'));

        $redis->close();
    }
}
