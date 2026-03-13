<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Support\Providers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
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

    public function testItCanLoadHealthPage()
    {
        $this->get('/up')->assertOk();
    }
}
