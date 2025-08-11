<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class WorkerCommandString
{
    /**
     * The base worker command.
     *
     * @var string
     */
    public static $command = 'exec @php artisan horizon:work';

    /**
     * Get the command-line representation of the options for a worker.
     */
    public static function fromOptions(SupervisorOptions $options): string
    {
        $command = str_replace('@php', PhpBinary::path(), static::$command);

        return sprintf(
            "%s {$options->connection} %s",
            $command,
            static::toOptionsString($options)
        );
    }

    /**
     * Get the additional option string for the command.
     */
    public static function toOptionsString(SupervisorOptions $options): string
    {
        return QueueCommandString::toWorkerOptionsString($options);
    }

    /**
     * Reset the base command back to its default value.
     */
    public static function reset(): void
    {
        static::$command = 'exec @php artisan horizon:work';
    }
}
