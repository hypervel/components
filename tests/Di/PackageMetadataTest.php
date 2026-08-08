<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure DI dependencies match the root package.
     *
     * @throws JsonException
     */
    public function testDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/di/composer.json'),
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

        $this->assertSame(
            $rootComposer['require']['composer-runtime-api'],
            $composer['require']['composer-runtime-api'],
        );
    }
}
