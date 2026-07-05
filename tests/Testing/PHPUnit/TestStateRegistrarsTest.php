<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Hypervel\Testing\PHPUnit\TestStateRegistrars;
use Hypervel\Tests\TestCase;
use Override;
use RuntimeException;

class TestStateRegistrarsTest extends TestCase
{
    private Filesystem $filesystem;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->basePath = ParallelTesting::tempDir('TestStateRegistrarsTest');

        $this->filesystem->deleteDirectory($this->basePath);
        $this->filesystem->ensureDirectoryExists($this->basePath . '/vendor/composer');

        TestStateRegistrarRecorder::$calls = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        AfterEachTestCleanup::forgetCallbacks();
        TestStateRegistrarRecorder::$calls = [];
        $this->filesystem->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function testRegistersPackageRegistrarsFromInstalledPackages(): void
    {
        $this->writeRootComposer();
        $this->writeInstalledPackages([
            $this->package('vendor/package', [PackageTestStateRegistrar::class]),
        ]);

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['package'], TestStateRegistrarRecorder::$calls);
    }

    public function testRegistersRootRegistrarsAfterPackageRegistrars(): void
    {
        $this->writeRootComposer([
            'test-state' => [RootTestStateRegistrar::class],
        ]);
        $this->writeInstalledPackages([
            $this->package('vendor/package', [PackageTestStateRegistrar::class]),
        ]);

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['package', 'root'], TestStateRegistrarRecorder::$calls);
    }

    public function testPackageDontDiscoverSuppressesPackageTestStateDiscovery(): void
    {
        $this->writeRootComposer();
        $this->writeInstalledPackages([
            $this->package('vendor/first', [], ['vendor/second']),
            $this->package('vendor/second', [PackageTestStateRegistrar::class]),
        ]);

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame([], TestStateRegistrarRecorder::$calls);
    }

    public function testRootDontDiscoverSuppressesPackageRegistrarsButNotRootRegistrars(): void
    {
        $this->writeRootComposer([
            'dont-discover' => ['*'],
            'test-state' => [RootTestStateRegistrar::class],
        ]);
        $this->writeInstalledPackages([
            $this->package('vendor/package', [PackageTestStateRegistrar::class]),
        ]);

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['root'], TestStateRegistrarRecorder::$calls);
    }

    public function testMissingRootComposerJsonDoesNotThrow(): void
    {
        $this->writeInstalledPackages([]);

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame([], TestStateRegistrarRecorder::$calls);
    }

    public function testMissingInstalledJsonDoesNotThrow(): void
    {
        $this->writeRootComposer();

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame([], TestStateRegistrarRecorder::$calls);
    }

    public function testMalformedComposerAndInstalledJsonDoNotThrow(): void
    {
        $this->filesystem->put($this->basePath . '/composer.json', '{');
        $this->filesystem->put($this->basePath . '/vendor/composer/installed.json', '{');

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame([], TestStateRegistrarRecorder::$calls);
    }

    public function testMissingDeclaredRegistrarThrows(): void
    {
        $this->writeRootComposer();
        $this->writeInstalledPackages([
            $this->package('vendor/package', ['Missing\TestStateRegistrar']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test-state registrar [Missing\TestStateRegistrar] declared by [vendor/package] does not exist.');

        $this->makeRegistrars()->register();
    }

    public function testDeclaredRegistrarWithoutRegisterMethodThrows(): void
    {
        $this->writeRootComposer();
        $this->writeInstalledPackages([
            $this->package('vendor/package', [RegistrarWithoutRegisterMethod::class]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test-state registrar [' . RegistrarWithoutRegisterMethod::class . '] declared by [vendor/package] must define a register method.');

        $this->makeRegistrars()->register();
    }

    public function testNonStringDeclaredRegistrarThrows(): void
    {
        $this->writeRootComposer();
        $this->writeInstalledPackages([
            $this->package('vendor/package', [123]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test-state registrar declared by [vendor/package] must be a class name string.');

        $this->makeRegistrars()->register();
    }

    public function testDuplicateCallbackNamesAreLastWins(): void
    {
        $this->writeRootComposer();
        $this->writeInstalledPackages([
            $this->package('vendor/first', [PackageTestStateRegistrar::class]),
            $this->package('vendor/second', [ReplacementPackageTestStateRegistrar::class]),
        ]);

        $this->makeRegistrars()->register();
        AfterEachTestCleanup::runCallbacks();

        $this->assertSame(['replacement'], TestStateRegistrarRecorder::$calls);
    }

    public function testForRootInstallUsesComposerVendorDirEnvironmentOverride(): void
    {
        $originalVendorPath = Env::get('COMPOSER_VENDOR_DIR');
        $customVendorPath = $this->basePath . '/custom-vendor';

        Env::deleteMany(['COMPOSER_VENDOR_DIR']);
        Env::flushRepository();
        Env::getRepository()->set('COMPOSER_VENDOR_DIR', $customVendorPath);

        try {
            $this->assertSame(
                $customVendorPath,
                TestStateRegistrarsProbe::forRootInstall()->vendorPath()
            );
        } finally {
            Env::deleteMany(['COMPOSER_VENDOR_DIR']);
            Env::flushRepository();

            if (is_string($originalVendorPath)) {
                Env::getRepository()->set('COMPOSER_VENDOR_DIR', $originalVendorPath);
            }
        }
    }

    public function testResolveInstalledRootPathThrowsForInvalidComposerRootInstallPath(): void
    {
        foreach ([null, '', 123] as $installPath) {
            try {
                TestStateRegistrarsProbe::resolveInstalledRootPath($installPath);

                $this->fail('Expected a runtime exception for invalid Composer root install path.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Composer runtime metadata is missing the root package install path.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function testResolveInstalledRootPathReturnsRealPathWhenAvailable(): void
    {
        $this->assertSame(
            realpath($this->basePath),
            TestStateRegistrarsProbe::resolveInstalledRootPath($this->basePath)
        );
    }

    private function makeRegistrars(): TestStateRegistrars
    {
        return new TestStateRegistrars(
            $this->basePath,
            $this->basePath . '/vendor',
            $this->filesystem
        );
    }

    /**
     * Write the root composer.json file.
     *
     * @param array<string, mixed> $hypervel
     */
    private function writeRootComposer(array $hypervel = []): void
    {
        $this->filesystem->put($this->basePath . '/composer.json', json_encode([
            'extra' => [
                'hypervel' => $hypervel,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Write Composer's installed package metadata.
     *
     * @param array<int, array<string, mixed>> $packages
     */
    private function writeInstalledPackages(array $packages): void
    {
        $this->filesystem->put($this->basePath . '/vendor/composer/installed.json', json_encode([
            'packages' => $packages,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Build package metadata.
     *
     * @param array<int, class-string|int|string> $registrars
     * @param array<int, string> $dontDiscover
     *
     * @return array<string, mixed>
     */
    private function package(string $name, array $registrars = [], array $dontDiscover = []): array
    {
        $hypervel = [];

        if ($registrars !== []) {
            $hypervel['test-state'] = $registrars;
        }

        if ($dontDiscover !== []) {
            $hypervel['dont-discover'] = $dontDiscover;
        }

        return [
            'name' => $name,
            'version' => 'v1.0.0',
            'extra' => [
                'hypervel' => $hypervel,
            ],
        ];
    }
}

class TestStateRegistrarRecorder
{
    /**
     * The recorded cleanup calls.
     *
     * @var array<int, string>
     */
    public static array $calls = [];
}

class PackageTestStateRegistrar
{
    /**
     * Register package test-state cleanup.
     */
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('vendor/package', function (): void {
            TestStateRegistrarRecorder::$calls[] = 'package';
        });
    }
}

class ReplacementPackageTestStateRegistrar
{
    /**
     * Register replacement package test-state cleanup.
     */
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('vendor/package', function (): void {
            TestStateRegistrarRecorder::$calls[] = 'replacement';
        });
    }
}

class RootTestStateRegistrar
{
    /**
     * Register root test-state cleanup.
     */
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('app', function (): void {
            TestStateRegistrarRecorder::$calls[] = 'root';
        });
    }
}

class RegistrarWithoutRegisterMethod
{
}

class TestStateRegistrarsProbe extends TestStateRegistrars
{
    /**
     * Resolve and validate the Composer root install path.
     */
    public static function resolveInstalledRootPath(mixed $installPath): string
    {
        return parent::resolveInstalledRootPath($installPath);
    }

    /**
     * Get the configured vendor path.
     */
    public function vendorPath(): string
    {
        return $this->vendorPath;
    }
}
