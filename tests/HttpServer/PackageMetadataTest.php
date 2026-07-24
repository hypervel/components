<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

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
            file_get_contents(__DIR__ . '/../../src/http-server/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-swoole',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coordinator',
            'hypervel/coroutine',
            'hypervel/http',
            'symfony/http-foundation',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('hypervel/engine', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/server', $composer['require']);
    }
}
