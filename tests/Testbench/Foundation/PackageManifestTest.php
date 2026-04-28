<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest as FoundationPackageManifest;
use Hypervel\Testbench\Foundation\PackageManifest;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class PackageManifestTest extends TestCase
{
    private string $basePath;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = __DIR__ . '/Fixtures/PackageManifest';
        $this->manifestPath = sys_get_temp_dir() . '/hypervel_testbench_packages_' . getmypid() . '.php';

        @unlink($this->manifestPath);
    }

    #[Override]
    protected function tearDown(): void
    {
        @unlink($this->manifestPath);

        FoundationPackageManifest::flushState();

        parent::tearDown();
    }

    /**
     * Build a fixture-backed package manifest.
     */
    private function makeManifest(?object $testbench = null, ?array $rootPackage = null): PackageManifest
    {
        return new class(new Filesystem, $this->basePath, $this->manifestPath, $testbench, $rootPackage) extends PackageManifest {
            /**
             * Create a new fixture-backed package manifest instance.
             */
            public function __construct(
                Filesystem $files,
                string $basePath,
                string $manifestPath,
                ?object $testbench,
                private readonly ?array $rootPackage
            ) {
                parent::__construct($files, $basePath, $manifestPath, $testbench);
            }

            /**
             * Get the root package composer metadata.
             *
             * @return null|array{name: string, extra?: array{hypervel?: array<string, mixed>}}
             */
            #[Override]
            protected function providersFromTestbench(): ?array
            {
                return $this->rootPackage;
            }
        };
    }

    /**
     * Create a testbench stub with the given ignore list.
     */
    private function makeTestbench(array $ignore): object
    {
        return new class($ignore) {
            /**
             * Create a new testbench stub.
             *
             * @param array<int, string> $ignore
             */
            public function __construct(
                private readonly array $ignore
            ) {
            }

            /**
             * Ignore package discovery from.
             *
             * @return array<int, string>
             */
            public function ignorePackageDiscoveriesFrom(): array
            {
                return $this->ignore;
            }
        };
    }

    /**
     * Get the root package fixture metadata.
     *
     * @return array{name: string, extra?: array{hypervel?: array<string, mixed>}}
     */
    private function rootPackageFixture(): array
    {
        /** @var array{name: string, extra?: array{hypervel?: array<string, mixed>}} $composer */
        return json_decode((string) file_get_contents($this->basePath . '/composer.json'), true);
    }

    #[Test]
    public function itCanBuildManifestFromFixtures(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: $this->rootPackageFixture()
        );

        $manifest->build();

        $cached = require $this->manifestPath;

        $this->assertArrayHasKey('testbench/example', $cached);
        $this->assertArrayHasKey('vendor-a/package-a', $cached);
        $this->assertArrayHasKey('vendor-a/package-b', $cached);
        $this->assertArrayHasKey('vendor-a/package-c', $cached);
        $this->assertArrayHasKey('vendor-a/package-d', $cached);
    }

    #[Test]
    public function itCanBuildManifestWithoutRootComposerMetadata(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: null
        );

        $manifest->build();

        $cached = require $this->manifestPath;

        $this->assertArrayNotHasKey('testbench/example', $cached);
        $this->assertArrayHasKey('vendor-a/package-a', $cached);
        $this->assertArrayHasKey('vendor-a/package-b', $cached);
    }

    #[Test]
    public function itCanFilterManifestUsingTheTestbenchIgnoreList(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench(['*']),
            rootPackage: $this->rootPackageFixture()
        );

        $this->assertSame([], $manifest->providers());
        $this->assertSame([], $manifest->aliases());
    }

    #[Test]
    public function itCanRetainRequiredPackagesWhenDiscoveryIsDisabled(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench(['*']),
            rootPackage: $this->rootPackageFixture()
        )->requires('testbench/example', 'vendor-a/package-d');

        $this->assertTrue($manifest->hasPackage('testbench/example'));
        $this->assertTrue($manifest->hasPackage('vendor-a/package-d'));
        $this->assertFalse($manifest->hasPackage('vendor-a/package-a'));
        $this->assertContains(
            'Hypervel\Tests\Testbench\Fixtures\Providers\ParentServiceProvider',
            $manifest->providers()
        );
    }

    #[Test]
    public function itFiltersPackagesWithMissingProviders(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: $this->rootPackageFixture()
        );

        $this->assertFalse($manifest->hasPackage('vendor-a/package-c'));
        $this->assertSame(
            [
                'PackageAlias' => 'PackageClass',
                'RootAlias' => 'RootClass',
            ],
            $manifest->aliases()
        );
    }

    #[Test]
    public function itSwapsTheApplicationBindingToTheTestbenchPackageManifest(): void
    {
        $manifest = $this->app->make(FoundationPackageManifest::class);

        $this->assertInstanceOf(PackageManifest::class, $manifest);
        $this->assertSame($manifest, $this->app->make(PackageManifest::class));
    }
}
