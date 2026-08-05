<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\TestCase;
use JsonException;
use ReflectionClass;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure direct runtime dependencies are installed with the split package.
     *
     * @throws JsonException
     */
    public function testDirectRuntimeDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/redis/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-swoole',
            'psr/log',
            'hypervel/collections',
            'hypervel/config',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coordinator',
            'hypervel/core',
            'hypervel/coroutine',
            'hypervel/engine',
            'hypervel/macroable',
            'hypervel/pool',
            'hypervel/support',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('ext-redis', $composer['require']);
        $this->assertArrayHasKey('ext-redis', $composer['suggest']);
    }

    public function testConnectionBoundMethodsAreExcludedFromFacadeDocumentation(): void
    {
        $proxy = new ReflectionClass(RedisProxy::class);
        $methods = $proxy->getReflectionConstant('CONNECTION_BOUND_METHODS')?->getValue();
        $this->assertIsArray($methods);

        $facade = new ReflectionClass(Redis::class);
        $ignoredMethod = $facade->getMethod('ignoredFacadeDocumenterMethods');
        $ignored = array_map('strtolower', $ignoredMethod->invoke(null));

        foreach ($methods as $method) {
            $this->assertContains($method, $ignored);
        }
    }

    public function testCaseInsensitiveCommandAliasesHaveIdenticalSignatures(): void
    {
        $docblock = (new ReflectionClass(RedisConnection::class))->getDocComment();
        $this->assertIsString($docblock);
        preg_match_all(
            '/@method\s+(\S+)\s+([A-Za-z_][A-Za-z0-9_]*)\(([^)]*)\)/',
            $docblock,
            $matches,
            PREG_SET_ORDER,
        );

        $methods = [];

        foreach ($matches as $match) {
            $methods[strtolower($match[2])][] = "{$match[1]}({$match[3]})";
        }

        foreach ([
            'setnx',
            'hmget',
            'hmset',
            'hsetnx',
            'hget',
            'hset',
            'llen',
            'blpop',
            'brpop',
            'spop',
            'srem',
            'zadd',
            'zcard',
            'zcount',
            'zrangebyscore',
            'zrevrangebyscore',
            'flushdb',
            'smembers',
            'hdel',
            'zrem',
            'hlen',
            'hkeys',
        ] as $method) {
            $this->assertCount(2, $methods[$method] ?? [], "Expected two case variants for [{$method}].");
            $this->assertCount(
                1,
                array_unique($methods[$method]),
                "Expected both case variants of [{$method}] to have the same signature.",
            );
        }
    }

    public function testFacadeDocumentsManagerAndMacroSurfaces(): void
    {
        $docblock = (new ReflectionClass(Redis::class))->getDocComment();
        $this->assertIsString($docblock);

        foreach ([
            'enableEvents',
            'disableEvents',
            'macro',
            'mixin',
            'hasMacro',
            'flushMacros',
            'hasHashTag',
        ] as $method) {
            $this->assertStringContainsString(" {$method}(", $docblock);
        }

        $this->assertStringContainsString(' bool|\Redis discard()', $docblock);
        $this->assertStringNotContainsString(' macroCall(', $docblock);
    }

    public function testUnsupportedNativeMethodsAreNotAdvertised(): void
    {
        // REMOVED: pooled RESET destroys auth/database state owned by the pool.
        // REMOVED: sharded subscriptions require slot-routed dedicated connections.
        $facade = new ReflectionClass(Redis::class);
        $docblock = $facade->getDocComment();
        $this->assertIsString($docblock);
        $ignoredMethod = $facade->getMethod('ignoredFacadeDocumenterMethods');
        $ignored = array_map('strtolower', $ignoredMethod->invoke(null));

        foreach (['reset', 'ssubscribe'] as $method) {
            $this->assertStringNotContainsString(" {$method}(", strtolower($docblock));
            $this->assertContains($method, $ignored);
        }
    }
}
