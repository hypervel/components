<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console\ReloadCommandTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

class ReloadCommandTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [ServiceProviderWithReload::class];
    }

    public function testCanRunReloadWithPackageRegisteredCommand(): void
    {
        $this->artisan('reload')
            ->assertSuccessful()
            ->expectsOutputToContain('my service');
    }

    public function testCanExcludeCommandsByKey(): void
    {
        $this->artisan('reload', ['--except' => 'my service'])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('my service');
    }

    public function testCanExcludeCommandsByCommand(): void
    {
        $this->artisan('reload', ['--except' => 'my_service:reload'])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('my service');
    }

    public function testIncludesDefaultTasks(): void
    {
        $this->artisan('reload')
            ->assertSuccessful()
            ->expectsOutputToContain('queue')
            ->expectsOutputToContain('schedule')
            ->expectsOutputToContain('server');
    }
}

class ServiceProviderWithReload extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            new ClosureCommand('my_service:reload', fn () => 0),
        ]);

        $this->reloads(
            reload: 'my_service:reload',
            key: 'my service',
        );
    }
}
