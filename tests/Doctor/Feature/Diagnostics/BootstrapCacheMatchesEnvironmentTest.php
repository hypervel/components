<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\BootstrapCacheMatchesEnvironment;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

class BootstrapCacheMatchesEnvironmentTest extends TestCase
{
    private string $basePath;

    /**
     * Create an isolated bootstrap and compiled view directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorBootstrapCache');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath . '/storage/framework/views', 0775, true);
        $this->app->setBasePath($this->basePath);
        $this->app->useStoragePath($this->basePath . '/storage');

        foreach ([$this->app->getCachedConfigPath(), $this->app->getCachedEventsPath(), $this->app->getCachedRoutesPath()] as $path) {
            File::ensureDirectoryExists(dirname($path));
        }
    }

    /**
     * Remove the bootstrap fixture.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testPassesWhenBootstrapFilesAreNotCachedLocally(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $result = (new BootstrapCacheMatchesEnvironment)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('The application bootstrap files are not cached.', $result->summary);
    }

    public function testWarnsWhenBootstrapFilesAreNotCachedOutsideLocalEnvironments(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $result = (new BootstrapCacheMatchesEnvironment)->check();

        $this->assertSame('warn', $result->status->value);
        $this->assertSame('The application bootstrap files are not cached.', $result->summary);
        $this->assertEquals([Link::docs('deployment', 'optimization')], $result->links);
    }

    public function testNoticesWhenBootstrapFilesAreCachedLocally(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        file_put_contents($this->app->getCachedConfigPath(), '<?php return [];');
        file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
        file_put_contents($this->app->getCachedRoutesPath(), '<?php return [];');
        file_put_contents($this->basePath . '/storage/framework/views/example.php', '<?php echo "cached";');

        $result = (new BootstrapCacheMatchesEnvironment)->check();

        $this->assertSame('notice', $result->status->value);
        $this->assertSame('Cached bootstrap files detected: config, events and routes.', $result->summary);
        $this->assertNull($result->details);
        $this->assertSame('If recent changes are not appearing, run `php artisan optimize:clear`.', $result->remediation);
        $this->assertEquals([Link::docs('deployment', 'optimization')], $result->links);
    }

    public function testNoticesWhenOneBootstrapFileIsCachedLocally(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        file_put_contents($this->app->getCachedConfigPath(), '<?php return [];');

        $result = (new BootstrapCacheMatchesEnvironment)->check();

        $this->assertSame('notice', $result->status->value);
        $this->assertSame('Cached bootstrap file detected: config.', $result->summary);
        $this->assertNull($result->details);
        $this->assertSame('If recent changes are not appearing, run `php artisan optimize:clear`.', $result->remediation);
    }

    public function testPassesWhenBootstrapFilesAreCachedOutsideLocalEnvironments(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        file_put_contents($this->app->getCachedConfigPath(), '<?php return [];');
        file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
        file_put_contents($this->app->getCachedRoutesPath(), '<?php return [];');

        $result = (new BootstrapCacheMatchesEnvironment)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('The application bootstrap files are cached.', $result->summary);
    }

    public function testReportsWhichBootstrapFilesAreMissingFromAPartialProductionCache(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        file_put_contents($this->app->getCachedConfigPath(), '<?php return [];');

        $result = (new BootstrapCacheMatchesEnvironment)->check();

        $this->assertSame('notice', $result->status->value);
        $this->assertSame('Cached bootstrap files: config. Uncached bootstrap files: events and routes.', $result->summary);
    }

    public function testCompiledViewsDoNotCountAsCachedBootstrapFiles(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['view.compiled' => $this->basePath . '/storage/framework/views']);
        file_put_contents($this->basePath . '/storage/framework/views/example.php', '<?php echo "cached";');

        $result = (new BootstrapCacheMatchesEnvironment)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('The application bootstrap files are not cached.', $result->summary);
    }
}
