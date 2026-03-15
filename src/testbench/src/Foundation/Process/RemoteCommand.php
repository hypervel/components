<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Process;

use Closure;
use Hypervel\Support\Arr;
use Hypervel\Support\ProcessUtils;
use RuntimeException;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\defined_environment_variables;

/**
 * Executes a command in a subprocess using the testbench CLI.
 *
 * @internal
 */
final class RemoteCommand
{
    /**
     * Create a new remote command instance.
     *
     * @param string $workingPath The working directory for the command
     * @param array<string, mixed>|string $env Environment variables or APP_ENV value
     * @param null|bool $tty Whether to enable TTY mode
     */
    public function __construct(
        public string $workingPath,
        public array|string $env = [],
        public ?bool $tty = null,
    ) {
    }

    /**
     * Execute the command.
     *
     * @param string $commander The testbench binary path
     * @param array<int, string>|Closure|string $command The command(s) to run
     */
    public function handle(string $commander, Closure|array|string $command): ProcessDecorator
    {
        $env = is_string($this->env) ? ['APP_ENV' => $this->env] : $this->env;

        $env['TESTBENCH_PACKAGE_REMOTE'] = '(true)';

        // Closure commands require SerializableClosure - not implemented yet
        if ($command instanceof Closure) {
            throw new RuntimeException(
                'Closure commands are not yet supported by remote(). Use string commands instead.'
            );
        }

        $commands = Arr::wrap($command);

        $process = Process::fromShellCommandline(
            command: Arr::join([
                ProcessUtils::escapeArgument(php_binary()),
                ProcessUtils::escapeArgument($commander),
                ...$commands,
            ], ' '),
            cwd: $this->workingPath,
            env: array_merge(defined_environment_variables(), $env)
        );

        if (is_bool($this->tty)) {
            $process->setTty($this->tty);
        }

        return new ProcessDecorator($process, $command);
    }
}
