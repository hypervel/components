<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application;

/**
 * Prevent Symfony Terminal from repeatedly spawning `stty` when headless.
 *
 * Its fallback dimensions are not cached, so every lookup otherwise enters
 * Swoole's hooked subprocess-wait path.
 */
class ConfigureTerminalDimensions
{
    /**
     * Configure deterministic dimensions for a headless terminal.
     */
    public function bootstrap(Application $app): void
    {
        if (defined('STDOUT') && stream_isatty(STDOUT)) {
            return;
        }

        if (getenv('COLUMNS') === false) {
            putenv('COLUMNS=80');
        }

        if (getenv('LINES') === false) {
            putenv('LINES=24');
        }
    }
}
