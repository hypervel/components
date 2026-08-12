<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Support\Providers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
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

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', Str::random(32));
    }

    public function testItCanLoadHealthPage(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertSee('Application up');
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
        Event::listen(DiagnosingHealth::class, static function (): never {
            throw new RuntimeException('Database connection refused.');
        });

        $this->getJson('/up')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'down']);
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
