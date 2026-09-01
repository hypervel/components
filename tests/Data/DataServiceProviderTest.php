<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Testbench\TestCase;

class DataServiceProviderTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testProviderBuildsOneBootStableConfiguration(): void
    {
        $this->assertTrue($this->app->bound(DataConfig::class));
        $this->assertTrue($this->app->resolved(DataConfig::class));

        $dataConfig = $this->app->make(DataConfig::class);

        $this->assertSame([DATE_ATOM], $dataConfig->dateFormats);
        $this->assertNull($dataConfig->wrap);

        config()->set('data.date_format', 'Y-m-d');
        config()->set('data.wrap', 'payload');

        $this->assertSame($dataConfig, $this->app->make(DataConfig::class));
        $this->assertSame([DATE_ATOM], $dataConfig->dateFormats);
        $this->assertNull($dataConfig->wrap);
    }
}
