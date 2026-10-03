<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Inertia dependencies match the classes it imports directly.
     *
     * @throws JsonException
     */
    public function testRuntimeDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/inertia/composer.json'),
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

        $this->assertArrayHasKey('guzzlehttp/promises', $rootComposer['require']);
        $this->assertArrayHasKey('guzzlehttp/promises', $composer['require']);
        $this->assertSame($rootComposer['require']['guzzlehttp/promises'], $composer['require']['guzzlehttp/promises']);
    }
}
