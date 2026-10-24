<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application;

/**
 * Prevent Symfony Terminal from repeatedly spawning `stty` when headless.
 *
 * Symfony does not cache failed dimension probes, so each lookup otherwise
 * enters Swoole's hooked subprocess wait, which can hang even after stty exits.
 *
 * @see https://github.com/swoole/swoole-src/issues/6328
 */
class ConfigureTerminalDimensions
{
    /**
     * The environment variables populated with framework defaults.
     *
     * @var array<string, true>
     */
    protected static array $configuredVariables = [];

    /**
     * Configure deterministic dimensions for a headless terminal.
     */
    public function bootstrap(Application $app): void
    {
        static::configure();
    }

    /**
     * Configure deterministic dimensions without requiring an application.
     *
     * Defaults are written to the process environment and apply to every
     * subsequent terminal lookup in the worker.
     */
    public static function configure(): void
    {
        if (defined('STDOUT') && stream_isatty(STDOUT)) {
            return;
        }

        if (getenv('COLUMNS') === false) {
            putenv('COLUMNS=80');
            static::$configuredVariables['COLUMNS'] = true;
        }

        if (getenv('LINES') === false) {
            putenv('LINES=24');
            static::$configuredVariables['LINES'] = true;
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        // Remove only defaults we supplied; explicit shell and XML dimensions must survive.
        foreach (array_keys(static::$configuredVariables) as $name) {
            putenv($name);
        }

        static::$configuredVariables = [];
    }
}
