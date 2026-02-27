<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Events\CommandFinished;
use Hypervel\Console\Events\CommandStarting;
use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Foundation\Testing\Concerns\WithConsoleEvents;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class CommandEventsTest extends TestCase
{
    use WithConsoleEvents;

    protected array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = [];
    }

    #[DataProvider('foregroundCommandEventsProvider')]
    public function testCommandEventsReceiveParsedInput($callback)
    {
        $this->app[ConsoleKernel::class]->registerCommand(new CommandEventsTestCommand());

        $this->app[Dispatcher::class]->listen(function (CommandStarting $event) {
            $this->log[] = 'CommandStarting';
            $this->log[] = $event->input->getArgument('firstname');
            $this->log[] = $event->input->getArgument('lastname');
            $this->log[] = $event->input->getOption('occupation');
        });

        Event::listen(function (CommandFinished $event) {
            $this->log[] = 'CommandFinished';
            $this->log[] = $event->input->getArgument('firstname');
            $this->log[] = $event->input->getArgument('lastname');
            $this->log[] = $event->input->getOption('occupation');
        });

        value($callback, $this);

        $this->assertSame([
            'CommandStarting', 'taylor', 'otwell', 'coding',
            'CommandFinished', 'taylor', 'otwell', 'coding',
        ], $this->log);
    }

    public static function foregroundCommandEventsProvider()
    {
        yield 'Foreground with array' => [function ($testCase) {
            $testCase->artisan(CommandEventsTestCommand::class, [
                'firstname' => 'taylor',
                'lastname' => 'otwell',
                '--occupation' => 'coding',
            ]);
        }];

        yield 'Foreground with string' => [function ($testCase) {
            $testCase->artisan('command-events-test-command taylor otwell --occupation=coding');
        }];
    }

    public function testCommandEventsReceiveParsedInputViaKernelCall()
    {
        $this->app[Dispatcher::class]->listen(function (CommandStarting $event) {
            $this->log[] = 'CommandStarting';
            $this->log[] = $event->input->getArgument('firstname');
            $this->log[] = $event->input->getArgument('lastname');
            $this->log[] = $event->input->getOption('occupation');
        });

        $this->app[Dispatcher::class]->listen(function (CommandFinished $event) {
            $this->log[] = 'CommandFinished';
            $this->log[] = $event->input->getArgument('firstname');
            $this->log[] = $event->input->getArgument('lastname');
            $this->log[] = $event->input->getOption('occupation');
        });

        $kernel = $this->app[ConsoleKernel::class];
        $kernel->registerCommand(new CommandEventsTestCommand());

        $kernel->call(CommandEventsTestCommand::class, [
            'firstname' => 'taylor',
            'lastname' => 'otwell',
            '--occupation' => 'coding',
        ]);

        $this->assertSame([
            'CommandStarting', 'taylor', 'otwell', 'coding',
            'CommandFinished', 'taylor', 'otwell', 'coding',
        ], $this->log);
    }
}

class CommandEventsTestCommand extends Command
{
    protected ?string $signature = 'command-events-test-command {firstname} {lastname} {--occupation=cook}';

    public function handle()
    {
        // ...
    }
}
