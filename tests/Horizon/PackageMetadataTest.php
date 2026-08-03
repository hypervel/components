<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

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
            file_get_contents(__DIR__ . '/../../src/horizon/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-json',
            'ext-mbstring',
            'ext-pcntl',
            'ext-posix',
            'ext-redis',
            'hypervel/foundation',
            'hypervel/routing',
            'symfony/console',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'symfony/process',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('nesbot/carbon', $composer['require']);
    }
}
