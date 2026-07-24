<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Tests\TestCase;
use JsonException;

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
}
