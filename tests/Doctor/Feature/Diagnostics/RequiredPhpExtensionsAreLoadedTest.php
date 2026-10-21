<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\RequiredPhpExtensionsAreLoaded;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RequiredPhpExtensionsAreLoadedTest extends TestCase
{
    private string $basePath;

    /**
     * Isolate the root manifest and installed metadata.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorRequiredExtensions');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath . '/dependencies/composer', 0775, true);
        $this->app->setBasePath($this->basePath);
        $this->app->make(PackageManifest::class)->vendorPath = $this->basePath . '/dependencies';
    }

    /**
     * Remove temporary Composer files.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testReportsMissingComposerRequiredPhpExtensions(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', json_encode([
            'require' => [
                'ext-hypervel_doctor_missing_extension' => '*',
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->app->make(RequiredPhpExtensionsAreLoaded::class)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('ext-hypervel_doctor_missing_extension', $result->details);
    }

    #[DataProvider('runtimeConstraints')]
    public function testChecksTheLoadedExtensionVersion(string $constraint, string $status): void
    {
        file_put_contents($this->basePath . '/composer.json', json_encode([
            'name' => 'example/app',
            'require' => ['ext-swoole' => $constraint],
        ], JSON_THROW_ON_ERROR));

        $result = $this->app->make(RequiredPhpExtensionsAreLoaded::class)->check();

        $this->assertSame($status, $result->status->value);

        if ($status === 'fail') {
            $this->assertStringContainsString('loaded ' . phpversion('swoole'), $result->details);
            $this->assertStringContainsString('example/app requires ' . $constraint, $result->details);
        }
    }

    /**
     * Provide compatible and incompatible runtime constraints.
     */
    public static function runtimeConstraints(): array
    {
        return [['>=0', 'pass'], ['<0', 'fail']];
    }

    #[DataProvider('extensionProviders')]
    public function testInstalledProvidersCanSatisfyDependencyExtensionRequirements(array $provider, string $status): void
    {
        file_put_contents($this->basePath . '/dependencies/composer/installed.json', json_encode(['packages' => [
            ['name' => 'example/dependency', 'require' => ['ext-hypervel_doctor_virtual' => '^2.3']],
            ['name' => 'example/provider', ...$provider],
        ]], JSON_THROW_ON_ERROR));

        $result = $this->app->make(RequiredPhpExtensionsAreLoaded::class)->check();

        $this->assertSame($status, $result->status->value);

        if ($status === 'fail') {
            $this->assertStringContainsString('example/dependency requires ^2.3', $result->details);
        }
    }

    /**
     * Provide Composer range and self-version links.
     */
    public static function extensionProviders(): array
    {
        return [
            'provided range overlaps' => [['provide' => ['ext-hypervel_doctor_virtual' => '^2.0']], 'pass'],
            'replacement self version' => [[
                'replace' => ['ext-hypervel_doctor_virtual' => 'self.version'], 'version_normalized' => '2.4.0.0',
            ], 'pass'],
            'incompatible provider' => [['provide' => ['ext-hypervel_doctor_virtual' => '^1.0']], 'fail'],
            'no provider' => [[], 'fail'],
        ];
    }

    public function testRootProviderCanSatisfyRequirementsWithoutInstalledMetadata(): void
    {
        file_put_contents($this->basePath . '/composer.json', json_encode([
            'require' => ['ext-hypervel_doctor_virtual' => '^2.3'],
            'provide' => ['ext-hypervel_doctor_virtual' => '^2.0'],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('pass', $this->app->make(RequiredPhpExtensionsAreLoaded::class)->check()->status->value);
    }

    public function testSkipsWhenNoRootOrInstalledMetadataIsAvailable(): void
    {
        $this->assertSame('skip', $this->app->make(RequiredPhpExtensionsAreLoaded::class)->check()->status->value);
    }

    public function testOneProviderMustSatisfyEveryRequiringPackage(): void
    {
        file_put_contents($this->basePath . '/dependencies/composer/installed.json', json_encode(['packages' => [
            ['name' => 'example/first', 'require' => ['ext-hypervel_doctor_virtual' => '^1.0']],
            ['name' => 'example/second', 'require' => ['ext-hypervel_doctor_virtual' => '^2.0']],
            ['name' => 'example/provider-first', 'provide' => ['ext-hypervel_doctor_virtual' => '^1.0']],
            ['name' => 'example/provider-second', 'provide' => ['ext-hypervel_doctor_virtual' => '^2.0']],
        ]], JSON_THROW_ON_ERROR));

        $result = $this->app->make(RequiredPhpExtensionsAreLoaded::class)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('example/first requires ^1.0', $result->details);
        $this->assertStringContainsString('example/second requires ^2.0', $result->details);
    }
}
