<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Facade;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

/**
 * Prove base application tests bootstrap the app during setUp().
 *
 * This guards the real app-test path that failed before user tests ran
 * because the lifecycle touched facades before the app was bootstrapped.
 */
class ApplicationBootstrapTest extends TestCase
{
    protected Filesystem $filesystem;

    protected string $appBasePath;

    protected mixed $previousEnvironmentBasePath = null;

    protected mixed $previousServerBasePath = null;

    protected bool $hadEnvironmentBasePath = false;

    protected bool $hadServerBasePath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->appBasePath = ParallelTesting::tempDir('ApplicationBootstrapTest');
        $this->rememberBasePathOverride();

        $this->filesystem->deleteDirectory($this->appBasePath);

        // Full kernel bootstrap expects writable boot cache and proxy output paths.
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/bootstrap/cache');
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/config');
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/storage/framework/aop');
        $this->filesystem->put($this->appBasePath . '/bootstrap/app.php', <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))->create();
PHP);
        $this->filesystem->put($this->appBasePath . '/config/app.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'Foundation Bootstrap Test',
];
PHP);

        // Application::inferBasePath() supports either source.
        $_ENV['APP_BASE_PATH'] = $this->appBasePath;
        $_SERVER['APP_BASE_PATH'] = $this->appBasePath;
    }

    protected function tearDown(): void
    {
        try {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            $this->restoreBasePathOverride();
            $this->filesystem->deleteDirectory($this->appBasePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testFoundationTestCaseBootstrapsCreatedApplication(): void
    {
        $testCase = new class('testApplicationBootstraps') extends FoundationTestCase {
            public function runSetUp(): void
            {
                $this->setUp();
            }

            public function runTearDown(): void
            {
                $this->tearDown();
            }

            public function application(): ApplicationContract
            {
                return $this->app;
            }

            public function hasApplication(): bool
            {
                return $this->app !== null;
            }

            public function testApplicationBootstraps(): void
            {
            }
        };

        try {
            // The real setUp() hits the facade call that failed before app bootstrap.
            $testCase->runSetUp();

            $app = $testCase->application();

            $this->assertTrue($app->hasBeenBootstrapped());
            $this->assertSame($this->appBasePath, $app->basePath());
            $this->assertSame($app, Facade::getFacadeApplication());
            $this->assertSame('Foundation Bootstrap Test', Config::get('app.name'));
        } finally {
            if ($testCase->hasApplication()) {
                $testCase->runTearDown();
            }
        }
    }

    protected function rememberBasePathOverride(): void
    {
        $this->previousEnvironmentBasePath = $_ENV['APP_BASE_PATH'] ?? null;
        $this->previousServerBasePath = $_SERVER['APP_BASE_PATH'] ?? null;
        $this->hadEnvironmentBasePath = array_key_exists('APP_BASE_PATH', $_ENV);
        $this->hadServerBasePath = array_key_exists('APP_BASE_PATH', $_SERVER);
    }

    protected function restoreBasePathOverride(): void
    {
        if ($this->hadEnvironmentBasePath) {
            $_ENV['APP_BASE_PATH'] = $this->previousEnvironmentBasePath;
        } else {
            unset($_ENV['APP_BASE_PATH']);
        }

        if ($this->hadServerBasePath) {
            $_SERVER['APP_BASE_PATH'] = $this->previousServerBasePath;
        } else {
            unset($_SERVER['APP_BASE_PATH']);
        }
    }
}
