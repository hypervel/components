<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Concurrency dependencies match the root package.
     *
     * @throws JsonException
     */
    public function testDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/concurrency/composer.json'),
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

        $this->assertArrayHasKey('nesbot/carbon', $rootComposer['require']);
        $this->assertArrayHasKey('nesbot/carbon', $composer['require']);
        $this->assertSame($rootComposer['require']['nesbot/carbon'], $composer['require']['nesbot/carbon']);
    }
}
