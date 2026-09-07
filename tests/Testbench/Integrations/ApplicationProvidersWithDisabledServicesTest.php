<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Hypervel\View\ViewServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\Test;

class ApplicationProvidersWithDisabledServicesTest extends TestCase
{
    #[Override]
    protected function overrideApplicationProviders(ApplicationContract $app): array
    {
        return [
            RouteServiceProvider::class => false,
            ViewServiceProvider::class => false,
        ];
    }

    #[Test]
    public function itDoesNotLoadDisabledServices(): void
    {
        $this->assertNull($this->app->getProvider(RouteServiceProvider::class));
        $this->assertFalse($this->app->bound('blade.compiler'));
        $this->assertFalse($this->app->resolved('blade.compiler'));
    }
}
