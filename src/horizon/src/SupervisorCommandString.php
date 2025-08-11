<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class SupervisorCommandString
{
    /**
     * The base worker command.
     */
    public static string $command = 'exec @php artisan horizon:supervisor';

    /**
     * Get the command-line representation of the options for a supervisor.
     */
    public static function fromOptions(SupervisorOptions $options): string
    {
        $command = str_replace('@php', PhpBinary::path(), static::$command);

        return sprintf(
            "%s {$options->name} {$options->connection} %s",
            $command,
            static::toOptionsString($options)
        );
    }

    /**
     * Get the additional option string for the command.
     */
    public static function toOptionsString(SupervisorOptions $options): string
    {
        return QueueCommandString::toSupervisorOptionsString($options);
    }

    /**
     * Reset the base command back to its default value.
     */
    public static function reset(): void
    {
        static::$command = 'exec @php artisan horizon:supervisor';
    }
}
