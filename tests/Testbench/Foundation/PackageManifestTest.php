<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest as FoundationPackageManifest;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Foundation\PackageManifest;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use UnexpectedValueException;

class PackageManifestTest extends TestCase
{
    private string $basePath;

    private string $manifestPath;

    private Filesystem $filesystem;

    private string $tempDirectory;

    /**
     * @var array{0: null|string, 1: null|string, 2: false|string}
     */
    private array $originalPackageTester = [null, null, false];

    protected function setUp(): void
    {
        parent::setUp();

        // The fixture manifests decide root discovery from their Testbench stub, not
        // from a package:test worker's inherited context.
        $this->originalPackageTester = [
            $_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? null,
            $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? null,
            getenv('TESTBENCH_PACKAGE_TESTER'),
        ];
        unset($_SERVER['TESTBENCH_PACKAGE_TESTER'], $_ENV['TESTBENCH_PACKAGE_TESTER']);
        putenv('TESTBENCH_PACKAGE_TESTER');

        $this->filesystem = new Filesystem;
        $this->basePath = __DIR__ . '/Fixtures/PackageManifest';
        $this->tempDirectory = ParallelTesting::tempDir('PackageManifestTest');
        $this->manifestPath = $this->tempDirectory . '/packages.php';

        $this->filesystem->deleteDirectory($this->tempDirectory);
        $this->filesystem->ensureDirectoryExists($this->tempDirectory);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDirectory);

        [$server, $environment, $process] = $this->originalPackageTester;

        if ($server !== null) {
            $_SERVER['TESTBENCH_PACKAGE_TESTER'] = $server;
        }

        if ($environment !== null) {
            $_ENV['TESTBENCH_PACKAGE_TESTER'] = $environment;
        }

        if ($process !== false) {
            putenv("TESTBENCH_PACKAGE_TESTER={$process}");
        }

        FoundationPackageManifest::flushState();

        parent::tearDown();
    }

    /**
     * Build a fixture-backed package manifest.
     */
    private function makeManifest(?object $testbench = null, ?array $rootPackage = null): PackageManifest
    {
        return new class($this->filesystem, $this->basePath, $this->manifestPath, $testbench, $rootPackage) extends PackageManifest {
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
             * @return null|array<string, mixed>
             */
            #[Override]
            protected function providersFromTestbench(): ?array
            {
                return $this->rootPackage;
            }
        };
    }

    /**
     * Create a testbench stub with the given ignore list and root discovery setting.
     */
    private function makeTestbench(array $ignore, bool $discoversRootPackage = true): object
    {
        return new class($ignore, $discoversRootPackage) {
            /**
             * Create a new testbench stub.
             *
             * @param array<int, string> $ignore
             */
            public function __construct(
                private readonly array $ignore,
                private readonly bool $discoversRootPackage
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

            /**
             * Determine if package discovery includes the package's own Composer metadata.
             */
            public function discoversRootPackage(): bool
            {
                return $this->discoversRootPackage;
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
        $composer = $this->filesystem->json($this->basePath . '/composer.json', JSON_THROW_ON_ERROR);

        return $composer;
    }

    #[Test]
    public function itCanBuildManifestFromFixtures(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: $this->rootPackageFixture()
        );

        $manifestPath = $manifest->getManifestPath();

        $manifest->build();

        $cached = require $manifestPath;

        $this->assertArrayHasKey('testbench/example', $cached);
        $this->assertArrayHasKey('vendor-a/package-a', $cached);
        $this->assertArrayHasKey('vendor-a/package-b', $cached);
        $this->assertArrayHasKey('vendor-a/package-c', $cached);
        $this->assertArrayHasKey('vendor-a/package-d', $cached);
    }

    #[Test]
    public function itDoesNotApplyRootComposerDontDiscoverDuringBuild(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: $this->rootPackageFixture()
        );

        $manifest->build();

        $cached = require $this->manifestPath;

        $this->assertArrayHasKey('vendor-a/package-a', $cached);
    }

    #[Test]
    public function itCanBuildManifestWithoutRootComposerMetadata(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: null
        );

        $manifestPath = $manifest->getManifestPath();

        $manifest->build();

        $cached = require $manifestPath;

        $this->assertArrayNotHasKey('testbench/example', $cached);
        $this->assertArrayHasKey('vendor-a/package-a', $cached);
        $this->assertArrayHasKey('vendor-a/package-b', $cached);
    }

    #[Test]
    public function itRejectsAnEmptyRootPackageName(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: ['name' => '']
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('member [name] must be a non-empty string.');

        $manifest->build();
    }

    #[Test]
    #[TestWith([false, ['extra' => ['hypervel' => ['providers' => ['Testbench\Example\ServiceProvider']]]]])]
    #[TestWith([true, ['require' => ['php' => '^8.4']]])]
    public function itAllowsAnUnnamedRootPackageWithNothingToDiscover(bool $discoversRootPackage, array $rootPackage): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([], $discoversRootPackage),
            rootPackage: $rootPackage
        );

        $manifest->build();

        $cached = require $this->manifestPath;

        $this->assertArrayHasKey('vendor-a/package-a', $cached);
        $this->assertNotContains('Testbench\Example\ServiceProvider', $manifest->providers());
    }

    #[Test]
    public function itRequiresANameToDiscoverRootPackageMetadata(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: ['extra' => ['hypervel' => ['providers' => ['Testbench\Example\ServiceProvider']]]]
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('member [name] must be a non-empty string.');

        $manifest->build();
    }

    #[Test]
    public function itRejectsAnEmptyFormattedRootPackageName(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: ['name' => '/custom/vendor/']
        );
        $manifest->vendorPath = '/custom/vendor';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('has an empty formatted package name.');

        $manifest->build();
    }

    #[Test]
    public function itRejectsInvalidExplicitRootHypervelMetadata(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: [
                'name' => 'testbench/example',
                'extra' => ['hypervel' => 'invalid'],
            ]
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('member [extra.hypervel] must contain an array.');

        $manifest->build();
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
    public function itCanFilterManifestUsingSpecificPackageNames(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench(['testbench/example']),
            rootPackage: $this->rootPackageFixture()
        );

        $manifest->build();

        $cached = require $this->manifestPath;

        $this->assertArrayHasKey('testbench/example', $cached);
        $this->assertFalse($manifest->hasPackage('testbench/example'));
        $this->assertTrue($manifest->hasPackage('vendor-a/package-a'));
        $this->assertContains('Hypervel\Tests\Testbench\Fixtures\Providers\ChildServiceProvider', $manifest->providers());
        $this->assertArrayNotHasKey('RootAlias', $manifest->aliases());
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
    public function itIncludesRootMetadataInTheSameInstanceAfterAnExplicitBuild(): void
    {
        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([]),
            rootPackage: $this->rootPackageFixture()
        );

        $manifest->build();

        $this->assertContains('Hypervel\Tests\Testbench\Fixtures\Providers\ParentServiceProvider', $manifest->providers());
        $this->assertSame('RootClass', $manifest->aliases()['RootAlias'] ?? null);
    }

    #[Test]
    #[TestWith([true, true])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([false, false])]
    public function itKeepsACachedManifestInStepWithTheRootDiscoveryDecision(bool $cachedWithRoot, bool $discoversRootPackage): void
    {
        $this->makeManifest(
            testbench: $this->makeTestbench([], $cachedWithRoot),
            rootPackage: $this->rootPackageFixture()
        )->build();

        $manifest = $this->makeManifest(
            testbench: $this->makeTestbench([], $discoversRootPackage),
            rootPackage: $this->rootPackageFixture()
        );

        $this->assertSame($discoversRootPackage, $manifest->hasPackage('testbench/example'));

        // Plain Foundation readers, such as the skeleton artisan run by cache commands,
        // read the file the Testbench manifest keeps in step.
        $plainReader = new FoundationPackageManifest($this->filesystem, $this->basePath, $this->manifestPath);

        $this->assertSame($discoversRootPackage, $plainReader->hasPackage('testbench/example'));
    }

    #[Test]
    #[TestWith([true])]
    #[TestWith([false])]
    public function itAppliesTheParentRootDiscoveryDecisionInABorrowedRuntime(bool $parentDiscoversRootPackage): void
    {
        $variables = [
            'TESTBENCH_PACKAGE_REMOTE' => '(true)',
            'TESTBENCH_BASE_PATH' => $this->basePath,
            'TESTBENCH_RUNTIME_COPY' => '(true)',
        ];

        $original = array_map(
            static fn (string $key): array => [$_SERVER[$key] ?? null, $_ENV[$key] ?? null],
            array_combine(array_keys($variables), array_keys($variables)),
        );

        foreach ($variables as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }

        Env::set('TESTBENCH_DISCOVERS_ROOT_PACKAGE', Env::encode($parentDiscoversRootPackage));

        try {
            // The child's own context would make the opposite decision.
            $makeChildManifest = fn (): PackageManifest => $this->makeManifest(
                testbench: $this->makeTestbench([], ! $parentDiscoversRootPackage),
                rootPackage: $this->rootPackageFixture()
            );
            $cachedHasRoot = fn (): bool => str_contains((string) file_get_contents($this->manifestPath), "'testbench/example'");

            // A missing file, as after clear-compiled, is rebuilt with the parent's decision.
            $makeChildManifest()->providers();
            $this->assertSame($parentDiscoversRootPackage, $cachedHasRoot());

            // An explicit build, as by package:discover, keeps the parent's decision.
            $makeChildManifest()->build();
            $this->assertSame($parentDiscoversRootPackage, $cachedHasRoot());

            // A cached read leaves the parent's file unchanged.
            $contents = file_get_contents($this->manifestPath);
            $makeChildManifest()->providers();
            $this->assertSame($contents, file_get_contents($this->manifestPath));
        } finally {
            foreach ($original as $key => [$server, $environment]) {
                unset($_SERVER[$key], $_ENV[$key]);

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }

                if ($environment !== null) {
                    $_ENV[$key] = $environment;
                }
            }

            Env::forget('TESTBENCH_DISCOVERS_ROOT_PACKAGE');
        }
    }

    #[Test]
    public function itSwapsTheApplicationBindingToTheTestbenchPackageManifest(): void
    {
        $manifest = $this->app->make(FoundationPackageManifest::class);

        $this->assertInstanceOf(PackageManifest::class, $manifest);
        $this->assertSame($manifest, $this->app->make(PackageManifest::class));
    }

    #[Test]
    #[WithEnv('APP_PACKAGES_CACHE', 'bootstrap/cache/testbench-custom-packages.php')]
    public function itUsesThePackageCachePathFromTheTestEnvironment(): void
    {
        $path = $this->app->basePath('bootstrap/cache/testbench-custom-packages.php');

        // Registered after TestCase's manifest cache restore, which treats this file
        // as pre-existing because the application generated it while booting.
        $this->beforeApplicationDestroyed(fn (): bool => $this->filesystem->delete($path));

        $this->assertSame($path, $this->app->make(FoundationPackageManifest::class)->manifestPath);
        $this->assertFileExists($path);
    }
}
