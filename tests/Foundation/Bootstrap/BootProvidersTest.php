<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\BootProviders;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BootProvidersTest extends TestCase
{
    public function testBoot()
    {
        $app = new Application;
        $app->register(ApplicationBasicServiceProviderStub::class);

        (new BootProviders)->bootstrap($app);

        $this->assertSame('bar', $app->make('foo'));
    }
}

class ApplicationBasicServiceProviderStub extends ServiceProvider
{
    public function boot()
    {
        $this->app->singleton('foo', fn () => 'bar');
    }
}
