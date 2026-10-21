<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Support;

use Hypervel\Doctor\Support\ComposerJson;
use Hypervel\Doctor\Support\InstalledPackages;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class InstalledPackagesTest extends TestCase
{
    private string $path;

    private InstalledPackages $packages;

    /**
     * Isolate installed Composer metadata from the development repository.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->path = ParallelTesting::tempDir('DoctorInstalledPackages');
        File::deleteDirectory($this->path);
        File::makeDirectory($this->path . '/composer', 0775, true);
        $this->packages = new class($this->path) extends InstalledPackages {
            /**
             * Use fixture metadata for this snapshot.
             */
            public function __construct(private string $path)
            {
            }

            /**
             * Resolve the fixture vendor path.
             */
            protected function vendorPath(): string
            {
                return $this->path;
            }
        };
    }

    /**
     * Remove temporary installed metadata.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->path);
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('installationModes')]
    public function testOnlyIncludesRootDevelopmentRequirementsForDevInstalls(?bool $dev): void
    {
        if ($dev !== null) {
            file_put_contents($this->path . '/composer/installed.json', json_encode([
                'dev' => $dev,
                'packages' => [[
                    'name' => 'example/dependency',
                    'require' => ['php' => '>=8.4', 'example/library' => '^1.0'],
                    'require-dev' => ['ext-dependency-dev' => '*'],
                ]],
            ], JSON_THROW_ON_ERROR));
        }

        $snapshot = $this->snapshot([
            'name' => 'example/app',
            'require' => ['ext-shared' => '^2.0'],
            'require-dev' => ['ext-shared' => '^2.5', 'ext-root-dev' => '*'],
        ]);

        $this->assertSame($dev === true
            ? ['example/app' => '^2.0', 'example/app (require-dev)' => '^2.5']
            : ['example/app' => '^2.0'], $snapshot['ext-shared']['requirements']);
        $this->assertSame($dev === true, isset($snapshot['ext-root-dev']));
        $this->assertArrayNotHasKey('ext-dependency-dev', $snapshot);
        $this->assertArrayNotHasKey('example/library', $snapshot);

        if ($dev !== null) {
            $this->assertSame(['example/dependency' => '>=8.4'], $snapshot['php']['requirements']);
        }
    }

    /**
     * Provide installed development, production, and unavailable metadata.
     */
    public static function installationModes(): array
    {
        return [[true], [false], [null]];
    }

    public function testResolvesRootAndInstalledSelfVersionsAndPreservesProviderRanges(): void
    {
        file_put_contents($this->path . '/composer/installed.json', json_encode(['packages' => [[
            'name' => 'example/provider',
            'version' => 'v2.4.0',
            'version_normalized' => '2.4.0.0',
            'provide' => ['ext-provided' => '^2.0'],
            'replace' => ['ext-replaced' => 'self.version'],
        ]]], JSON_THROW_ON_ERROR));
        file_put_contents($this->path . '/composer/installed.php', "<?php return ['root' => ['version' => '3.0.0.0']];");

        $snapshot = $this->snapshot([
            'name' => 'example/app',
            'provide' => ['ext-root' => 'self.version'],
        ]);

        $this->assertSame(['example/app' => '3.0.0.0'], $snapshot['ext-root']['providers']);
        $this->assertSame(['example/provider' => '^2.0'], $snapshot['ext-provided']['providers']);
        $this->assertSame(['example/provider' => '2.4.0.0'], $snapshot['ext-replaced']['providers']);
    }

    public function testDoesNotInventAnUnknownRootSelfVersion(): void
    {
        $this->assertSame([], $this->snapshot(['provide' => ['ext-root' => 'self.version']]));
    }

    /**
     * Build a snapshot using the diagnostic's supplied root manifest.
     */
    private function snapshot(array $root): array
    {
        $composer = m::mock(ComposerJson::class);
        $composer->allows('contents')->andReturn($root);

        return $this->packages->platformRequirements($composer);
    }
}
