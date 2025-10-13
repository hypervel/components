<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class Exec
{
    /**
     * Run the given command.
     */
    public function run(string $command): array
    {
        exec($command, $output);

        return $output;
    }
}
