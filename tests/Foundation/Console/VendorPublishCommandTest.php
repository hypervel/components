<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\VendorPublishCommand;
use Hypervel\Foundation\Events\VendorTagPublished;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

class VendorPublishCommandTest extends TestCase
{
    protected Filesystem $filesystem;

    protected string $sourceDir;

    protected string $destDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->sourceDir = ParallelTesting::tempDir('VendorPublishCommandSource');
        $this->destDir = ParallelTesting::tempDir('VendorPublishCommandDestination');

        $this->filesystem->ensureDirectoryExists($this->sourceDir);
        $this->filesystem->ensureDirectoryExists($this->destDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->sourceDir);
        $this->filesystem->deleteDirectory($this->destDir);

        parent::tearDown();
    }

    public function testPublishesFile(): void
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

    public function testPublishesDirectory(): void
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

    public function testSkipsExistingFileWithoutForce(): void
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

    public function testSkippedFileUsesConfiguredPathWhenDestinationDisappears(): void
    {
        $source = $this->sourceDir . '/config.php';
        $destination = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return ["new"];');
        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $destination];

        $files = m::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('exists')->once()->with($destination)->andReturnTrue();
        $tester = $this->commandTester($files);

        $this->assertSame(0, $tester->execute(['--provider' => TestPublishProvider::class]));
        $this->assertStringContainsString($destination, $tester->getDisplay());
        $this->assertStringContainsString('SKIPPED', $tester->getDisplay());
        $this->assertFileDoesNotExist($destination);
    }

    public function testOverwritesExistingFileWithForce(): void
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

    public function testExistingOptionOnlyOverwritesExistingFiles(): void
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

    #[DataProvider('migrationRepublishTimes')]
    public function testSkipsPreviouslyPublishedMigration(string $republishedAt): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $source = $this->sourceDir . '/2024_01_01_000000_create_users_table.php';
        $destination = $this->destDir . '/2024_01_01_000000_create_users_table.php';
        file_put_contents($source, '<?php // first');
        $this->registerMigrations([$source => $destination]);

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $published = $this->publishedMigration('create_users_table.php');
        file_put_contents($source, '<?php // second');
        CarbonImmutable::setTestNow($republishedAt);

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->expectsOutputToContain(basename($published))
            ->assertSuccessful();

        $this->assertSame([$published], $this->publishedMigrations('create_users_table.php'));
        $this->assertStringEqualsFile($published, '<?php // first');
    }

    /**
     * Return the command times used to exercise same- and later-second reruns.
     */
    public static function migrationRepublishTimes(): array
    {
        return [
            'same second' => ['2026-01-01 00:00:00'],
            'later time' => ['2026-02-01 00:00:00'],
        ];
    }

    public function testDirectoryPublicationOnlyAddsMissingMigrations(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $source = $this->sourceDir . '/migrations';
        $destination = $this->destDir . '/migrations';
        $this->filesystem->ensureDirectoryExists($source);
        file_put_contents($source . '/2024_01_01_000000_create_users_table.php', '<?php // users-v1');
        file_put_contents($source . '/2024_01_02_000000_create_posts_table.php', '<?php // posts-v1');
        $this->registerMigrations([$source => $destination]);

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $publishedUsers = $this->publishedMigration('create_users_table.php', $destination);
        $publishedPosts = $this->publishedMigration('create_posts_table.php', $destination);
        $this->filesystem->delete($publishedPosts);
        file_put_contents($source . '/2024_01_01_000000_create_users_table.php', '<?php // users-v2');
        file_put_contents($source . '/2024_01_02_000000_create_posts_table.php', '<?php // posts-v2');
        CarbonImmutable::setTestNow('2026-02-01 00:00:00');

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $this->assertSame([$publishedUsers], $this->publishedMigrations('create_users_table.php', $destination));
        $this->assertStringEqualsFile($publishedUsers, '<?php // users-v1');
        $this->assertStringEqualsFile(
            $this->publishedMigration('create_posts_table.php', $destination),
            '<?php // posts-v2',
        );
    }

    public function testDirectoryPublicationOnlyUpdatesMigrationBasenames(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $source = $this->sourceDir . '/migrations';
        $sourceDirectory = $source . '/2020_01_01_000000_nested';
        $destination = $this->destDir . '/migrations';
        $destinationDirectory = $destination . '/2020_01_01_000000_nested';
        $migration = $sourceDirectory . '/2024_01_01_000000_create_users_table.php';
        $this->filesystem->ensureDirectoryExists($sourceDirectory);
        file_put_contents($migration, '<?php // first');
        $this->registerMigrations([$source => $destination]);

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $published = $this->publishedMigration('create_users_table.php', $destinationDirectory);
        $this->assertSame(
            $destinationDirectory . '/2026_01_01_000001_create_users_table.php',
            $published,
        );

        file_put_contents($migration, '<?php // second');
        CarbonImmutable::setTestNow('2026-02-01 00:00:00');

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $this->assertSame(
            [$published],
            $this->publishedMigrations('create_users_table.php', $destinationDirectory),
        );
        $this->assertStringEqualsFile($published, '<?php // first');
    }

    #[DataProvider('migrationOverwriteOptions')]
    public function testOverwritesPreviouslyPublishedMigrationInPlace(array $options): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $source = $this->sourceDir . '/2024_01_01_000000_create_users_table.php';
        $destination = $this->destDir . '/2024_01_01_000000_create_users_table.php';
        file_put_contents($source, '<?php // first');
        $this->registerMigrations([$source => $destination]);

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $published = $this->publishedMigration('create_users_table.php');
        file_put_contents($source, '<?php // second');
        CarbonImmutable::setTestNow('2026-02-01 00:00:00');

        $this->artisan('vendor:publish', [
            '--provider' => TestPublishProvider::class,
            ...$options,
        ])->assertSuccessful();

        $this->assertSame([$published], $this->publishedMigrations('create_users_table.php'));
        $this->assertStringEqualsFile($published, '<?php // second');
    }

    /**
     * Return options that intentionally overwrite a published migration.
     */
    public static function migrationOverwriteOptions(): array
    {
        return [
            'force' => [['--force' => true]],
            'existing' => [['--existing' => true]],
        ];
    }

    public function testExistingOptionSkipsUnpublishedMigration(): void
    {
        $source = $this->sourceDir . '/2024_01_01_000000_create_users_table.php';
        $destination = $this->destDir . '/2024_01_01_000000_create_users_table.php';
        file_put_contents($source, '<?php // migration');
        $this->registerMigrations([$source => $destination]);

        $this->artisan('vendor:publish', [
            '--provider' => TestPublishProvider::class,
            '--existing' => true,
        ])->expectsOutputToContain('SKIPPED')->assertSuccessful();

        $this->assertSame([], $this->publishedMigrations('create_users_table.php'));
    }

    public function testRejectsMultiplePublishedMigrationsWithTheSameSuffix(): void
    {
        $source = $this->sourceDir . '/2024_01_01_000000_create_users_table.php';
        $destination = $this->destDir . '/2024_01_01_000000_create_users_table.php';
        $first = $this->destDir . '/2025_01_01_000000_create_users_table.php';
        $second = $this->destDir . '/2026_01_01_000000_create_users_table.php';
        file_put_contents($source, '<?php // migration');
        file_put_contents($first, '<?php // first');
        file_put_contents($second, '<?php // second');
        $this->registerMigrations([$source => $destination]);

        $tester = $this->commandTester($this->filesystem);

        try {
            $tester->execute(['--provider' => TestPublishProvider::class]);
            $this->fail('Expected duplicate published migrations to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('create_users_table.php', $exception->getMessage());
            $this->assertStringContainsString($first, $exception->getMessage());
            $this->assertStringContainsString($second, $exception->getMessage());
            $this->assertStringContainsString('Remove the duplicate migrations and retry', $exception->getMessage());
        }
    }

    public function testOnlyUpdatesRegisteredMigrationBasenames(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $source = $this->sourceDir . '/2024_01_01_000000_create_users_table.php';
        $parent = $this->destDir . '/2020_01_01_000000_migrations';
        $destination = $parent . '/2024_01_01_000000_create_users_table.php';
        file_put_contents($source, '<?php // migration');
        $this->filesystem->deleteDirectory($this->destDir);
        $this->registerMigrations([$source => $destination]);

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $this->assertDirectoryExists($parent);
        $this->assertFileExists($parent . '/2026_01_01_000001_create_users_table.php');

        $unregistered = $this->sourceDir . '/2024_01_02_000000_create_posts_table.php';
        $unchanged = $parent . '/2024_01_02_000000_create_posts_table.php';
        file_put_contents($unregistered, '<?php // posts');
        ServiceProvider::$publishes[TestPublishProvider::class] = [$unregistered => $unchanged];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertSuccessful();

        $this->assertFileExists($unchanged);
    }

    public function testPublishesByTag(): void
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

    public function testPublishesByProvider(): void
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

    public function testDispatchesVendorTagPublishedEvent(): void
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

    public function testCreatesParentDirectories(): void
    {
        $source = $this->sourceDir . '/config.php';
        $dest = $this->destDir . '/nested/deep/config.php';
        file_put_contents($source, '<?php return [];');

        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $dest];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->assertExitCode(0);

        $this->assertFileExists($dest);
    }

    public function testPublishAllWithFlag(): void
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

    public function testDontUpdateMigrationDates(): void
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

    public function testCopyFailureDoesNotReportStatusOrDispatchPublishedEvent(): void
    {
        Event::fake([VendorTagPublished::class]);

        $source = $this->sourceDir . '/config.php';
        $destination = $this->destDir . '/config.php';
        file_put_contents($source, '<?php return [];');
        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $destination];

        $files = m::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('copy')->once()->with($source, $destination)->andReturnFalse();
        $tester = $this->commandTester($files);

        try {
            $tester->execute(['--provider' => TestPublishProvider::class]);
            $this->fail('Expected vendor file copying to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame("Unable to copy [{$source}] to [{$destination}].", $exception->getMessage());
        }

        $this->assertFileDoesNotExist($destination);
        $this->assertStringNotContainsString('Copying file', $tester->getDisplay());
        Event::assertNotDispatched(VendorTagPublished::class);
    }

    public function testEmptyDirectoryPublicationReportsConfiguredDestinationPath(): void
    {
        $source = $this->sourceDir . '/empty';
        $destination = $this->destDir . '/published-empty';
        $this->filesystem->ensureDirectoryExists($source);
        ServiceProvider::$publishes[TestPublishProvider::class] = [$source => $destination];

        $this->artisan('vendor:publish', ['--provider' => TestPublishProvider::class])
            ->expectsOutputToContain('published-empty')
            ->assertSuccessful();
    }

    /**
     * Create a tester for the vendor publisher.
     */
    protected function commandTester(Filesystem $files): CommandTester
    {
        $command = new VendorPublishCommand($files);
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }

    /**
     * Register migration paths for the test provider.
     */
    protected function registerMigrations(array $paths): void
    {
        config(['database.migrations.update_date_on_publish' => true]);

        (new TestPublishProvider($this->app))->publishMigrations($paths);
    }

    /**
     * Return the published migration path for a suffix.
     */
    protected function publishedMigration(string $suffix, ?string $directory = null): string
    {
        $published = $this->publishedMigrations($suffix, $directory);

        $this->assertCount(1, $published);

        return $published[0];
    }

    /**
     * Return the published migration paths for a suffix.
     *
     * @return list<string>
     */
    protected function publishedMigrations(string $suffix, ?string $directory = null): array
    {
        $paths = glob(($directory ?? $this->destDir) . '/*_' . $suffix);

        $this->assertIsArray($paths);

        sort($paths, SORT_STRING);

        return $paths;
    }
}

class TestPublishProvider extends ServiceProvider
{
    /**
     * Register migration paths to publish.
     */
    public function publishMigrations(array $paths): void
    {
        $this->publishesMigrations($paths);
    }
}

class OtherPublishProvider extends ServiceProvider
{
}
