<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Assert;
use Hypervel\Testing\ParallelTesting;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Testbench\remote;

class AboutCommandTest extends TestCase
{
    protected string $directory;

    /**
     * Prepare an isolated bootstrap directory for cache checks.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ParallelTesting::tempDir('AboutCommandTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->directory);
        $files->ensureDirectoryExists($this->directory);
    }

    /**
     * Remove the temporary bootstrap directory.
     */
    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    #[WithEnv('APP_URL', 'http://localhost')]
    public function testItCanDisplayAboutCommandAsJson(): void
    {
        $process = remote('about --json', ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'])->mustRun();

        tap(json_decode($process->getOutput(), true), function (array $output): void {
            Assert::assertArraySubset([
                'application_name' => 'Testbench',
                'php_version' => PHP_VERSION,
                'swoole_version' => swoole_version(),
                'environment' => 'local',
                'debug_mode' => true,
                'url' => 'localhost',
                'maintenance_mode' => false,
            ], $output['environment']);

            Assert::assertArraySubset([
                'config' => false,
                'events' => false,
                'routes' => false,
            ], $output['cache']);

            Assert::assertArraySubset([
                'broadcasting' => 'log',
                'cache' => 'database',
                'database' => 'testing',
                'logs' => ['single'],
                'mail' => 'log',
                'queue' => 'sync',
                'session' => 'cookie',
            ], $output['drivers']);
        });
    }

    public function testItDisplaysAnEmptyUrlWhenTheApplicationHasNoCanonicalUrl(): void
    {
        config(['app.url' => null]);
        $this->withoutMockingConsoleOutput();

        $this->artisan(AboutCommand::class, ['--json' => null]);

        $output = json_decode(Artisan::output(), true);

        $this->assertSame('', $output['environment']['url']);
    }

    #[DataProvider('proxyCacheStates')]
    public function testItReportsWhetherAopProxiesAreCached(bool $cached): void
    {
        $this->app->useBootstrapPath($this->directory);

        if ($cached) {
            $files = new Filesystem;
            $files->ensureDirectoryExists($this->app->bootstrapPath('cache/aop'));
            $files->put($this->app->bootstrapPath('cache/aop/Example.proxy.php'), '<?php');
        }

        $this->withoutMockingConsoleOutput();
        $this->artisan(AboutCommand::class, ['--json' => null]);

        $output = json_decode(Artisan::output(), true);

        $this->assertSame($cached, $output['cache']['aop_proxies']);
    }

    /**
     * Provide empty and populated proxy caches.
     */
    public static function proxyCacheStates(): array
    {
        return ['empty' => [false], 'populated' => [true]];
    }

    #[WithEnv('VIEW_COMPILED_PATH', __DIR__ . '/Fixtures/compiled-views')]
    public function testItRespectsCustomPathForCompiledViews(): void
    {
        $process = remote('about --json', ['APP_ENV' => 'local'])->mustRun();

        tap(json_decode($process->getOutput(), true), static function (array $output) {
            Assert::assertArraySubset([
                'views' => true,
            ], $output['cache']);
        });
    }

    public function testItReportsCompiledViewsWhenCached(): void
    {
        remote('view:cache')->mustRun();

        $process = remote('about --json', ['APP_ENV' => 'local'])->mustRun();

        tap(json_decode($process->getOutput(), true), static function (array $output) {
            Assert::assertArraySubset([
                'views' => true,
            ], $output['cache']);
        });
    }
}
