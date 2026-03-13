<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\VendorPublishCommand;
use Hypervel\Foundation\Events\VendorTagPublished;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class VendorPublishCommandTest extends TestCase
{
    protected string $sourceDir;

    protected string $destDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir() . '/vendor-publish-test-source-' . uniqid();
        $this->destDir = sys_get_temp_dir() . '/vendor-publish-test-dest-' . uniqid();

        mkdir($this->sourceDir, 0755, true);
        mkdir($this->destDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        $filesystem->deleteDirectory($this->sourceDir);
        $filesystem->deleteDirectory($this->destDir);

        parent::tearDown();
    }

    public function testPublishesFile()
    {
        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return [];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        $this->assertFileExists($dest);
        $this->assertFileEquals($source, $dest);
    }

    public function testPublishesDirectory()
    {
        $sourceSubDir = $this->sourceDir . '/views';
        mkdir($sourceSubDir, 0755, true);
        file_put_contents($sourceSubDir . '/index.blade.php', '<h1>Hello</h1>');
        file_put_contents($sourceSubDir . '/layout.blade.php', '<html></html>');

        $destSubDir = $this->destDir . '/views';

        ServiceProvider::$publishes[TestPublishProvider::class] = [$sourceSubDir => $destSubDir];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        $this->assertFileExists($destSubDir . '/index.blade.php');
        $this->assertFileExists($destSubDir . '/layout.blade.php');
        $this->assertStringEqualsFile($destSubDir . '/index.blade.php', '<h1>Hello</h1>');
    }

    public function testSkipsExistingFileWithoutForce()
    {
        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return ["new"];');
        file_put_contents($dest, '<?php return ["old"];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        $this->assertStringEqualsFile($dest, '<?php return ["old"];');
    }

    public function testOverwritesExistingFileWithForce()
    {
        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return ["new"];');
        file_put_contents($dest, '<?php return ["old"];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertStringEqualsFile($dest, '<?php return ["new"];');
    }

    public function testExistingOptionOnlyOverwritesExistingFiles()
    {
        $source1 = $this->sourceDir . '/existing.php';
        $source2 = $this->sourceDir . '/new.php';
        $dest1 = $this->destDir . '/existing.php';
        $dest2 = $this->destDir . '/new.php';

        file_put_contents($source1, '<?php return ["updated"];');
        file_put_contents($source2, '<?php return ["brand-new"];');
        file_put_contents($dest1, '<?php return ["old"];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [
            $source1 => $dest1,
            $source2 => $dest2,
        ];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class, '--existing' => true])
            ->assertExitCode(0);

        $this->assertStringEqualsFile($dest1, '<?php return ["updated"];');
        $this->assertFileDoesNotExist($dest2);
    }

    public function testPublishesByTag()
    {
        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return [];');

        $otherSource = $this->sourceDir . '/other.php';
        $otherDest = $this->destDir . '/other.php';
        file_put_contents($otherSource, '<?php return ["other"];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [
            $source => $dest,
            $otherSource => $otherDest,
        ];
        ServiceProvider::$publishGroups['test-config'] = [$source => $dest];

        $this->artisan('vendor:publish', ['--tag' => ['test-config']])
            ->assertExitCode(0);

        $this->assertFileExists($dest);
        $this->assertFileDoesNotExist($otherDest);
    }

    public function testPublishesByProvider()
    {
        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return [];');

        $otherSource = $this->sourceDir . '/other.php';
        $otherDest = $this->destDir . '/other.php';
        file_put_contents($otherSource, '<?php return ["other"];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];
        ServiceProvider::$publishes[OtherPublishProvider::class] = [$otherSource => $otherDest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        $this->assertFileExists($dest);
        $this->assertFileDoesNotExist($otherDest);
    }

    public function testDispatchesVendorTagPublishedEvent()
    {
        Event::fake([VendorTagPublished::class]);

        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return [];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        Event::assertDispatched(VendorTagPublished::class);
    }

    public function testCreatesParentDirectories()
    {
        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/nested/deep/config.php';
        file_put_contents($source, '<?php return [];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        $this->assertFileExists($dest);
    }

    public function testPublishAllWithFlag()
    {
        // Isolate $publishes so --all only sees the test's entries,
        // not real framework providers that would publish into workbench.
        $originalPublishes = ServiceProvider::$publishes;
        ServiceProvider::$publishes = [];

        try {
            $source1 = $this->sourceDir . '/one.php';
            $source2 = $this->sourceDir . '/two.php';
            $dest1 = $this->destDir . '/one.php';
            $dest2 = $this->destDir . '/two.php';

            file_put_contents($source1, '<?php return ["one"];');
            file_put_contents($source2, '<?php return ["two"];');

            ServiceProvider::$publishes[TestPublishProvider::class] = [$source1 => $dest1];
            ServiceProvider::$publishes[OtherPublishProvider::class] = [$source2 => $dest2];

            $this->artisan('vendor:publish', ['--all' => true])
                ->assertExitCode(0);

            $this->assertFileExists($dest1);
            $this->assertFileExists($dest2);
        } finally {
            ServiceProvider::$publishes = $originalPublishes;
        }
    }

    public function testDontUpdateMigrationDates()
    {
        VendorPublishCommand::dontUpdateMigrationDates();

        $source = $this->sourceDir . '/2024_01_01_000000_create_users_table.php';
        $dest = $this->destDir . '/2024_01_01_000000_create_users_table.php';
        file_put_contents($source, '<?php // migration');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        // File should be published with original name since date updating is disabled
        $this->assertFileExists($dest);
    }
}

class TestPublishProvider extends ServiceProvider
{
}

class OtherPublishProvider extends ServiceProvider
{
}
