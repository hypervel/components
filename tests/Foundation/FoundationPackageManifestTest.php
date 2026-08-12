<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class FoundationPackageManifestTest extends TestCase
{
    private string $basePath;

    private string $manifestPath;

    private Filesystem $filesystem;

    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->basePath = __DIR__ . '/Fixtures';
        $this->tempDirectory = ParallelTesting::tempDir('FoundationPackageManifestTest');
        $this->manifestPath = $this->tempDirectory . '/packages.php';

        $this->filesystem->deleteDirectory($this->tempDirectory);
        $this->filesystem->ensureDirectoryExists($this->tempDirectory);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDirectory);

        PackageManifest::flushState();

        parent::tearDown();
    }

    private function makeManifest(): PackageManifest
    {
        return new PackageManifest($this->filesystem, $this->basePath, $this->manifestPath);
    }

    private function makeTempComposerRoot(string $name): string
    {
        $path = $this->tempDirectory . '/' . $name;

        $this->filesystem->deleteDirectory($path);
        $this->filesystem->ensureDirectoryExists($path . '/vendor/composer');

        return $path;
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

    public function testDiscoverInstalledPackagesMatchesBuildCacheForFixtures(): void
    {
        $filesystem = new Filesystem;
        $manifest = $this->makeManifest();

        $manifest->build();

        $this->assertSame(
            require $this->manifestPath,
            PackageManifest::discoverInstalledPackages(
                $filesystem,
                $this->basePath . '/vendor',
                PackageManifest::packagesToIgnoreFromComposer($filesystem, $this->basePath)
            )
        );
    }

    public function testDiscoverInstalledPackagesHandlesLegacyBareInstalledJsonFormat(): void
    {
        $filesystem = new Filesystem;
        $basePath = $this->makeTempComposerRoot('legacy');
        $filesystem->put($basePath . '/composer.json', '{}');
        $filesystem->put($basePath . '/vendor/composer/installed.json', json_encode([
            [
                'name' => 'vendor-a/package-a',
                'version' => 'v1.0.0',
                'extra' => [
                    'hypervel' => [
                        'providers' => ['Vendor\Package\Provider'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            [
                'vendor-a/package-a' => [
                    'providers' => ['Vendor\Package\Provider'],
                    'version' => 'v1.0.0',
                ],
            ],
            PackageManifest::discoverInstalledPackages($filesystem, $basePath . '/vendor', [])
        );
    }

    public function testDiscoverInstalledPackagesKeepsVersionOnlyPackagesUnlessIgnored(): void
    {
        $packages = PackageManifest::discoverInstalledPackages(
            new Filesystem,
            $this->basePath . '/vendor',
            []
        );

        $this->assertSame(['version' => 'v3.1.4'], $packages['vendor-a/package-d']);
    }

    public function testDiscoverInstalledPackagesHonorsWildcardDontDiscover(): void
    {
        $this->assertSame(
            [],
            PackageManifest::discoverInstalledPackages(new Filesystem, $this->basePath . '/vendor', ['*'])
        );
    }

    public function testDiscoverInstalledPackagesReturnsEmptyArrayForMissingInstalledJson(): void
    {
        $basePath = $this->makeTempComposerRoot('missing-installed-json');

        (new Filesystem)->delete($basePath . '/vendor/composer/installed.json');

        $this->assertSame(
            [],
            PackageManifest::discoverInstalledPackages(new Filesystem, $basePath . '/vendor', [])
        );
    }

    public function testDiscoverInstalledPackagesFailsForMalformedInstalledJson(): void
    {
        $basePath = $this->makeTempComposerRoot('malformed-installed-json');
        $this->filesystem->put($basePath . '/vendor/composer/installed.json', '{');

        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Syntax error');

        PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', []);
    }

    public function testRootWildcardSkipsMalformedInstalledJsonBeforeParsing(): void
    {
        $basePath = $this->makeTempComposerRoot('wildcard-malformed-installed-json');
        $this->filesystem->put($basePath . '/composer.json', json_encode([
            'extra' => [
                'hypervel' => [
                    'dont-discover' => ['*'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->filesystem->put($basePath . '/vendor/composer/installed.json', '{');

        $ignore = PackageManifest::packagesToIgnoreFromComposer($this->filesystem, $basePath);

        $this->assertSame(
            [],
            PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', $ignore)
        );
    }

    public function testDiscoverInstalledPackagesFailsForNonArrayRoot(): void
    {
        $basePath = $this->makeTempComposerRoot('non-array-installed-root');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, 'null');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Composer metadata [{$path}] must contain an array.");

        PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', []);
    }

    public function testDiscoverInstalledPackagesFailsForNonArrayPackagesMember(): void
    {
        $basePath = $this->makeTempComposerRoot('malformed-packages-shape');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => 'invalid',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Composer metadata [{$path}] member [packages] must contain an array.");

        PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', []);
    }

    public function testDiscoverInstalledPackagesFailsForNonArrayPackageEntry(): void
    {
        $basePath = $this->makeTempComposerRoot('non-array-package-entry');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => ['invalid'],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Composer metadata package [0] in [{$path}] must contain an array.");

        PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', []);
    }

    public function testDiscoverInstalledPackagesFailsForNamelessPackageEntry(): void
    {
        $basePath = $this->makeTempComposerRoot('nameless-package-entry');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => [['version' => 'v1.0.0']],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "Composer metadata package [0] in [{$path}] member [name] must be a non-empty string."
        );

        PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', []);
    }

    public function testDiscoverInstalledPackagesFailsForEmptyFormattedPackageName(): void
    {
        $basePath = $this->makeTempComposerRoot('empty-formatted-package-name');
        $vendorPath = $basePath . '/vendor';
        $path = $vendorPath . '/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => [['name' => $vendorPath . '/']],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "Composer metadata package [0] in [{$path}] has an empty formatted package name."
        );

        PackageManifest::discoverInstalledPackages($this->filesystem, $vendorPath, []);
    }

    public function testDiscoverInstalledPackagesFailsForInvalidVersion(): void
    {
        $basePath = $this->makeTempComposerRoot('invalid-version');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => [[
                'name' => 'vendor/package',
                'version' => [],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "Composer metadata package [vendor/package] in [{$path}] member [version] must be a string or null."
        );

        PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', []);
    }

    public function testDiscoverInstalledPackagesFailsForInvalidHypervelExtra(): void
    {
        $basePath = $this->makeTempComposerRoot('invalid-package-hypervel-extra');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => [[
                'name' => 'vendor/package',
                'extra' => ['hypervel' => 'invalid'],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "Composer metadata package [0] in [{$path}] member [extra.hypervel] must contain an array."
        );

        PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', []);
    }

    public function testSpecificallyIgnoredPackagesSkipInvalidConsumedMetadata(): void
    {
        $basePath = $this->makeTempComposerRoot('ignored-invalid-metadata');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/invalid-version',
                    'version' => [],
                ],
                [
                    'name' => 'vendor/invalid-extra',
                    'extra' => ['hypervel' => 'invalid'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            [],
            PackageManifest::discoverInstalledPackages(
                $this->filesystem,
                $basePath . '/vendor',
                ['vendor/invalid-version', 'vendor/invalid-extra']
            )
        );
    }

    public function testDiscoverInstalledPackagesToleratesNonArrayParentExtra(): void
    {
        $basePath = $this->makeTempComposerRoot('non-array-parent-extra');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => [[
                'name' => 'vendor/package',
                'extra' => 'invalid',
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            ['vendor/package' => ['version' => null]],
            PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', [])
        );
    }

    public function testDiscoverInstalledPackagesPreservesConsumerOwnedHypervelValues(): void
    {
        $basePath = $this->makeTempComposerRoot('consumer-owned-hypervel-values');
        $path = $basePath . '/vendor/composer/installed.json';
        $this->filesystem->put($path, json_encode([
            'packages' => [[
                'name' => 'vendor/package',
                'version' => 'v1.2.3',
                'extra' => [
                    'hypervel' => [
                        'providers' => 'Vendor\Package\Provider',
                        'aliases' => ['Package' => 'Vendor\Package\Facade'],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            [
                'vendor/package' => [
                    'providers' => 'Vendor\Package\Provider',
                    'aliases' => ['Package' => 'Vendor\Package\Facade'],
                    'version' => 'v1.2.3',
                ],
            ],
            PackageManifest::discoverInstalledPackages($this->filesystem, $basePath . '/vendor', [])
        );
    }

    public function testRootHypervelExtraReturnsNullForMissingComposerJson(): void
    {
        $basePath = $this->makeTempComposerRoot('missing-root-composer-json');

        $this->assertNull(PackageManifest::rootHypervelExtra($this->filesystem, $basePath, 'test-state'));
        $this->assertSame([], PackageManifest::packagesToIgnoreFromComposer($this->filesystem, $basePath));
    }

    public function testRootHypervelExtraFailsForMalformedComposerJson(): void
    {
        $basePath = $this->makeTempComposerRoot('malformed-composer-json');
        $this->filesystem->put($basePath . '/composer.json', '{');

        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Syntax error');

        PackageManifest::rootHypervelExtra($this->filesystem, $basePath, 'test-state');
    }

    public function testRootHypervelExtraFailsForNonArrayRoot(): void
    {
        $basePath = $this->makeTempComposerRoot('non-array-root-composer');
        $path = $basePath . '/composer.json';
        $this->filesystem->put($path, 'null');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Composer metadata [{$path}] must contain an array.");

        PackageManifest::rootHypervelExtra($this->filesystem, $basePath, 'test-state');
    }

    public function testRootHypervelExtraFailsForInvalidExplicitHypervelMetadata(): void
    {
        $basePath = $this->makeTempComposerRoot('invalid-root-hypervel-metadata');
        $path = $basePath . '/composer.json';
        $this->filesystem->put($path, json_encode([
            'extra' => ['hypervel' => 'invalid'],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "Composer metadata root package in [{$path}] member [extra.hypervel] must contain an array."
        );

        PackageManifest::rootHypervelExtra($this->filesystem, $basePath, 'test-state');
    }

    public function testRootHypervelExtraToleratesNonArrayParentExtra(): void
    {
        $basePath = $this->makeTempComposerRoot('non-array-root-extra');
        $this->filesystem->put($basePath . '/composer.json', json_encode([
            'extra' => 'invalid',
        ], JSON_THROW_ON_ERROR));

        $this->assertNull(PackageManifest::rootHypervelExtra($this->filesystem, $basePath, 'test-state'));
    }

    public function testRootHypervelExtraPreservesConsumerOwnedValues(): void
    {
        $basePath = $this->makeTempComposerRoot('consumer-owned-root-extra');
        $this->filesystem->put($basePath . '/composer.json', json_encode([
            'extra' => [
                'hypervel' => [
                    'test-state' => 'Vendor\Package\Registrar',
                    'dont-discover' => ['vendor/package'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            'Vendor\Package\Registrar',
            PackageManifest::rootHypervelExtra($this->filesystem, $basePath, 'test-state')
        );
        $this->assertSame(
            ['vendor/package'],
            PackageManifest::packagesToIgnoreFromComposer($this->filesystem, $basePath)
        );
    }

    public function testBuildFailurePreservesExistingManifest(): void
    {
        $basePath = $this->makeTempComposerRoot('build-failure');
        $manifestPath = $basePath . '/packages.php';
        $existingManifest = "<?php return ['existing/package' => ['version' => 'v1.0.0']];";
        $this->filesystem->put($basePath . '/composer.json', '{}');
        $this->filesystem->put($basePath . '/vendor/composer/installed.json', '{');
        $this->filesystem->put($manifestPath, $existingManifest);
        $manifest = new PackageManifest($this->filesystem, $basePath, $manifestPath);

        try {
            $manifest->build();
            $this->fail('Expected malformed installed metadata to fail the manifest build.');
        } catch (JsonException $exception) {
            $this->assertSame('Syntax error', $exception->getMessage());
        }

        $this->assertSame($existingManifest, $this->filesystem->get($manifestPath));
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

    public function testManifestIsCachedAfterFirstRead(): void
    {
        $basePath = $this->makeTempComposerRoot('cached-manifest');
        $this->filesystem->copy(
            $this->basePath . '/vendor/composer/installed.json',
            $basePath . '/vendor/composer/installed.json'
        );
        $manifest = new PackageManifest($this->filesystem, $basePath, $this->manifestPath);

        $providers = $manifest->providers();

        $this->filesystem->delete($basePath . '/vendor/composer/installed.json');
        $this->filesystem->delete($this->manifestPath);

        $this->assertSame($providers, $manifest->providers());
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
