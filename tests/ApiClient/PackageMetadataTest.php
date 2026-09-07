<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure API Client dependencies match the classes it imports directly.
     *
     * @throws JsonException
     */
    public function testRuntimeDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/api-client/composer.json'),
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

        foreach (['guzzlehttp/guzzle', 'guzzlehttp/psr7', 'psr/http-message'] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $this->assertArrayNotHasKey('hypervel/engine', $composer['require']);
    }
}
