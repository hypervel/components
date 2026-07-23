<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

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
            file_get_contents(__DIR__ . '/../../src/websocket-server/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-swoole',
            'hypervel/collections',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coordinator',
            'hypervel/core',
            'hypervel/coroutine',
            'hypervel/engine',
            'hypervel/http',
            'hypervel/http-server',
            'hypervel/routing',
            'hypervel/server',
            'hypervel/support',
            'symfony/http-foundation',
            'symfony/http-kernel',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('psr/log', $composer['require']);
    }
}
