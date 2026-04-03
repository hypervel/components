<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class DontDiscoverPackagesTest extends TestCase
{
    protected bool $enablesPackageDiscoveries = false;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [];
    }

    #[Test]
    public function itCanDisablePackageAutoDiscovery(): void
    {
        $loadedProviders = collect($this->app->getLoadedProviders())->keys()->all();

        $this->assertNotContains('Workbench\App\Providers\WorkbenchServiceProvider', $loadedProviders);
        $this->assertNotContains('Workbench\App\Providers\AppServiceProvider', $loadedProviders);
    }
}
