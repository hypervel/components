<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Process;

use Closure;
use Hypervel\Support\Arr;
use Hypervel\Support\ProcessUtils;
use Laravel\SerializableClosure\SerializableClosure;
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
        $definedEnvironmentVariables = defined_environment_variables();
        $commandName = $this->resolveCommandName($command);

        $env['TESTBENCH_PACKAGE_REMOTE'] = '(true)';

        if (defined('BASE_PATH') && $commandName !== 'serve') {
            $env['TESTBENCH_BASE_PATH'] ??= BASE_PATH;
        }

        if (! array_key_exists('APP_ENV', $env)) {
            unset($definedEnvironmentVariables['APP_ENV']);
        }

        if ($command instanceof Closure) {
            $env['HYPERVEL_INVOKABLE_CLOSURE'] = base64_encode(
                serialize(new SerializableClosure($command))
            );
            $env['APP_KEY'] ??= config('app.key') ?? false;
            $commands = ['invoke-serialized-closure'];
        } else {
            $commands = Arr::wrap($command);
        }

        $process = Process::fromShellCommandline(
            command: Arr::join([
                ProcessUtils::escapeArgument(php_binary()),
                ProcessUtils::escapeArgument($commander),
                ...$commands,
            ], ' '),
            cwd: $this->workingPath,
            env: array_merge($definedEnvironmentVariables, $env)
        );

        if (is_bool($this->tty)) {
            $process->setTty($this->tty);
        }

        return new ProcessDecorator($process, $command);
    }

    /**
     * Resolve the top-level command name for the given invocation.
     */
    private function resolveCommandName(Closure|array|string $command): ?string
    {
        if ($command instanceof Closure) {
            return null;
        }

        if (is_array($command)) {
            return $command[0] ?? null;
        }

        $commandName = strtok(trim($command), " \t\n\r\0\x0B");

        return $commandName === false ? null : $commandName;
    }
}
