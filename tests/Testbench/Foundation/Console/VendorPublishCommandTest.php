<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\transform_realpath_to_relative;

/**
 * @internal
 * @coversNothing
 */
#[RequiresOperatingSystem('Linux|Darwin')]
class VendorPublishCommandTest extends TestCase
{
    private Filesystem $filesystem;

    private string $workingDirectory;

    /**
     * @var array<class-string, array<string, string>>
     */
    private array $originalPublishes;

    /**
     * @var array<string, array<string, string>>
     */
    private array $originalPublishGroups;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->workingDirectory = package_path('tests', 'Testbench', 'Fixtures', 'vendor-publish-' . uniqid());
        $this->originalPublishes = ServiceProvider::$publishes;
        $this->originalPublishGroups = ServiceProvider::$publishGroups;
    }

    #[Override]
    protected function tearDown(): void
    {
        ServiceProvider::$publishes = $this->originalPublishes;
        ServiceProvider::$publishGroups = $this->originalPublishGroups;
        $this->filesystem->deleteDirectory($this->workingDirectory);

        parent::tearDown();
    }

    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TestbenchServiceProvider::class,
        ];
    }

    #[Test]
    public function itDisplaysPublishedPathsRelativeToThePackageRoot()
    {
        $source = join_paths($this->workingDirectory, 'source.php');
        $destination = join_paths($this->workingDirectory, 'published.php');

        $this->filesystem->ensureDirectoryExists(dirname($source));
        $this->filesystem->put($source, '<?php return ["published" => true];');

        ServiceProvider::$publishes[TestVendorPublishServiceProvider::class] = [
            $source => $destination,
        ];

        $this->artisan('vendor:publish', ['--provider' => TestVendorPublishServiceProvider::class])
            ->expectsOutputToContain(sprintf(
                'Copying file [%s] to [%s]',
                transform_realpath_to_relative($source),
                transform_realpath_to_relative($destination),
            ))
            ->assertOk();

        $this->assertFileExists($destination);
        $this->assertSame(
            '<?php return ["published" => true];',
            $this->filesystem->get($destination),
        );
    }
}

class TestVendorPublishServiceProvider extends ServiceProvider
{
}
