<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

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
        ] as $method) {
            $this->assertStringContainsString(" {$method}(", $docblock);
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
