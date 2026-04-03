<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Tests\Testbench\TestCase;
use Hypervel\View\ViewServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class ApplicationProvidersWithDisabledServicesTest extends TestCase
{
    #[Override]
    protected function overrideApplicationProviders(ApplicationContract $app): array
    {
        return [ViewServiceProvider::class => false];
    }

    #[Test]
    public function itDoesNotLoadsTheDefaultServices(): void
    {
        $this->assertFalse($this->app->bound('blade.compiler'));
        $this->assertFalse($this->app->resolved('blade.compiler'));
    }
}
