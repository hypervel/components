<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Creation\DataCreator;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Foundation\Application as FoundationApplication;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class DataServiceProviderTest extends TestCase
{
    // REMOVED: Structure-cache command tests; worker memory is the metadata cache boundary.
    // REMOVED: Livewire/Wireable and TypeScript integration tests; Hypervel has no matching Data integration.

    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testProviderBuildsOneBootStableConfiguration(): void
    {
        $this->assertTrue($this->app->resolved(DataConfig::class));
        $this->assertFalse($this->app->resolved(DataCreator::class));
        $this->assertFalse($this->app->resolved(DataTransformer::class));

        $dataConfig = $this->app->make(DataConfig::class);

        $this->assertSame([DATE_ATOM], $dataConfig->dateFormats);
        $this->assertNull($dataConfig->wrap);

        config()->set('data.date_format', 'Y-m-d');
        config()->set('data.wrap', 'payload');

        $this->assertSame($dataConfig, $this->app->make(DataConfig::class));
        $this->assertSame([DATE_ATOM], $dataConfig->dateFormats);
        $this->assertNull($dataConfig->wrap);
    }

    public function testProviderWarmsFixedServicesAfterNonTestingApplicationBoot(): void
    {
        $originalContainer = Container::getInstance();

        try {
            $application = new FoundationApplication;
            $application->instance('env', 'production');
            $application->instance('config', new Repository);
            $application->setRunningInConsole(false);

            $creator = m::mock(DataCreator::class);
            $transformer = m::mock(DataTransformer::class);

            $application->singleton(DataCreator::class, static fn (): DataCreator => $creator);
            $application->singleton(DataTransformer::class, static fn (): DataTransformer => $transformer);

            $provider = new DataServiceProvider($application);
            $provider->register();
            $provider->boot();

            $this->assertTrue($application->resolved(DataConfig::class));
            $this->assertFalse($application->resolved(DataCreator::class));
            $this->assertFalse($application->resolved(DataTransformer::class));

            $application->boot();

            $this->assertTrue($application->resolved(DataCreator::class));
            $this->assertTrue($application->resolved(DataTransformer::class));
            $this->assertSame($creator, $application->make(DataCreator::class));
            $this->assertSame($transformer, $application->make(DataTransformer::class));
        } finally {
            Container::setInstance($originalContainer);
        }
    }
}
