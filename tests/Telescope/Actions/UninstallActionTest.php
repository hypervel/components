<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Actions;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Telescope\TelescopeServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;

class UninstallActionTest extends TestCase
{
    protected string $originalProviders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalProviders = (new Filesystem)->get($this->app->getBootstrapProvidersPath());
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $providersPath = $this->app->getBootstrapProvidersPath();

        CleanupActions::run(
            fn () => $files->replace($providersPath, $this->originalProviders),
            fn () => parent::tearDown(),
        );
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TelescopeServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('telescope.enabled', false);
    }

    public function testPreUninstallRemovesOnlyTelescopeProvider(): void
    {
        (new Filesystem)->replace(
            $this->app->getBootstrapProvidersPath(),
            <<<'PHP'
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\TelescopeServiceProvider::class,
];
PHP,
        );

        $this->app->make(Dispatcher::class)
            ->dispatch('composer_package.hypervel/telescope:pre_uninstall');

        $providers = require $this->app->getBootstrapProvidersPath();

        $this->assertSame(['App\Providers\AppServiceProvider'], $providers);
    }
}
