<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Symfony\Component\Process\Process;

class SystemProcessCounter
{
    /**
     * The default base command.
     */
    protected const DEFAULT_COMMAND = 'horizon:work';

    /**
     * The base command to search for.
     */
    public static string $command = self::DEFAULT_COMMAND;

    /**
     * Get the number of Horizon workers for a given supervisor.
     */
    public function get(string $name): int
    {
        $process = Process::fromShellCommandline('exec ps aux | grep ' . static::$command, null, ['COLUMNS' => '2000']);

        $process->run();

        return substr_count($process->getOutput(), 'supervisor=' . $name);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$command = self::DEFAULT_COMMAND;
    }
}
