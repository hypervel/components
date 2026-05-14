<?php

declare(strict_types=1);

namespace Hypervel\Console;

trait Prohibitable
{
    /**
     * Indicates if the command should be prohibited from running.
     */
    protected static bool $prohibitedFromRunning = false;

    /**
     * Indicate whether the command should be prohibited from running.
     *
     * Boot-only. The prohibition flag persists in a static property for the
     * worker lifetime and affects every subsequent execution of this command.
     */
    public static function prohibit(bool $prohibit = true): void
    {
        static::$prohibitedFromRunning = $prohibit;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$prohibitedFromRunning = false;
    }

    /**
     * Determine if the command is prohibited from running and display a warning if so.
     */
    protected function isProhibited(bool $quiet = false): bool
    {
        if (! static::$prohibitedFromRunning) {
            return false;
        }

        if (! $quiet) {
            $this->components->warn('This command is prohibited from running in this environment.');
        }

        return true;
    }
}
