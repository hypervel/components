<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Sanctum dependencies and discovery metadata are declared consistently.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/sanctum/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            'ext-ctype',
            'ext-filter',
            'ext-json',
            'hypervel/cookie',
            'hypervel/foundation',
            'hypervel/session',
            'symfony/http-foundation',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $providers = [SanctumServiceProvider::class];

        $this->assertSame($providers, $composer['extra']['hypervel']['providers']);
        $this->assertContains(SanctumServiceProvider::class, $rootComposer['extra']['hypervel']['providers']);
    }
}
