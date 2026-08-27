<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\ServiceProvider;
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
    /**
     * Let the nested Foundation test case own the coroutine lifecycle under test.
     */
    protected bool $runTestsInCoroutine = false;

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
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/routes');
        $this->filesystem->ensureDirectoryExists($this->appBasePath . '/storage/framework/aop');
        $this->filesystem->put($this->appBasePath . '/bootstrap/app.php', <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(using: static function (): void {
        require dirname(__DIR__) . '/routes/web.php';
    })
    ->withExceptions()
    ->create();
PHP);
        $this->filesystem->put($this->appBasePath . '/config/app.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'Foundation Bootstrap Test',
    'providers' => [
        // Keep this Hypervel-prefixed provider first so it boots before DatabaseServiceProvider.
        \Hypervel\Tests\Foundation\Testing\DatabaseQueryingTestServiceProvider::class,
        ...\Hypervel\Support\ServiceProvider::defaultProviders()->toArray(),
    ],
];
PHP);
        $this->filesystem->put($this->appBasePath . '/config/database.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'default' => 'testing',
    'connections' => [
        'testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 0.05,
            ],
        ],
    ],
];
PHP);
        $this->filesystem->put($this->appBasePath . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Route;

Route::post('/testing/database-resolver', function () {
    $parentVisible = DB::table('testing_resolver_records')
        ->where('value', 'parent')
        ->exists();

    DB::table('testing_resolver_records')->insert(['value' => 'request']);

    return response()->json(['parent_visible' => $parentVisible]);
});
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

    public function testFoundationTestCaseSharesItsDatabaseConnectionWithNestedRequests(): void
    {
        $testCase = new class('testDatabaseConnectionIsShared') extends FoundationTestCase {
            use RefreshDatabase;

            public function runSetUp(): void
            {
                $this->setUp();
            }

            public function runTearDown(): void
            {
                $this->tearDown();
            }

            public function hasApplication(): bool
            {
                return $this->app !== null;
            }

            public function runTestMethod(): void
            {
                $this->invokeTestMethod('testDatabaseConnectionIsShared', []);
            }

            protected function afterRefreshingDatabase(): void
            {
                Schema::create('testing_resolver_records', function (Blueprint $table): void {
                    $table->id();
                    $table->string('value');
                });
            }

            public function testDatabaseConnectionIsShared(): void
            {
                $resolver = $this->app->make('db.resolver');

                $this->assertInstanceOf(DatabaseConnectionResolver::class, $resolver);
                $this->assertSame($resolver, $this->app->make('test.boot_database_resolver'));
                $this->assertTrue($this->app->make('test.boot_model_resolver_matches'));

                DB::table('testing_resolver_records')->insert(['value' => 'parent']);

                $this->post('/testing/database-resolver')
                    ->assertOk()
                    ->assertJson(['parent_visible' => true]);

                $this->assertDatabaseHas('testing_resolver_records', ['value' => 'request']);
            }
        };

        try {
            $testCase->runSetUp();
            $testCase->runTestMethod();
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

class DatabaseQueryingTestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->instance('test.boot_database_resolver', $this->app->make('db.resolver'));
        $this->app->instance(
            'test.boot_model_resolver_matches',
            Model::getConnectionResolver() === $this->app->make('db'),
        );

        DB::selectOne('select 1');
    }
}
