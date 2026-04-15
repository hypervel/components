<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Tests\Testbench\Fixtures\Providers\ParentServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class AggregateServiceProviderTest extends TestCase
{
    #[Override]
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ParentServiceProvider::class,
        ];
    }

    #[Test]
    public function itPopulateExpectedServices()
    {
        $this->assertTrue($this->app->bound('parent.loaded'));
        $this->assertTrue($this->app->bound('child.loaded'));

        $this->assertTrue($this->app->make('parent.loaded'));
        $this->assertTrue($this->app->make('child.loaded'));
    }
}
