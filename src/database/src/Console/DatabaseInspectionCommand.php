<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Arr;

abstract class DatabaseInspectionCommand extends Command
{
    // Laravel's deprecated connection-name and connection-count forwarding
    // helpers are intentionally not ported. Use the connection APIs directly.

    /**
     * Get the connection configuration details for the given connection.
     */
    protected function getConfigFromDatabase(?string $database): array
    {
        $database ??= config('database.default');

        return Arr::except(config('database.connections.' . $database), ['password']);
    }
}
