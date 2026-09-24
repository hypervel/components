<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Prohibitable;
use Hypervel\Foundation\DevCommand as RegisteredDevCommand;
use Hypervel\Foundation\DevCommandMode;
use Hypervel\Foundation\DevCommands;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\NodePackageManager;
use Hypervel\Support\NodePackageManagers\Pnpm;
use Hypervel\Support\NodePackageManagers\Yarn;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @phpstan-import-type DevCommandArray from DevCommands
 */
#[AsCommand(name: 'dev')]
class DevCommand extends Command
{
    use Prohibitable;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'dev
        {--s|stream : Start in stream mode}
        {--t|tabs : Start in tabs mode}
        {--i|inline : Print output inline instead of rendering the TUI (the default when not a TTY)}
        {--timestamps : Display timestamps on each output line}
        {--no-restart : Disable auto-restart on crash}
        {--json : Emit newline-delimited JSON events. Implies --inline}
        {--buffer-size= : Set the max lines per command buffer}
        {--stream-buffer-size= : Set the max lines in the stream buffer}';

    /**
     * The console command description.
     */
    protected string $description = 'Run the dev processes';

    /**
     * Whether to execute in a coroutine environment.
     *
     * Native execution lets pcntl_exec replace the console process that owns
     * the long-running development subprocesses.
     */
    protected bool $coroutine = false;

    /**
     * Execute the console command.
     */
    public function handle(NodePackageManager $packageManager): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        $devCommands = DevCommands::commands();

        if ($devCommands === []) {
            $this->components->error('No development commands are configured to run.');

            return self::FAILURE;
        }

        if (array_any(
            $devCommands,
            fn (array $command): bool => $command['name'] === 'server'
                && $command['priority'] === RegisteredDevCommand::PRIORITY_DEFAULT,
        ) && ! $this->getApplication()->has('watch')) {
            $this->components->error(
                'The default [server] process requires Hypervel Watcher. Install it with [composer require --dev hypervel/watcher].'
            );

            return self::FAILURE;
        }

        return $this->runViaMultiplex($devCommands, $packageManager);
    }

    /**
     * Run the dev commands via `@laravel/multiplex`.
     *
     * @param list<DevCommandArray> $devCommands
     */
    protected function runViaMultiplex(array $devCommands, NodePackageManager $packageManager): int
    {
        $multiplexCommand = $this->buildMultiplexCommand($devCommands);
        $manager = $packageManager->packageManager();

        // These managers execute installed binaries rather than npm package names.
        if ($manager instanceof Pnpm || $manager instanceof Yarn) {
            $multiplexCommand = Str::replaceStart('@laravel/multiplex ', 'multiplex ', $multiplexCommand);
        }

        $command = $packageManager->getExecCommand($multiplexCommand);

        if (extension_loaded('pcntl')) {
            pcntl_exec('/usr/bin/env', ['sh', '-c', $command]);
        }

        passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Build the command to run `@laravel/multiplex` with the given dev commands.
     *
     * @param list<DevCommandArray> $devCommands
     */
    protected function buildMultiplexCommand(array $devCommands): string
    {
        $args = collect($devCommands)
            ->map(fn (array $devCommand): string => implode(',', [
                $devCommand['name'] . '@' . $devCommand['color'],
                $devCommand['command'],
            ]));

        $mode = match (true) {
            $this->option('tabs') => DevCommandMode::TABS,
            $this->option('stream') => DevCommandMode::STREAM,
            $this->option('inline') => DevCommandMode::INLINE,
            default => DevCommands::mode(),
        };

        $flags = collect([
            'stream' => $mode === DevCommandMode::STREAM,
            'inline' => $mode === DevCommandMode::INLINE,
            'timestamps' => $this->option('timestamps') || DevCommands::shouldIncludeTimestamps(),
            'no-restart' => $this->option('no-restart') || ! DevCommands::shouldAutoRestart(),
            'json' => $this->option('json'),
        ])
            ->filter()
            ->keys()
            ->map(fn (string $flag): string => "--{$flag}");

        if (($bufferSize = $this->option('buffer-size') ?? DevCommands::getBufferSize()) !== null) {
            $flags->push('--buffer-size=' . escapeshellarg((string) $bufferSize));
        }

        if (($streamBufferSize = $this->option('stream-buffer-size') ?? DevCommands::getStreamBufferSize()) !== null) {
            $flags->push('--stream-buffer-size=' . escapeshellarg((string) $streamBufferSize));
        }

        $title = 'artisan dev · ' . (Config::string('app.name') === 'Hypervel' ? basename(base_path()) : Config::string('app.name'));

        $command = '@laravel/multiplex --title ' . escapeshellarg($title);

        if (! $flags->isEmpty()) {
            $command .= ' ' . $flags->implode(' ');
        }

        $command .= ' ' . $args->map(escapeshellarg(...))->implode(' ');

        return $command;
    }

    // REMOVED: The concurrently fallback is Windows-only; Hypervel requires Swoole, pcntl, and POSIX.
}
