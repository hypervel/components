<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class AssertPublishedFilesTest extends TestCase
{
    use InteractsWithPublishedFiles;

    /**
     * Published files owned by the test.
     *
     * @var array<int, string>
     */
    protected array $files = [];

    #[Test]
    public function itCanTestAssertFileContains(): void
    {
        $this->assertFileContains([
            'hypervel/hypervel',
        ], 'composer.json');

        $this->assertFileDoesNotContains([
            'orchestra/workbench',
        ], 'composer.json');

        $this->assertFileNotContains([
            'orchestra/workbench',
        ], 'composer.json');
    }

    #[Test]
    public function itCanTestAssertFileExists(): void
    {
        $this->assertFilenameExists('composer.json');

        $this->assertFilenameDoesNotExists('composer.lock');
        $this->assertFilenameNotExists('composer.lock');
    }

    #[Test]
    public function itCanTestAssertMigrationsFiles(): void
    {
        $this->assertMigrationFileContains([
            'return new class extends Migration',
            'Schema::create(\'users\', function (Blueprint $table) {',
        ], 'testbench_create_users_table.php', directory: 'migrations');

        $this->assertMigrationFileDoesNotContains([
            'class TestbenchCreateUsersTable extends Migration',
        ], 'testbench_create_users_table.php', directory: 'migrations');

        $this->assertMigrationFileExists('0001_01_01_000000_testbench_create_users_table.php', 'migrations');
        $this->assertMigrationFileDoesNotExists('0001_01_01_000000_create_users_table.php', 'migrations');
    }

    #[Test]
    public function itCleansMigrationFilesAfterOrdinaryFileCleanupFails(): void
    {
        $filesystem = new Filesystem;
        $publishedFile = $this->app->basePath('published.txt');
        $migrationFile = $this->app->databasePath('migrations/2026_08_09_000000_published.php');
        $this->files = ['published.txt'];
        $filesystem->put($publishedFile, 'published');
        $filesystem->put($migrationFile, '<?php');
        $this->app->instance('files', new FailingPublishedFileFilesystem($publishedFile));

        try {
            try {
                $this->cleanUpPublishedFileSets();
                $this->fail('Expected published file cleanup to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    "Unable to remove published files [{$publishedFile}].",
                    $exception->getMessage(),
                );
            }

            $this->assertFileExists($publishedFile);
            $this->assertFileDoesNotExist($migrationFile);
        } finally {
            $this->app->instance('files', $filesystem);
            $filesystem->delete([$publishedFile, $migrationFile]);
        }
    }
}

class FailingPublishedFileFilesystem extends Filesystem
{
    /**
     * Construct the filesystem.
     */
    public function __construct(
        private readonly string $failingPath,
    ) {
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        $paths = is_array($paths) ? $paths : [$paths];

        if (! in_array($this->failingPath, $paths, true)) {
            return parent::delete($paths);
        }

        parent::delete(array_values(array_diff($paths, [$this->failingPath])));

        return false;
    }
}
