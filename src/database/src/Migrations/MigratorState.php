<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hypervel\Console\OutputStyle;
use Hypervel\Context\ReplicableContext;

class MigratorState implements ReplicableContext
{
    public ?string $connection = null;

    public ?OutputStyle $output = null;

    /**
     * Inherit the connection and output without sharing subsequent assignments.
     */
    public function replicate(): static
    {
        return clone $this;
    }
}
