<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Support and Collections declare their direct runtime dependencies.
     *
     * @throws JsonException
     */
    public function testRuntimeDependenciesAreDeclared(): void
    {
        $supportComposer = json_decode(
            file_get_contents(__DIR__ . '/../../src/support/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $collectionsComposer = json_decode(
            file_get_contents(__DIR__ . '/../../src/collections/composer.json'),
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

        foreach (['guzzlehttp/promises', 'league/commonmark'] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $supportComposer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $supportComposer['require'][$dependency]);
        }

        foreach (['symfony/polyfill-php85', 'symfony/polyfill-php86'] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $collectionsComposer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $collectionsComposer['require'][$dependency]);
        }
    }
}
