<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Head\ErrorPages;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadManager;
use Hypervel\Head\HeadServiceProvider;
use Hypervel\Head\Inertia\ShareHead;
use Hypervel\Head\Schema\SchemaFactory;
use Hypervel\Head\TagRegistry;
use Hypervel\Inertia\InertiaServiceProvider;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Tests\Head\TestCase;

class HeadServiceProviderTest extends TestCase
{
    /**
     * Load Head with optional Inertia discovery disabled.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(Application $app): array
    {
        return [HeadServiceProvider::class];
    }

    /**
     * Resolve the handler before Head registers its status callback.
     */
    protected function resolveApplicationExceptionHandler(Application $app): void
    {
        parent::resolveApplicationExceptionHandler($app);

        $app->make(ExceptionHandler::class);
    }

    public function testRegistersTheHeadManager(): void
    {
        $this->assertInstanceOf(HeadManager::class, app('head'));
        $this->assertSame(app('head'), app(HeadManager::class));
        $this->assertSame(app('head.schema'), app(SchemaFactory::class));
    }

    public function testRegistersTheTagRegistry(): void
    {
        $this->assertInstanceOf(TagRegistry::class, app(TagRegistry::class));
    }

    public function testRegistersTheHeadFacade(): void
    {
        $this->assertInstanceOf(HeadManager::class, Head::getFacadeRoot());
    }

    public function testRegistersTheBladeDirective(): void
    {
        $compiled = app('blade.compiler')->compileString('@head');

        $this->assertStringContainsString("app('head')->renderForView()", $compiled);
    }

    public function testDoesNotEnableInertiaWhenItsClassesExistWithoutItsProvider(): void
    {
        $this->assertTrue(class_exists(InertiaServiceProvider::class));
        $this->assertFalse($this->app->providerIsLoaded(InertiaServiceProvider::class));
        $this->assertFalse($this->app->bound(Gateway::class));
        $this->assertFalse($this->app->make(Kernel::class)->hasMiddleware(ShareHead::class));

        Head::title('Blade page');
        $this->assertSame('<title>Blade page</title>', Head::renderForView()->toHtml());
    }

    public function testCapturesExceptionStatusWhenTheHandlerWasAlreadyResolved(): void
    {
        config(['view.paths' => [__DIR__ . '/../Fixtures/views', ...config('view.paths')]]);
        Head::errors(fn (ErrorPages $errors): ErrorPages => $errors->status(404, title: 'Early handler'));

        $this->get('/missing-with-early-handler')
            ->assertNotFound()
            ->assertSee('<title>Early handler</title>', false);
    }

    public function testSupportsCustomExceptionHandlersWithoutRenderingHooks(): void
    {
        $handler = $this->createStub(ExceptionHandler::class);
        $this->app->singleton(ExceptionHandler::class, fn (): ExceptionHandler => $handler);

        $this->assertSame($handler, $this->app->make(ExceptionHandler::class));

        Head::errors(fn (ErrorPages $errors): ErrorPages => $errors->status(404, title: 'Not found'));
        Head::status(404);

        $this->assertSame('<title>Not found</title>', Head::toHtml());
    }
}
