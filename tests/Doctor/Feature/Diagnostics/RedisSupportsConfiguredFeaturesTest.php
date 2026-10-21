<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\RedisSupportsConfiguredFeatures;
use Hypervel\Redis\RedisRequirements;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

class RedisSupportsConfiguredFeaturesTest extends TestCase
{
    /**
     * Keep Redis inactive unless the test selects it.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'file',
            'session.track_user_sessions' => false,
        ]);
    }

    public function testInactiveRedisFeaturesDoNotRequireAConnection(): void
    {
        config(['cache.stores.unused' => ['driver' => 'redis', 'tag_mode' => 'any', 'connection' => 'unavailable']]);
        $this->redisInfo([]);

        $this->assertSame('skip', (new RedisSupportsConfiguredFeatures)->check()->status->value);
    }

    #[RequiresPhpExtension('redis', '>=' . RedisRequirements::MINIMUM_PHPREDIS_VERSION)]
    public function testOrdinaryRedisOnlyRequiresTheBaseClientWithoutServerInfo(): void
    {
        config([
            'cache.default' => 'ordinary',
            'cache.stores.ordinary' => ['driver' => 'redis', 'connection' => 'unavailable', 'lock_connection' => 'locks'],
        ]);
        $this->redisInfo([]);

        $this->assertSame('pass', (new RedisSupportsConfiguredFeatures)->check()->status->value);
    }

    #[RequiresPhpExtension('redis', '>=' . RedisRequirements::HASH_FIELD_EXPIRATION_PHPREDIS_VERSION)]
    #[DataProvider('activeFeatures')]
    public function testOnlyChecksServersForActiveHashFieldFeatures(TagMode|string $mode, bool $trackSessions, array $servers, string $status): void
    {
        config([
            'cache.default' => 'stack',
            'cache.stores.stack' => ['driver' => 'stack', 'stores' => ['array', 'feature-cache']],
            'cache.stores.feature-cache' => ['driver' => 'redis', 'connection' => 'data', 'lock_connection' => 'locks', 'tag_mode' => $mode],
            'session.driver' => 'redis',
            'session.connection' => 'data',
            'session.track_user_sessions' => $trackSessions,
        ]);
        $this->redisInfo($servers);

        $result = (new RedisSupportsConfiguredFeatures)->check();

        $this->assertSame($status, $result->status->value, $result->details ?? $result->summary);

        if ($status === 'fail') {
            $this->assertStringContainsString('data', $result->details);
            $this->assertStringContainsString('Hash field expiration requires', $result->details);
        }
    }

    /**
     * Provide feature selections with supported and unsupported servers.
     */
    public static function activeFeatures(): array
    {
        return [
            'any-tag cache' => ['any', false, ['data' => ['redis_version' => '7.4.0']], 'fail'],
            'enum any-tag cache' => [TagMode::Any, false, ['data' => ['redis_version' => '8.0.0']], 'pass'],
            'session tracking' => ['all', true, ['data' => ['redis_version' => '7.4.0']], 'fail'],
            'shared feature connection on Valkey' => ['any', true, ['data' => ['valkey_version' => '9.0.0', 'redis_version' => '7.2.0']], 'pass'],
            'no hash-field features' => ['all', false, [], 'pass'],
        ];
    }

    #[RequiresPhpExtension('redis', '<' . RedisRequirements::HASH_FIELD_EXPIRATION_PHPREDIS_VERSION)]
    public function testUnsupportedClientFailsBeforeReadingServerInfo(): void
    {
        config([
            'cache.default' => 'features',
            'cache.stores.features' => ['driver' => 'redis', 'tag_mode' => 'any', 'connection' => 'unavailable'],
        ]);
        $this->redisInfo([]);

        $result = (new RedisSupportsConfiguredFeatures)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('phpredis >=', $result->details);
    }

    #[RequiresPhpExtension('redis', '>=' . RedisRequirements::HASH_FIELD_EXPIRATION_PHPREDIS_VERSION)]
    public function testUnknownServerVersionCannotEstablishFeatureSupport(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => null, 'session.track_user_sessions' => true]);
        $this->redisInfo(['session' => []]);

        $result = (new RedisSupportsConfiguredFeatures)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('Could not determine Redis/Valkey version', $result->details);
    }

    /**
     * Provide server information only for connections that may require it.
     */
    private function redisInfo(array $servers): void
    {
        Redis::swap(new class($servers) {
            /**
             * Capture available server metadata.
             */
            public function __construct(private array $servers)
            {
            }

            /**
             * Resolve a simulated server without accepting inactive connections.
             */
            public function connection(string $name): object
            {
                if (! array_key_exists($name, $this->servers)) {
                    throw new RuntimeException('Unexpected server inspection: ' . $name);
                }

                return new class($this->servers[$name]) {
                    /**
                     * Capture one server's INFO response.
                     */
                    public function __construct(private array $response)
                    {
                    }

                    /**
                     * Return the requested server metadata.
                     */
                    public function info(string $section): array
                    {
                        return $this->response;
                    }
                };
            }
        });
    }
}
