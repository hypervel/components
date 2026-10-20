<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Wayfinder\WayfinderServiceProvider;

use function Hypervel\Filesystem\join_paths;

class SkipDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    private Filesystem $files;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WayfinderServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempPath = ParallelTesting::tempDir('wayfinder-skip-database');
        $this->files->deleteDirectory($this->tempPath);

        Route::get('/docblock-posts/{post}', function (DocblockPost $post) {
            return $post;
        })->name('docblock-posts.show');
    }

    /**
     * Create the docblock posts table after refreshing the database.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('docblock_posts', function (Blueprint $table): void {
            $table->id();
        });
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function testSkippingTheDatabaseReadsModelDocblocks(): void
    {
        $this->assertStringContainsString('post: number', $this->generate());

        $this->assertStringContainsString('post: string', $this->generate(skipDatabase: true));
    }

    /**
     * Generate the named routes and return the docblock post route file.
     */
    private function generate(bool $skipDatabase = false): string
    {
        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-actions' => true,
            '--skip-database' => $skipDatabase,
        ])->assertSuccessful();

        return $this->files->get(join_paths($this->tempPath, 'routes', 'docblock-posts', 'index.ts'));
    }
}

/**
 * @property string $id
 */
class DocblockPost extends Model
{
    // An incrementing key's int cast would take precedence over both the schema and the docblock.
    public bool $incrementing = false;

    protected ?string $table = 'docblock_posts';
}
