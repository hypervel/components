<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Prohibitable;
use Hypervel\Foundation\DevCommand as RegisteredDevCommand;
use Hypervel\Foundation\DevCommands;
use Hypervel\Prompts\Prompt;
use Hypervel\Support\NodePackageManager;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'dev')]
class DevCommand extends Command
{
    use Prohibitable;

    /**
     * The console command name.
     */
    protected ?string $name = 'dev';

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

        $commands = array_column($devCommands, 'command');
        $colors = array_column($devCommands, 'color');
        $names = array_column($devCommands, 'name');

        $longestName = max(array_map(strlen(...), $names));

        $columns = getenv('COLUMNS');

        putenv('COLUMNS=' . max(Prompt::terminal()->cols() - $longestName - 4, 1));

        $this->line('');

        foreach ($devCommands as $devCommand) {
            $this->line(
                sprintf(
                    '<fg=%s>[%s]</>%s%s',
                    $devCommand['color'],
                    $devCommand['name'],
                    str_repeat(' ', ($longestName - strlen($devCommand['name'])) + 1),
                    $devCommand['command'],
                ),
            );
        }

        $this->line('');

        $command = $packageManager->getExecCommand(sprintf(
            'concurrently -c %s %s --names=%s --kill-others-on-fail',
            escapeshellarg(implode(',', $colors)),
            implode(' ', array_map(escapeshellarg(...), $commands)),
            escapeshellarg(implode(',', $names)),
        ));

        if (extension_loaded('pcntl')) {
            pcntl_exec('/usr/bin/env', ['sh', '-c', $command]);
        }

        passthru($command, $exitCode);

        $columns === false ? putenv('COLUMNS') : putenv("COLUMNS={$columns}");

        return $exitCode;
    }
}
