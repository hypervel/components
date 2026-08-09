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
    private string $tempPath;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WayfinderServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = ParallelTesting::tempDir('wayfinder-generate-command');
        (new Filesystem)->deleteDirectory($this->tempPath);
        $this->app->make(Router::class)->setRoutes(new RouteCollection);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempPath);

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
