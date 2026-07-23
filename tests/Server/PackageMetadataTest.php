<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

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
            file_get_contents(__DIR__ . '/../../src/server/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-posix',
            'ext-swoole',
            'psr/log',
            'symfony/console',
            'hypervel/console',
            'hypervel/contracts',
            'hypervel/core',
            'hypervel/engine',
            'hypervel/events',
            'hypervel/filesystem',
            'hypervel/server-process',
            'hypervel/support',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('hypervel/http-server', $composer['require']);
    }
}
