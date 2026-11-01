<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Support\Providers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Http\Request;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use RuntimeException;

#[WithConfig('app.debug', false)]
#[WithConfig('filesystems.disks.local.serve', false)]
class RouteServiceProviderHealthTest extends TestCase
{
    /**
     * Resolve application implementation.
     */
    protected function resolveApplication(): ApplicationContract
    {
        return Application::configure(static::applicationBasePath())
            ->withRouting(
                web: __DIR__ . '/Fixtures/web.php',
                health: '/up',
            )->create();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('app.key', Str::random(32));
    }

    public function testItCanLoadHealthPage(): void
    {
        config(['app.name' => null]);

        $this->get('/up')
            ->assertOk()
            ->assertSee('<title>Hypervel</title>', false)
            ->assertSee('Application up');
    }

    #[WithConfig('view.cache', false)]
    public function testItPreservesTheCssThemeWhenACustomDirectiveIsRegistered(): void
    {
        Blade::directive('theme', static fn (): string => '/* Application theme directive */');

        $this->get('/up')
            ->assertOk()
            ->assertSee('@theme {', false);
    }

    public function testItRendersTheCurrentRequestDuration(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 12:00:00 UTC');

        $this->call('GET', '/up', server: [
            'REQUEST_TIME_FLOAT' => CarbonImmutable::now()->subSeconds(5)->getPreciseTimestamp(6) / 1_000_000,
        ])->assertOk()
            ->assertSee('Response rendered in 5000ms.');

        $this->call('GET', '/up', server: [
            'REQUEST_TIME_FLOAT' => CarbonImmutable::now()->subSeconds(2)->getPreciseTimestamp(6) / 1_000_000,
        ])->assertOk()
            ->assertSee('Response rendered in 2000ms.')
            ->assertDontSee('Response rendered in 5000ms.');
    }

    public function testItReturnsJsonWhenRequestExpectsJson(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertExactJson(['status' => 'up']);
    }

    public function testItReturnsJsonFailureStatusWhenDiagnosisReportsAProblem(): void
    {
        $failure = new RuntimeException('Database connection refused.');
        Exceptions::fake();
        Event::listen(DiagnosingHealth::class, static fn () => throw $failure);

        $this->getJson('/up')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'down']);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception === $failure);
    }

    public function testItRendersHtmlFailurePageWhenDiagnosisReportsAProblem(): void
    {
        Event::listen(DiagnosingHealth::class, static function (): never {
            throw new RuntimeException('Database connection refused.');
        });

        $this->get('/up')
            ->assertStatus(500)
            ->assertSee('experiencing problems');
    }

    public function testHealthRouteCanBeCachedWithoutAbsolutePaths(): void
    {
        $route = $this->app->make('router')->getRoutes()->match(Request::create('/up'));

        $route->prepareForSerialization();

        $this->assertStringNotContainsString(
            realpath(__DIR__ . '/../../../../../src'),
            $route->getAction('uses')
        );

        $this->get('/up')->assertOk();
    }

    public function testHealthRouteHonorsEventFakesAfterAnEarlierRequest(): void
    {
        $this->getJson('/up')->assertOk();

        Event::fake([DiagnosingHealth::class]);

        $this->getJson('/up')->assertOk();

        Event::assertDispatched(DiagnosingHealth::class);
    }

    public function testEmptyDiagnosisMessagesStillProduceFailureResponses(): void
    {
        Event::listen(DiagnosingHealth::class, static function (): never {
            throw new RuntimeException('');
        });

        $this->getJson('/up')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'down']);

        $this->get('/up')
            ->assertStatus(500)
            ->assertSee('experiencing problems');
    }
}
