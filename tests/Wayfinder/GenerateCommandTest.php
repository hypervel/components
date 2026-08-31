<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\URL;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\Index as IndexController;
use Hypervel\Tests\Wayfinder\Fixtures\Controllers\TwoRoutesSameActionController;
use Hypervel\View\Factory;
use Hypervel\View\FileViewFinder;
use Hypervel\Wayfinder\WayfinderServiceProvider;
use Symfony\Component\Console\Exception\InvalidArgumentException;

use function Hypervel\Filesystem\join_paths;

class GenerateCommandTest extends TestCase
{
    private string $middlewareSourcePath;

    private string $tempPath;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WayfinderServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $filesystem = new Filesystem;
        $this->middlewareSourcePath = ParallelTesting::tempDir('wayfinder-generate-command-middleware');
        $this->tempPath = ParallelTesting::tempDir('wayfinder-generate-command');
        $filesystem->deleteDirectory($this->middlewareSourcePath);
        $filesystem->deleteDirectory($this->tempPath);
        $filesystem->ensureDirectoryExists($this->middlewareSourcePath);
        $this->app->make(Router::class)->setRoutes(new RouteCollection);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->middlewareSourcePath);
        $filesystem->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function testSameActionAndUriRejectDifferentResolvedDefaults(): void
    {
        Route::get('/same-uri/{tenant}', [TwoRoutesSameActionController::class, 'sameUri'])
            ->middleware(FirstWayfinderDefaultsMiddleware::class);
        Route::post('/same-uri/{tenant}', [TwoRoutesSameActionController::class, 'sameUri'])
            ->middleware(SecondWayfinderDefaultsMiddleware::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('resolve different parameter metadata');

        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-routes' => true,
        ])->run();
    }

    public function testExplicitEmptyOutputPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The --path option may not be empty.');

        $this->artisan('wayfinder:generate', ['--path' => ''])->run();
    }

    public function testControllerModuleCannotCollideWithItsBarrelPath(): void
    {
        Route::get('/index-controller', [IndexController::class, 'show']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Controller [\\' . IndexController::class . '] cannot generate module');

        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-routes' => true,
        ])->run();
    }

    public function testRepeatedGenerationDoesNotGrowViewRegistration(): void
    {
        $arguments = [
            '--path' => $this->tempPath,
            '--skip-actions' => true,
        ];

        $this->artisan('wayfinder:generate', $arguments)->run();
        $this->artisan('wayfinder:generate', $arguments)->run();

        /** @var Factory $view */
        $view = $this->app->make('view');
        $finder = $view->getFinder();

        $this->assertInstanceOf(FileViewFinder::class, $finder);
        $this->assertCount(1, $finder->getHints()['wayfinder']);
        $this->assertSame(
            1,
            collect($finder->getExtensions())->filter(fn (string $extension): bool => $extension === 'blade.ts')->count(),
        );
    }

    public function testRepeatedGenerationKeepsCollisionBarrelByteIdentical(): void
    {
        Route::get('/collision/foo-bar', fn (): string => 'foo-bar')->name('collision.foo-bar');
        Route::get('/collision/foo-bar-camel', fn (): string => 'fooBar')->name('collision.fooBar');
        Route::get('/collision/foo-bar-two', fn (): string => 'fooBar2')->name('collision.fooBar2');

        $arguments = [
            '--path' => $this->tempPath,
            '--skip-actions' => true,
        ];

        $this->artisan('wayfinder:generate', $arguments)->run();

        $path = join_paths($this->tempPath, 'routes', 'collision', 'index.ts');
        $first = (new Filesystem)->get($path);

        $this->artisan('wayfinder:generate', $arguments)->run();

        $this->assertSame($first, (new Filesystem)->get($path));
    }

    public function testDuplicateGeneratedRouteNamesFailBeforeWritingFiles(): void
    {
        Route::get('/package', fn (): string => 'package')->name('my-package::store');
        Route::post('/namespaced-package', fn (): string => 'namespaced')->name('namespaced.my-package.store');

        try {
            $this->artisan('wayfinder:generate', [
                '--path' => $this->tempPath,
                '--skip-actions' => true,
            ])->run();

            $this->fail('Expected duplicate generated route names to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('[namespaced.my-package.store]', $exception->getMessage());
            $this->assertStringContainsString('[my-package::store] (GET|HEAD', $exception->getMessage());
            $this->assertStringContainsString('[namespaced.my-package.store] (POST', $exception->getMessage());
            $this->assertStringContainsString("'/package'", $exception->getMessage());
            $this->assertStringContainsString("'/namespaced-package'", $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->tempPath);
    }

    public function testDuplicateRawRouteNamesIdentifyEveryConflictingRoute(): void
    {
        Route::get('/first', fn (): string => 'first')->name('duplicate');
        Route::post('/second', fn (): string => 'second')->name('duplicate');

        try {
            $this->artisan('wayfinder:generate', [
                '--path' => $this->tempPath,
                '--skip-actions' => true,
            ])->run();

            $this->fail('Expected duplicate route names to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('[duplicate]', $exception->getMessage());
            $this->assertStringContainsString("(GET|HEAD '/first')", $exception->getMessage());
            $this->assertStringContainsString("(POST '/second')", $exception->getMessage());
        }
    }

    public function testSkipRoutesIgnoresDuplicateNamesWhenGeneratingActions(): void
    {
        Route::get('/first', [TwoRoutesSameActionController::class, 'same'])->name('duplicate');
        Route::get('/second', [TwoRoutesSameActionController::class, 'same'])->name('duplicate');

        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-routes' => true,
        ])->run();

        $this->assertFileExists(join_paths(
            $this->tempPath,
            'actions',
            'Hypervel',
            'Tests',
            'Wayfinder',
            'Fixtures',
            'Controllers',
            'TwoRoutesSameActionController.ts',
        ));
        $this->assertDirectoryDoesNotExist(join_paths($this->tempPath, 'routes'));
    }

    public function testParameterizedMiddlewareUsesItsResolvedClassForUrlDefaults(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('wayfinder.defaults', ParameterizedWayfinderDefaultsMiddleware::class);

        Route::get('/direct/{tenant}', [ParameterizedWayfinderDefaultsController::class, 'direct'])
            ->middleware(ParameterizedWayfinderDefaultsMiddleware::class . ':tenant');
        Route::get('/alias/{tenant}', [ParameterizedWayfinderDefaultsController::class, 'alias'])
            ->middleware('wayfinder.defaults:tenant');
        Route::get('/plain/{tenant}', [ParameterizedWayfinderDefaultsController::class, 'plain'])
            ->middleware(ParameterizedWayfinderDefaultsMiddleware::class);
        // This class is intentionally undefined to exercise the absent-middleware guard.
        Route::get('/missing/{tenant}', [ParameterizedWayfinderDefaultsController::class, 'missing'])
            ->middleware(MissingWayfinderDefaultsMiddleware::class . ':tenant');

        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-routes' => true,
        ])->run();

        $content = (new Filesystem)->get(join_paths(
            $this->tempPath,
            'actions',
            'Hypervel',
            'Tests',
            'Wayfinder',
            'ParameterizedWayfinderDefaultsController.ts',
        ));

        $this->assertStringContainsString("url: '/direct/{tenant?}'", $content);
        $this->assertStringContainsString("url: '/alias/{tenant?}'", $content);
        $this->assertStringContainsString("url: '/plain/{tenant?}'", $content);
        $this->assertStringContainsString("url: '/missing/{tenant}'", $content);
    }

    public function testUppercaseExplicitOctalMiddlewareDefaultIsParsed(): void
    {
        $middlewarePath = join_paths($this->middlewareSourcePath, 'UppercaseOctalDefaultsMiddleware.php');

        (new Filesystem)->put($middlewarePath, <<<'PHP'
<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\GeneratedFixtures;

use Closure;
use Hypervel\Support\Facades\URL;

class UppercaseOctalDefaultsMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        URL::defaults(['uppercaseOctalDefault' => 0O12]);

        return $next($request);
    }
}
PHP);

        require $middlewarePath;

        Route::get('/uppercase-octal/{uppercaseOctalDefault}', [ParameterizedWayfinderDefaultsController::class, 'direct'])
            ->middleware('Hypervel\Tests\Wayfinder\GeneratedFixtures\UppercaseOctalDefaultsMiddleware');

        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-routes' => true,
        ])->run();

        $content = (new Filesystem)->get(join_paths(
            $this->tempPath,
            'actions',
            'Hypervel',
            'Tests',
            'Wayfinder',
            'ParameterizedWayfinderDefaultsController.ts',
        ));

        $this->assertStringContainsString("url: '/uppercase-octal/{uppercaseOctalDefault?}'", $content);
        $this->assertStringContainsString('uppercaseOctalDefault: args?.uppercaseOctalDefault ?? 10', $content);
    }
}

class FirstWayfinderDefaultsMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        URL::defaults(['tenant' => 'first']);

        return $next($request);
    }
}

class SecondWayfinderDefaultsMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        URL::defaults(['tenant' => 'second']);

        return $next($request);
    }
}

class ParameterizedWayfinderDefaultsController
{
    public function direct(): void
    {
    }

    public function alias(): void
    {
    }

    public function plain(): void
    {
    }

    public function missing(): void
    {
    }
}

class ParameterizedWayfinderDefaultsMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        URL::defaults(['tenant' => 'hypervel']);

        return $next($request);
    }
}
