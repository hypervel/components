<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Hypervel\Wayfinder\WayfinderServiceProvider;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Wayfinder runtime dependencies and discovery metadata are complete.
     *
     * @throws JsonException
     */
    public function testRuntimeDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/wayfinder/composer.json'),
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

        foreach (['ext-tokenizer', 'phpstan/phpdoc-parser'] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $internalConstraint = '^' . Str::before(
            $composer['extra']['branch-alias']['dev-main'],
            '-dev',
        );

        $this->assertSame($internalConstraint, $composer['require']['hypervel/reflection'] ?? null);
        $this->assertArrayNotHasKey('phpstan/phpdoc-parser', $rootComposer['require-dev']);
        $this->assertSame(
            [WayfinderServiceProvider::class],
            $composer['extra']['hypervel']['providers'],
        );
        $this->assertContains(
            WayfinderServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers'],
        );
    }
}
