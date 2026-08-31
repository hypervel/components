<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Telescope;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\CommandWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

#[WithConfig('telescope.watchers', [
    CommandWatcher::class => true,
])]
class TelescopeNonCoroutineTest extends FeatureTestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testRecordingAndDeferredStorageConfigurationWorkOutsideACoroutine(): void
    {
        config()->set('telescope.defer', true);

        Telescope::recordLog(IncomingEntry::make(['message' => 'non-coroutine']));
        Telescope::store($this->app->make(EntriesRepository::class));

        $this->assertSame('non-coroutine', EntryModel::firstOrFail()->content['message']);
    }

    public function testNonCoroutineCommandStoresItsEntryAtTheTerminalEvent(): void
    {
        config()->set('telescope.defer', true);
        Telescope::stopRecording();
        $kernel = $this->app->make(KernelContract::class);
        $kernel->registerCommand($this->app->make(NonCoroutineTelescopeCommand::class));

        $exitCode = $kernel->call('telescope:non-coroutine');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFalse(Telescope::isRecording());
        $this->assertSame([], Telescope::getEntriesQueue());
        $this->assertSame('telescope:non-coroutine', EntryModel::firstOrFail()->content['command']);
    }
}

class NonCoroutineTelescopeCommand extends Command
{
    protected ?string $signature = 'telescope:non-coroutine';

    protected bool $coroutine = false;

    public function handle(): void
    {
    }
}
