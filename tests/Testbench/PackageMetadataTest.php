<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Composer\Semver\VersionParser;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Tests\TestCase;
use JsonException;

use function Hypervel\Testbench\package_version_compare;

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
            file_get_contents(__DIR__ . '/../../src/testbench/composer.json'),
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

        $this->assertArrayHasKey('composer/semver', $rootComposer['require-dev']);
        $this->assertArrayHasKey('composer/semver', $composer['require']);
        $this->assertSame($rootComposer['require-dev']['composer/semver'], $composer['require']['composer/semver']);
        $this->assertArrayHasKey('hypervel/di', $composer['require']);
        $this->assertSame('^0.4', $composer['require']['hypervel/di']);
        $this->assertArrayHasKey('hypervel/di', $rootComposer['replace']);
        $this->assertSame('self.version', $rootComposer['replace']['hypervel/di']);
        $this->assertArrayHasKey('hypervel/concurrency', $composer['require']);
        $this->assertSame('^0.4', $composer['require']['hypervel/concurrency']);
        $this->assertArrayHasKey('hypervel/concurrency', $rootComposer['replace']);
        $this->assertSame('self.version', $rootComposer['replace']['hypervel/concurrency']);
        $this->assertArrayNotHasKey('brianium/paratest', $composer['suggest'] ?? []);
    }

    /**
     * Ensure split-package bootstrap dependencies support their owning paths.
     */
    public function testBootstrapDependenciesAreAvailable(): void
    {
        $this->assertTrue(class_exists(VersionParser::class));
        $this->assertTrue(class_exists(GenerateProxies::class));
        $this->assertTrue(package_version_compare('composer/semver', '3.4', '>='));
    }
}
