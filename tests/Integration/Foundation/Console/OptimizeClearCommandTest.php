<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console\OptimizeClearCommandTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class OptimizeClearCommandTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [ServiceProviderWithOptimizeClear::class];
    }

    public function testCanRunOptimizeClearWithPackageRegisteredCommand()
    {
        $this->artisan('optimize:clear')
            ->assertSuccessful()
            ->expectsOutputToContain('ServiceProviderWithOptimizeClear');
    }

    public function testCanExcludeCommandsByKey()
    {
        $this->artisan('optimize:clear', ['--except' => 'my package'])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('my package');
    }

    public function testCanExcludeCommandsByCommand()
    {
        $this->artisan('optimize:clear', ['--except' => 'my_package:clear'])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('my_package:clear');
    }
}

class ServiceProviderWithOptimizeClear extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            new ClosureCommand('my_package:clear', fn () => 0),
        ]);

        $this->optimizes(
            clear: 'my_package:clear',
        );
    }
}
