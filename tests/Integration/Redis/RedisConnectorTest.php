<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Tests that Redis connection configuration is correctly applied to the
 * underlying phpredis client. Ported from Laravel's RedisConnectorTest
 * (phpredis portions only — predis is not supported).
 */
class RedisConnectorTest extends TestCase
{
    use InteractsWithRedis;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        // Suppress pool log output
        $app->make('config')->set('app.stdout_log.level', []);
    }

    public function testDefaultConfiguration(): void
    {
        $host = $this->app->make('config')->get('database.redis.default.host');
        $port = $this->app->make('config')->get('database.redis.default.port');

        $this->withClient('default', function (\Redis $client) use ($host, $port): void {
            $this->assertSame($host, $client->getHost());
            $this->assertSame($port, $client->getPort());
        });
    }

    public function testUrl(): void
    {
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);

        $name = $this->addTestConnection([
            'url' => "redis://{$host}:{$port}",
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'database' => $this->getParallelRedisDb(),
        ]);

        $this->withClient($name, function (\Redis $client) use ($host, $port): void {
            // redis:// URL maps to tcp:// scheme via ConfigurationUrlParser driver aliases
            $this->assertSame("tcp://{$host}", $client->getHost());
            $this->assertEquals($port, $client->getPort());
        });
    }

    public function testUrlWithScheme(): void
    {
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);

        $name = $this->addTestConnection([
            'url' => "tcp://{$host}:{$port}",
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'database' => $this->getParallelRedisDb(),
        ]);

        $this->withClient($name, function (\Redis $client) use ($host, $port): void {
            $this->assertSame("tcp://{$host}", $client->getHost());
            $this->assertEquals($port, $client->getPort());
        });
    }

    public function testScheme(): void
    {
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);

        $name = $this->addTestConnection([
            'scheme' => 'tcp',
            'host' => $host,
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'port' => $port,
            'database' => $this->getParallelRedisDb(),
        ]);

        $this->withClient($name, function (\Redis $client) use ($host, $port): void {
            $this->assertSame("tcp://{$host}", $client->getHost());
            $this->assertEquals($port, $client->getPort());
        });
    }

    public function testPerConnectionPrefixOverridesGlobalPrefix(): void
    {
        $name = $this->addTestConnection([
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => $this->getParallelRedisDb(),
            'options' => [
                'prefix' => 'per_connection_',
            ],
        ]);

        // Set a global prefix that should be overridden
        $this->app->make('config')->set('database.redis.options.prefix', 'global_');

        // Must purge + re-resolve since config changed after initial resolution
        $this->app->make('redis')->purge($name);

        $this->withClient($name, function (\Redis $client): void {
            $this->assertSame('per_connection_', $client->getOption(\Redis::OPT_PREFIX));
        });
    }

    public function testTopLevelConnectionPrefixOverridesGlobalAndLocalPrefix(): void
    {
        $name = $this->addTestConnection([
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => $this->getParallelRedisDb(),
            'prefix' => 'top_level_',
            'options' => [
                'prefix' => 'per_connection_',
            ],
        ]);

        $this->app->make('config')->set('database.redis.options.prefix', 'global_');
        $this->app->make('redis')->purge($name);

        $this->withClient($name, function (\Redis $client): void {
            $this->assertSame('top_level_', $client->getOption(\Redis::OPT_PREFIX));
        });
    }

    public function testClientNameIsApplied(): void
    {
        $name = $this->addTestConnection([
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => $this->getParallelRedisDb(),
            'name' => 'hypervel-connector-test',
        ]);

        $this->withClient($name, function (\Redis $client): void {
            $this->assertSame('hypervel-connector-test', $client->client('GETNAME'));
        });
    }

    public function testTcpKeepaliveOptionIsApplied(): void
    {
        $name = $this->addTestConnection([
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => $this->getParallelRedisDb(),
            'options' => [
                'tcp_keepalive' => 60,
            ],
        ]);

        $this->withClient($name, function (\Redis $client): void {
            $this->assertSame(1, $client->getOption(\Redis::OPT_TCP_KEEPALIVE));
        });
    }

    /**
     * Execute a callback with the underlying phpredis client for a named connection.
     *
     * @param Closure(\Redis): void $callback
     */
    private function withClient(string $name, Closure $callback): void
    {
        Redis::connection($name)->withConnection(
            function (RedisConnection $connection) use ($callback): void {
                $client = $connection->client();
                $this->assertInstanceOf(\Redis::class, $client);

                $callback($client);
            },
            transform: false
        );
    }

    /**
     * Add a test Redis connection and return its name.
     */
    private function addTestConnection(array $config): string
    {
        static $counter = 0;
        $name = 'connector_test_' . ++$counter;

        $config = array_merge([
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ], $config);

        $this->app->make('config')->set("database.redis.{$name}", $config);

        return $name;
    }
}
