<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\CallCommandsTest;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

class CallCommandsTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        Artisan::command('test:caller-by-name', function (): void {
            $this->call('test:callee');
        });

        Artisan::command('test:caller-by-class', function (): void {
            $this->call(CalleeCommand::class);
        });

        Artisan::command('test:caller-by-class-twice', function (): void {
            $this->call(CalleeCommand::class);
            $this->call(CalleeCommand::class);
        });

        Artisan::command('test:caller-by-instance', function (): void {
            $this->call($this->getHypervel()->make(CalleeCommand::class));
        });

        $app->make(Kernel::class)
            ->registerCommand($app->make(CalleeCommand::class));
    }

    public function testItCanCallCommandByName(): void
    {
        $this->artisan('test:caller-by-name')->assertSuccessful();
    }

    public function testItCanCallCommandByClass(): void
    {
        $this->artisan('test:caller-by-class')->assertSuccessful();
    }

    public function testClassCommandCallsUseFreshInstances(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('test:caller-by-class-twice'));

        $output = Artisan::output();
        $this->assertSame(2, substr_count($output, 'child run count: 1'));
        $this->assertStringNotContainsString('child run count: 2', $output);
    }

    public function testItCanCallCommandByInstance(): void
    {
        $this->artisan('test:caller-by-instance')->assertSuccessful();
    }
}

#[AsCommand(name: 'test:callee')]
class CalleeCommand extends Command
{
    protected ?string $signature = 'test:callee';

    protected string $description = 'A test callee command';

    private int $runs = 0;

    public function handle(): int
    {
        ++$this->runs;
        $this->line("child run count: {$this->runs}");

        return self::SUCCESS;
    }
}
