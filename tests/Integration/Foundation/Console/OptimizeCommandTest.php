<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console\OptimizeCommandTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class OptimizeCommandTest extends TestCase
{
    use InteractsWithPublishedFiles;

    protected array $files = [
        'bootstrap/cache/config.php',
        'bootstrap/cache/events.php',
        'bootstrap/cache/routes-v7.php',
    ];

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [ServiceProviderWithOptimize::class];
    }

    public function testCanRunOptimizeWithPackageRegisteredCommand()
    {
        $this->artisan('optimize')
            ->assertSuccessful()
            ->expectsOutputToContain('my package');
    }

    public function testCanExcludeCommandsByKey()
    {
        $this->artisan('optimize', ['--except' => 'my package'])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('my package');
    }

    public function testCanExcludeCommandsByCommand()
    {
        $this->artisan('optimize', ['--except' => 'my_package:cache'])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('my_package:cache');
    }
}

class ServiceProviderWithOptimize extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            new ClosureCommand('my_package:cache', fn () => 0),
        ]);

        $this->optimizes(
            optimize: 'my_package:cache',
            key: 'my package',
        );
    }
}
