<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 * @coversNothing
 */
class CallCommandsTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        Artisan::command('test:caller-by-name', function () {
            $this->call('test:callee');
        });

        Artisan::command('test:caller-by-class', function () {
            $this->call(CallCommandsTestCalleeCommand::class);
        });

        Artisan::command('test:caller-by-instance', function () {
            $this->call($this->app->make(CallCommandsTestCalleeCommand::class));
        });

        $app->make(\Hypervel\Contracts\Console\Kernel::class)
            ->registerCommand($app->make(CallCommandsTestCalleeCommand::class));
    }

    public function testItCanCallCommandByName()
    {
        $this->artisan('test:caller-by-name')->assertSuccessful();
    }

    public function testItCanCallCommandByClass()
    {
        $this->artisan('test:caller-by-class')->assertSuccessful();
    }

    public function testItCanCallCommandByInstance()
    {
        $this->artisan('test:caller-by-instance')->assertSuccessful();
    }
}

#[AsCommand(name: 'test:callee')]
class CallCommandsTestCalleeCommand extends Command
{
    protected ?string $signature = 'test:callee';

    protected string $description = 'A test callee command';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
