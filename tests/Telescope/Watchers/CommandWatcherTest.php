<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Support\Json;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\CommandWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[WithConfig('telescope.watchers', [
    CommandWatcher::class => true,
])]
class CommandWatcherTest extends FeatureTestCase
{
    public function testCommandWatcherRegisterEntry(): void
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

    public function testPackageDiscoveryIsIgnoredWhileRecordingIsActive(): void
    {
        $this->app->make(KernelContract::class)
            ->registerCommand($this->app->make(PackageDiscoverCommand::class));

        $this->assertTrue(Telescope::isRecording());

        $this->app->make(KernelContract::class)
            ->call('package:discover');

        $this->assertCount(0, $this->loadTelescopeEntries());
    }

    public function testCommandWatcherRedactsValueBearingInput(): void
    {
        $this->app->make(KernelContract::class)
            ->registerCommand($this->app->make(SensitiveInputCommand::class));

        $this->app->make(KernelContract::class)->call('telescope:sensitive-input', [
            'secret' => 'positional-secret',
            'values' => ['first-array-secret', 'second-array-secret'],
            '--innocuous' => 'custom-option-secret',
            '--tags' => ['first-tag-secret', 'second-tag-secret'],
            '--force' => true,
            '--no-feature' => true,
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('telescope:sensitive-input', $entry->content['command']);
        $this->assertSame(0, $entry->content['exit_code']);
        $this->assertSame(Telescope::REDACTED_VALUE, $entry->content['arguments']['secret']);
        $this->assertSame(Telescope::REDACTED_VALUE, $entry->content['arguments']['default-secret']);
        $this->assertSame(Telescope::REDACTED_VALUE, $entry->content['arguments']['values']);
        $this->assertSame(Telescope::REDACTED_VALUE, $entry->content['options']['innocuous']);
        $this->assertSame(Telescope::REDACTED_VALUE, $entry->content['options']['default-credential']);
        $this->assertSame(Telescope::REDACTED_VALUE, $entry->content['options']['tags']);
        $this->assertNull($entry->content['options']['nullable']);
        $this->assertTrue($entry->content['options']['force']);
        $this->assertFalse($entry->content['options']['feature']);

        $content = Json::encode($entry->content);

        foreach ([
            'positional-secret',
            'default-argument-secret',
            'first-array-secret',
            'second-array-secret',
            'custom-option-secret',
            'default-option-secret',
            'first-tag-secret',
            'second-tag-secret',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function testCommandWatcherFailsClosedForMissingAndUnknownInput(): void
    {
        $command = new SensitiveInputCommand;
        $watcher = $this->app->make(CommandWatcher::class);

        $watcher->recordCommand(new AfterExecute($command));

        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getArguments')->once()->andReturn([]);
        $input->shouldReceive('getOptions')->once()->andReturn(['unknown' => 'unknown-secret']);

        $watcher->recordCommand(new AfterExecute($command, input: $input));

        $entries = Telescope::getEntriesQueue();

        $this->assertCount(2, $entries);
        $this->assertSame([], $entries[0]->content['arguments']);
        $this->assertSame([], $entries[0]->content['options']);
        $this->assertSame(Telescope::REDACTED_VALUE, $entries[1]->content['options']['unknown']);
    }

    public function testNestedCommandsStoreOnceAfterTheOuterCommandEntryIsRecorded(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->registerCommand($this->app->make(NestedCommand::class));
        $kernel->registerCommand($this->app->make(OuterCommand::class));
        $storedBatches = [];

        Telescope::afterStoring(function (array $entries) use (&$storedBatches): void {
            $storedBatches[] = array_map(
                fn ($entry) => Json::decode($entry->content)['command'],
                $entries,
            );
        });

        $kernel->call('telescope:outer-command');

        $this->assertSame([[
            'telescope:nested-command',
            'telescope:outer-command',
        ]], $storedBatches);
        $this->assertFalse(Telescope::isRecording());
        $this->assertSame([], Telescope::getEntriesQueue());
        $this->assertSame([
            'telescope:nested-command',
            'telescope:outer-command',
        ], $this->loadTelescopeEntries()->pluck('content.command')->all());
    }
}

class MyCommand extends Command
{
    protected ?string $signature = 'telescope:test-command';

    public function handle(): void
    {
    }
}

class PackageDiscoverCommand extends Command
{
    protected ?string $signature = 'package:discover';

    public function handle(): void
    {
    }
}

class SensitiveInputCommand extends Command
{
    protected ?string $name = 'telescope:sensitive-input';

    public function handle(): void
    {
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('secret', InputArgument::REQUIRED),
            new InputArgument('default-secret', InputArgument::OPTIONAL, default: 'default-argument-secret'),
            new InputArgument('values', InputArgument::IS_ARRAY),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('innocuous', null, InputOption::VALUE_REQUIRED),
            new InputOption('default-credential', null, InputOption::VALUE_OPTIONAL, default: 'default-option-secret'),
            new InputOption('tags', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('nullable', null, InputOption::VALUE_OPTIONAL),
            new InputOption('force'),
            new InputOption('feature', null, InputOption::VALUE_NEGATABLE),
        ];
    }
}

class NestedCommand extends Command
{
    protected ?string $signature = 'telescope:nested-command';

    public function handle(): void
    {
    }
}

class OuterCommand extends Command
{
    protected ?string $signature = 'telescope:outer-command';

    public function handle(): void
    {
        $this->call('telescope:nested-command');
    }
}
