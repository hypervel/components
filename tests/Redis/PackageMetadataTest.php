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
            'ext-filter',
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
            'hypervel/connection-pool',
            'hypervel/support',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('ext-redis', $composer['require']);
        $this->assertArrayHasKey('ext-redis', $composer['suggest']);
    }

    /**
     * Ensure native Redis command return values are documented.
     */
    public function testNativeRedisCommandReturnValuesAreDocumented(): void
    {
        $connectionDocblock = (new ReflectionClass(RedisConnection::class))->getDocComment();
        $this->assertIsString($connectionDocblock);
        $this->assertStringContainsString('@method array|false|Redis|RedisCluster keys(string $pattern)', $connectionDocblock);
        $this->assertStringContainsString(
            '@method false|int|Redis|RedisCluster lInsert(string $key, string $pos, mixed $pivot, mixed $value)',
            $connectionDocblock,
        );

        $facadeDocblock = (new ReflectionClass(Redis::class))->getDocComment();
        $this->assertIsString($facadeDocblock);
        $this->assertStringContainsString('@method static array|false|\Redis|\RedisCluster keys(string $pattern)', $facadeDocblock);
        $this->assertStringContainsString(
            '@method static false|int|\Redis|\RedisCluster lInsert(string $key, string $pos, mixed $pivot, mixed $value)',
            $facadeDocblock,
        );
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

    public function testCommandsAreDocumentedOncePerCaseInsensitiveName(): void
    {
        $docblock = (new ReflectionClass(RedisConnection::class))->getDocComment();
        $this->assertIsString($docblock);
        preg_match_all('/@method\s+\S+\s+([A-Za-z_][A-Za-z0-9_]*)\(/', $docblock, $matches);

        // Commands are case-insensitive, and the static analysis matches every casing to the one tag.
        $counts = array_count_values(array_map(strtolower(...), $matches[1]));

        $this->assertSame([], array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
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

    /**
     * Ensure pooled connections do not advertise guarded commands.
     */
    public function testPooledConnectionsDoNotAdvertiseGuardedCommands(): void
    {
        $connection = new ReflectionClass(RedisConnection::class);
        $docblock = $connection->getDocComment();
        $this->assertIsString($docblock);
        $docblock = strtolower($docblock);

        foreach (['reset', 'subscribe', 'psubscribe', 'ssubscribe'] as $command) {
            $this->assertStringNotContainsString(" {$command}(", $docblock);
        }
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
