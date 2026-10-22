<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\PhpVersionSatisfiesComposerRequirement;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Support\ComposerJson;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PhpVersionSatisfiesComposerRequirementTest extends TestCase
{
    private string $basePath;

    /**
     * Isolate the application and its installed metadata.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorPhpVersion');
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

    public function testReportsWhenPhpDoesNotSatisfyComposerJson(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', json_encode([
            'require' => [
                'php' => '<1.0',
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->app->make(PhpVersionSatisfiesComposerRequirement::class)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame(sprintf('PHP %s does not satisfy the application or installed package requirements.', PHP_VERSION), $result->summary);
        $this->assertSame('- root requires php <1.0.', $result->details);
        $this->assertEquals([Link::docs('deployment', 'server-requirements')], $result->links);
    }

    #[DataProvider('rootRequirements')]
    public function testChecksActualPhpWithoutInstalledMetadata(array $composer, string $status): void
    {
        file_put_contents($this->basePath . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        $result = $this->app->make(PhpVersionSatisfiesComposerRequirement::class)->check();

        $this->assertSame($status, $result->status->value);
    }

    /**
     * Provide requirements independent of Composer's emulated platform.
     */
    public static function rootRequirements(): array
    {
        return [
            'no constraints' => [[], 'skip'],
            'compatible' => [['require' => ['php' => '>=8.4']], 'pass'],
            'actual runtime exceeds upper bound' => [[
                'require' => ['php' => '<8.0'],
                'config' => ['platform' => ['php' => '7.4.0']],
            ], 'fail'],
        ];
    }

    public function testRechecksInstalledDependencyRequirementsAfterMetadataChanges(): void
    {
        file_put_contents($this->basePath . '/composer.json', '{"require":{"php":">=8.4"}}');
        $path = $this->basePath . '/dependencies/composer/installed.json';
        file_put_contents($path, json_encode(['packages' => [
            ['name' => 'example/dependency', 'require' => ['php' => '<8.0']],
        ]], JSON_THROW_ON_ERROR));
        $diagnostic = $this->app->make(PhpVersionSatisfiesComposerRequirement::class);

        $result = $diagnostic->check();
        $this->assertSame('fail', $result->status->value);
        $this->assertSame('- example/dependency requires php <8.0.', $result->details);

        file_put_contents($path, json_encode(['packages' => [
            ['name' => 'example/dependency', 'require' => ['php' => '>=8.4']],
        ]], JSON_THROW_ON_ERROR));

        $this->assertSame('pass', $diagnostic->check()->status->value);
    }

    #[DataProvider('phpVersions')]
    public function testChecksDistributionAndPrereleaseVersions(string $version, string $constraint, string $status): void
    {
        file_put_contents($this->basePath . '/composer.json', json_encode([
            'require' => ['php' => $constraint],
        ], JSON_THROW_ON_ERROR));

        $diagnostic = new class($this->app->make(ComposerJson::class)) extends PhpVersionSatisfiesComposerRequirement {
            public string $version;

            /**
             * Supply a platform version independently of the test runner's PHP build.
             */
            protected function phpVersion(): string
            {
                return $this->version;
            }
        };
        $diagnostic->version = $version;

        $result = $diagnostic->check();

        $this->assertSame($status, $result->status->value);

        if ($status === 'fail') {
            $this->assertSame("PHP {$version} does not satisfy the application or installed package requirements.", $result->summary);
            $this->assertSame("- root requires php {$constraint}.", $result->details);
        }
    }

    /**
     * Provide distribution suffixes and prereleases with meaningful version constraints.
     */
    public static function phpVersions(): array
    {
        return [
            'Ubuntu build passes' => ['8.4.12-1ubuntu2.14', '^8.4', 'pass'],
            'Ubuntu build fails upper bound' => ['8.4.12-1ubuntu2.14', '<8.4', 'fail'],
            'Sury build passes' => ['8.4.12-1+ubuntu24.04.1+deb.sury.org+1', '^8.4', 'pass'],
            'Sury build fails upper bound' => ['8.4.12-1+ubuntu24.04.1+deb.sury.org+1', '<8.4', 'fail'],
            'development version is not the stable release' => ['8.4.0-dev', '8.4.0', 'fail'],
            'development version satisfies its own constraint' => ['8.4.0-dev', '8.4.0-dev', 'pass'],
        ];
    }
}
