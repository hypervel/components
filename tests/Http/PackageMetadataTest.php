<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure HTTP runtime and optional dependencies are declared consistently.
     *
     * @throws JsonException
     */
    public function testRuntimeAndOptionalDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/http/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach (['guzzlehttp/promises', 'guzzlehttp/psr7', 'psr/http-message'] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $this->assertSame('*', $composer['require']['ext-filter']);
        $this->assertSame(
            'Required to use Hypervel\Http\Testing\FileFactory::image().',
            $composer['suggest']['ext-gd']
        );
        $this->assertArrayHasKey('hypervel/image', $composer['suggest']);
        $this->assertIsString($composer['suggest']['hypervel/image']);
        $this->assertNotSame('', trim($composer['suggest']['hypervel/image']));
    }
}
