<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class RedisConfigTest extends TestCase
{
    public function testConnectionNamesExcludesMetadataKeys(): void
    {
        $redisConfig = [
            'client' => 'phpredis',
            'options' => ['prefix' => 'global:'],
            'clusters' => ['cache' => []],
            'default' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 0],
            'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 1],
        ];

        $this->assertSame(['default', 'cache'], RedisConfig::connectionNames($redisConfig));
    }

    public function testConnectionNamesThrowsForNonArrayConnectionEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] must be an array.');

        RedisConfig::connectionNames([
            'default' => 'tcp://127.0.0.1:6379',
        ]);
    }

    public function testConnectionNamesThrowsForUnknownArrayShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [custom] does not have a valid connection shape.');

        RedisConfig::connectionNames([
            'custom' => ['foo' => 'bar'],
        ]);
    }

    public function testConnectionConfigMergesSharedAndConnectionOptions(): void
    {
        $redisConfig = [
            'options' => ['prefix' => 'global:', 'serializer' => 1],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'db' => 0,
                'options' => ['prefix' => 'default:'],
            ],
        ];

        $connectionConfig = RedisConfig::connectionConfig($redisConfig, 'default');

        $this->assertSame(
            ['prefix' => 'default:', 'serializer' => 1],
            $connectionConfig['options'],
        );
    }

    public function testConnectionConfigThrowsForMissingConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] must be an array.');

        RedisConfig::connectionConfig([], 'default');
    }

    public function testConnectionConfigThrowsForInvalidConnectionOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] options must be an array.');

        RedisConfig::connectionConfig([
            'options' => ['prefix' => 'global:'],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'db' => 0,
                'options' => 'invalid',
            ],
        ], 'default');
    }
}
