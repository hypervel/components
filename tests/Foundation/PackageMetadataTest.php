<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure direct framework dependencies are installed with the split package.
     *
     * @throws JsonException
     */
    public function testDirectFrameworkDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/foundation/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'hypervel/cache',
            'hypervel/concurrency',
            'hypervel/encryption',
            'hypervel/events',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame('^0.4', $composer['require'][$dependency]);
        }
    }
}
