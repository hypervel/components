<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class FoundationPackageManifestTest extends TestCase
{
    private string $basePath;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = __DIR__ . '/Fixtures';
        $this->manifestPath = sys_get_temp_dir() . '/hypervel_test_packages_' . getmypid() . '.php';

        @unlink($this->manifestPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);

        PackageManifest::flushState();

        parent::tearDown();
    }

    private function makeManifest(): PackageManifest
    {
        return new PackageManifest(new Filesystem(), $this->basePath, $this->manifestPath);
    }

    public function testProvidersReturnsDiscoveredProviders()
    {
        $manifest = $this->makeManifest();

        // package-a has TestOneServiceProvider
        // package-b has TestTwoServiceProvider + TestThreeServiceProvider
        // package-c is dont-discovered by package-a
        // package-d has no hypervel extra
        $providers = $manifest->providers();

        $this->assertContains('Hypervel\Tests\Foundation\Bootstrap\TestOneServiceProvider', $providers);
        $this->assertContains('Hypervel\Tests\Foundation\Bootstrap\TestTwoServiceProvider', $providers);
        $this->assertContains('Hypervel\Tests\Foundation\Bootstrap\TestThreeServiceProvider', $providers);
        $this->assertNotContains('Hypervel\Tests\Foundation\Bootstrap\TestFourServiceProvider', $providers);
    }

    public function testAliasesReturnsDiscoveredAliases()
    {
        $manifest = $this->makeManifest();

        $aliases = $manifest->aliases();

        $this->assertSame(['TestAlias' => 'TestClass'], $aliases);
    }

    public function testBuildWritesCacheFile()
    {
        $manifest = $this->makeManifest();

        $manifest->build();

        $this->assertFileExists($this->manifestPath);

        $cached = require $this->manifestPath;
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('vendor-a/package-a', $cached);
        $this->assertArrayHasKey('vendor-a/package-b', $cached);
    }

    public function testBuildCachesVersions()
    {
        $manifest = $this->makeManifest();

        $manifest->build();

        $cached = require $this->manifestPath;

        $this->assertSame('v1.0.1', $cached['vendor-a/package-a']['version']);
        $this->assertSame('v2.3.0', $cached['vendor-a/package-b']['version']);
    }

    public function testVersionReturnsPackageVersion()
    {
        $manifest = $this->makeManifest();

        $this->assertSame('v1.0.1', $manifest->version('vendor-a/package-a'));
        $this->assertSame('v2.3.0', $manifest->version('vendor-a/package-b'));
    }

    public function testVersionReturnsNullForUnknownPackage()
    {
        $manifest = $this->makeManifest();

        $this->assertNull($manifest->version('vendor-a/nonexistent'));
    }

    public function testHasPackageReturnsTrueForInstalledPackage()
    {
        $manifest = $this->makeManifest();

        $this->assertTrue($manifest->hasPackage('vendor-a/package-a'));
        $this->assertTrue($manifest->hasPackage('vendor-a/package-b'));
    }

    public function testHasPackageReturnsFalseForUnknownPackage()
    {
        $manifest = $this->makeManifest();

        $this->assertFalse($manifest->hasPackage('vendor-a/nonexistent'));
    }

    public function testHasPackageReturnsFalseForDontDiscoverPackage()
    {
        $manifest = $this->makeManifest();

        // package-c is dont-discovered by package-a
        $this->assertFalse($manifest->hasPackage('vendor-a/package-c'));
    }

    public function testDontDiscoverFromProjectComposerJson()
    {
        $manifest = $this->makeManifest();

        // package-d is dont-discovered by the project composer.json
        $this->assertFalse($manifest->hasPackage('vendor-a/package-d'));
    }

    public function testIgnorePackageDiscoveriesFromStaticMethod()
    {
        PackageManifest::ignorePackageDiscoveriesFrom(['*']);

        $manifest = $this->makeManifest();

        $this->assertEmpty($manifest->providers());
        $this->assertEmpty($manifest->aliases());
    }

    public function testIgnoreSpecificPackage()
    {
        PackageManifest::ignorePackageDiscoveriesFrom(['vendor-a/package-a']);

        $manifest = $this->makeManifest();

        $providers = $manifest->providers();

        $this->assertNotContains('Hypervel\Tests\Foundation\Bootstrap\TestOneServiceProvider', $providers);
        $this->assertContains('Hypervel\Tests\Foundation\Bootstrap\TestTwoServiceProvider', $providers);
    }

    public function testManifestIsCachedAfterFirstRead()
    {
        $manifest = $this->makeManifest();

        // First call builds and caches
        $providers1 = $manifest->providers();

        // Delete the installed.json — should still work from cache
        $manifest->build();

        $providers2 = $manifest->providers();

        $this->assertSame($providers1, $providers2);
    }

    public function testBuildDoesNotApplyRuntimeIgnoresToDiskCache()
    {
        // Set runtime ignore to '*' — should NOT affect what's written to disk
        PackageManifest::ignorePackageDiscoveriesFrom(['*']);

        $manifest = $this->makeManifest();
        $manifest->build();

        // The file on disk should contain all packages (minus project/inter-package dont-discover)
        $cached = require $this->manifestPath;

        $this->assertArrayHasKey('vendor-a/package-a', $cached);
        $this->assertArrayHasKey('vendor-a/package-b', $cached);

        // But getManifest() should still filter at read time
        $this->assertEmpty($manifest->providers());
    }

    public function testFlushStateResetsIgnoreList()
    {
        PackageManifest::ignorePackageDiscoveriesFrom(['*']);

        PackageManifest::flushState();

        $manifest = $this->makeManifest();

        $this->assertNotEmpty($manifest->providers());
    }

    public function testSatisfiesThrowsWithoutComposerSemver()
    {
        if (class_exists(\Composer\Semver\VersionParser::class)) {
            $this->markTestSkipped('composer/semver is installed — cannot test missing dependency path.');
        }

        $manifest = $this->makeManifest();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer/semver');

        $manifest->satisfies('vendor-a/package-a', '^1.0');
    }

    public function testSatisfiesReturnsTrueForMatchingConstraint()
    {
        $manifest = $this->makeManifest();

        // vendor-a/package-a is v1.0.1
        $this->assertTrue($manifest->satisfies('vendor-a/package-a', '^1.0'));
        $this->assertTrue($manifest->satisfies('vendor-a/package-a', '>=1.0'));
        $this->assertTrue($manifest->satisfies('vendor-a/package-a', '~1.0'));
    }

    public function testSatisfiesReturnsFalseForNonMatchingConstraint()
    {
        $manifest = $this->makeManifest();

        // vendor-a/package-a is v1.0.1
        $this->assertFalse($manifest->satisfies('vendor-a/package-a', '^2.0'));
        $this->assertFalse($manifest->satisfies('vendor-a/package-a', '<1.0'));
    }

    public function testSatisfiesReturnsFalseForUnknownPackage()
    {
        $manifest = $this->makeManifest();

        $this->assertFalse($manifest->satisfies('vendor-a/nonexistent', '^1.0'));
    }
}
