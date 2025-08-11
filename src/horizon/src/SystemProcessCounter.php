<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Symfony\Component\Process\Process;

class SystemProcessCounter
{
    /**
     * The base command to search for.
     *
     * @var string
     */
    public static $command = 'horizon:work';

    /**
     * Get the number of Horizon workers for a given supervisor.
     */
    public function get(string $name): int
    {
        $process = Process::fromShellCommandline('exec ps aux | grep ' . static::$command, null, ['COLUMNS' => '2000']);

        $process->run();

        return substr_count($process->getOutput(), 'supervisor=' . $name);
    }
}
