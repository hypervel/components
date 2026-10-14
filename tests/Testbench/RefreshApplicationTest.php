<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class RefreshApplicationTest extends TestCase
{
    protected ?RuntimeException $bootstrapFailure = null;

    /**
     * Install the failing provider only for the replacement application.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        if ($this->bootstrapFailure === null) {
            return [];
        }

        $app->instance('test.refresh_failure', $this->bootstrapFailure);

        return [FailingRefreshApplicationProvider::class];
    }

    public function testFailedRefreshRestoresThePreviousApplicationGlobals(): void
    {
        $previousApplication = $this->app;
        $previousConfig = Config::getFacadeRoot();
        $this->bootstrapFailure = new RuntimeException('Replacement application failed to boot.');

        try {
            $this->refreshApplication();
            $this->fail('The replacement application must fail to boot.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->bootstrapFailure, $exception);
        }

        $this->assertSame($previousApplication, $this->app);
        $this->assertSame($previousApplication, Container::getInstance());
        $this->assertSame($previousApplication, Facade::getFacadeApplication());
        $this->assertSame($previousApplication, app());
        $this->assertSame($previousConfig, Config::getFacadeRoot());
    }
}

class FailingRefreshApplicationProvider extends ServiceProvider
{
    /**
     * Resolve a facade against the new application before failing its bootstrap.
     */
    public function boot(): void
    {
        Config::get('app.name');

        throw $this->app->make('test.refresh_failure');
    }
}
