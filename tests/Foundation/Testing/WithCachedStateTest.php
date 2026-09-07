<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\WithCachedStateTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Foundation\Testing\CachedState;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Foundation\Testing\WithCachedConfig;
use Hypervel\Foundation\Testing\WithCachedRoutes;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Route;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

class WithCachedStateTest extends TestCase
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
        $this->appBasePath = ParallelTesting::tempDir('WithCachedStateTest');
        $this->rememberBasePathOverride();

        $this->filesystem->deleteDirectory($this->appBasePath);
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/bootstrap/cache');
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/config');
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/storage/framework/aop');
        $this->filesystem->put($this->appBasePath . '/bootstrap/app.php', <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\Tests\Foundation\Testing\WithCachedStateTest\CachedFoundationStateFixture;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(using: static fn () => CachedFoundationStateFixture::loadRoutes())
    ->create();
PHP);
        $this->filesystem->put($this->appBasePath . '/config/app.php', <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Tests\Foundation\Testing\WithCachedStateTest\CachedFoundationStateFixture;

return CachedFoundationStateFixture::loadConfig();
PHP);

        $_ENV['APP_BASE_PATH'] = $this->appBasePath;
        $_SERVER['APP_BASE_PATH'] = $this->appBasePath;

        CachedState::$cachedConfig = null;
        CachedState::$cachedRoutes = null;
        CachedFoundationStateFixture::$configLoads = 0;
        CachedFoundationStateFixture::$routeLoads = 0;
        LoadConfiguration::flushState();
        RouteServiceProvider::flushState();
    }

    protected function tearDown(): void
    {
        try {
            CachedState::$cachedConfig = null;
            CachedState::$cachedRoutes = null;
            LoadConfiguration::flushState();
            RouteServiceProvider::flushState();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            $this->restoreBasePathOverride();
            $this->filesystem->deleteDirectory($this->appBasePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testCachedStateIsRearmedBeforeSuccessiveFoundationApplicationBoots(): void
    {
        $first = new CachedFoundationTestCase('testPlaceholder');

        try {
            $first->runSetUp();

            $this->assertNotInstanceOf(CompiledRouteCollection::class, $first->routeCollection());
            $this->assertSame(1, CachedFoundationStateFixture::$configLoads);
            $this->assertSame(1, CachedFoundationStateFixture::$routeLoads);
        } finally {
            $first->runTearDown();
        }

        $second = new CachedFoundationTestCase('testPlaceholder');

        try {
            $second->runSetUp();

            $this->assertTrue($second->configLoadedFromCache());
            $this->assertTrue($second->application()->configurationIsCached());
            $this->assertTrue($second->application()->routesAreCached());
            $this->assertInstanceOf(CompiledRouteCollection::class, $second->routeCollection());
            $this->assertNotNull($second->routeCollection()->getByName('cached-state'));
            $this->assertSame(1, CachedFoundationStateFixture::$configLoads);
            $this->assertSame(1, CachedFoundationStateFixture::$routeLoads);
        } finally {
            $second->runTearDown();
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

class CachedFoundationTestCase extends FoundationTestCase
{
    use WithCachedConfig;
    use WithCachedRoutes;

    public function testPlaceholder(): void
    {
    }

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

    public function configLoadedFromCache(): bool
    {
        return (bool) $this->app->make('config_loaded_from_cache');
    }

    public function routeCollection(): CompiledRouteCollection|RouteCollection
    {
        return $this->app->make(Router::class)->getRoutes();
    }
}

class CachedFoundationStateFixture
{
    public static int $configLoads = 0;

    public static int $routeLoads = 0;

    public static function loadConfig(): array
    {
        ++self::$configLoads;

        return [
            'name' => 'Cached State Test',
        ];
    }

    public static function loadRoutes(): void
    {
        ++self::$routeLoads;

        Route::get('/cached-state', static fn (): string => 'cached')->name('cached-state');
    }
}
