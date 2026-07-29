<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class SupervisorCommandString
{
    /**
     * The default base supervisor command.
     */
    protected const DEFAULT_COMMAND = 'exec @php artisan horizon:supervisor';

    /**
     * The base worker command.
     */
    public static string $command = self::DEFAULT_COMMAND;

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
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$command = self::DEFAULT_COMMAND;
    }
}
