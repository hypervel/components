<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

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
            file_get_contents(__DIR__ . '/../../src/watcher/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-pcntl',
            'ext-posix',
            'ext-swoole',
            'hypervel/console',
            'hypervel/contracts',
            'hypervel/coroutine',
            'hypervel/engine',
            'hypervel/filesystem',
            'hypervel/foundation',
            'hypervel/support',
            'symfony/console',
            'symfony/finder',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('hypervel/coordinator', $composer['require']);
    }
}
