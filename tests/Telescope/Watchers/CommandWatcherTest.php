<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\CommandWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    CommandWatcher::class => true,
])]
class CommandWatcherTest extends FeatureTestCase
{
    public function testCommandWatcherRegisterEntry()
    {
        $this->app->make(KernelContract::class)
            ->registerCommand($this->app->make(MyCommand::class));

        $this->app->make(KernelContract::class)
            ->call('telescope:test-command');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::COMMAND, $entry->type);
        $this->assertSame('telescope:test-command', $entry->content['command']);
        $this->assertSame(0, $entry->content['exit_code']);
    }
}

class MyCommand extends Command
{
    protected ?string $signature = 'telescope:test-command';

    public function handle()
    {
    }
}
