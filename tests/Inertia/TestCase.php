<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Response;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\InertiaServiceProvider;
use Hypervel\Support\Facades\View;
use Hypervel\Testbench\TestCase as Testbench;
use Hypervel\Testing\TestResponse;

abstract class TestCase extends Testbench
{
    /**
     * Example Page Objects.
     *
     * @var array<string, mixed>
     */
    protected const EXAMPLE_PAGE_OBJECT = [
        'component' => 'Foo/Bar',
        'props' => ['foo' => 'bar'],
        'url' => '/test',
        'version' => '',
    ];

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            InertiaServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        View::addLocation(__DIR__ . '/Fixtures');

        Inertia::setRootView('welcome');
        Inertia::transformComponentUsing();
        config()->set('inertia.testing.ensure_pages_exist', false);
        config()->set('inertia.pages.paths', [realpath(__DIR__)]);
    }

    /**
     * Make a mock request through the given middleware.
     *
     * @param array<int, class-string>|class-string $middleware
     * @return TestResponse<Response>
     */
    protected function makeMockRequest(mixed $view, string|array $middleware = []): TestResponse
    {
        $middleware = is_array($middleware) ? $middleware : [$middleware];

        app('router')->middleware($middleware)->get('/example-url', function () use ($view) {
            return is_callable($view) ? $view() : $view;
        });

        return $this->get('/example-url');
    }
}
